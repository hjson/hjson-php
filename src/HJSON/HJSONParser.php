<?php

namespace HJSON;

class HJSONParser
{

    private $text;
    private $at;   // The index of the current character
    private $ch;   // The current character
    private $escapee = [];
    private $keepWsc; // keep whitespace

    public function __construct()
    {
        $this->escapee = [
            '"'  => '"',
            '\'' => '\'',
            "\\" => "\\",
            '/'  => '/',
            'b'  => chr(8),
            'f'  => chr(12),
            'n'  => "\n",
            'r'  => "\r",
            't'  => "\t"
        ];
    }

    public function parse($source, $options = [])
    {
        $this->keepWsc = $options && isset($options['keepWsc']) && $options['keepWsc'];
        $this->text = $source;
        $data = $this->rootValue();

        if ($options && isset($options['assoc']) && $options['assoc']) {
            $data = json_decode(json_encode($data), true);
        }

        return $data;
    }

    private function resetAt()
    {
        $this->at = 0;
        $this->ch = ' ';
    }

    public function parseWsc($source, $options = [])
    {
        return $this->parse($source, array_merge($options, ['keepWsc' => true]));
    }

    private function isPunctuatorChar($c)
    {
        return $c === '{' || $c === '}' || $c === '[' || $c === ']' || $c === ',' || $c === ':';
    }

    private function checkExit($result)
    {
        $this->white();
        if ($this->ch !== null) {
            $this->error("Syntax error, found trailing characters!");
        }
        return $result;
    }

    private function rootValue()
    {
        // Braces for the root object are optional

        $this->resetAt();
        $this->white();
        switch ($this->ch) {
            case '{':
                return $this->checkExit($this->object());
            case '[':
                return $this->checkExit($this->_array());
        }

        try {
          // assume we have a root object without braces
            return $this->checkExit($this->object(true));
        } catch (HJSONException $e) {
            // test if we are dealing with a single JSON value instead (true/false/null/num/"")
            $this->resetAt();
            try {
                return $this->checkExit($this->value());
            } catch (HJSONException $e2) {
                throw $e;
            } // throw original error
        }
    }

    private function value()
    {
        $this->white();
        switch ($this->ch) {
            case '{':
                return $this->object();
            case '[':
                return $this->_array();
            case '"':
                return $this->string('"');
            case '\'':
                if ($this->peek(0) !== '\'' || $this->peek(1) !== '\'') {
                    return $this->string('\'');
                }
                // Falls through on multiline strings
            default:
                return $this->tfnns();
        }
    }

    private function string($quote)
    {
        // Parse a string value.
        $hex;
        $string = '';
        $uffff;

        // When parsing for string values, we must look for " and \ characters.
        if ($this->ch === $quote) {
            while ($this->next() !== null) {
                if ($this->ch === $quote) {
                    $this->next();
                    return $string;
                }
                if ($this->ch === "\\") {
                    $this->next();
                    if ($this->ch === 'u') {
                        $uffff = '';
                        for ($i = 0; $i < 4; $i++) {
                            $uffff .= $this->next();
                        }
                        if (!ctype_xdigit($uffff)) {
                            $this->error("Bad \\u char");
                        }
                        $string .= mb_convert_encoding(pack('H*', $uffff), 'UTF-8', 'UTF-16BE');
                    } elseif (@$this->escapee[$this->ch]) {
                        $string .= $this->escapee[$this->ch];
                    } else {
                        break;
                    }
                } else {
                    $string .= $this->ch;
                }
            }
        }
        $this->error("Bad string");
    }

    private function _array()
    {
        // Parse an array value.
        // assumeing ch === '['

        $array = [];
        $kw = null;
        $wat = null;

        if ($this->keepWsc) {
            $array['__WSC__'] = [];
            $kw = &$array['__WSC__'];
        }

        $this->next();
        $wat = $this->at;
        $this->white();
        if ($kw !== null) {
            $c = $this->getComment($wat);
            if (trim($c)) {
                $kw[] = $c;
            }
        }

        if ($this->ch === ']') {
            $this->next();
            return $array;  // empty array
        }

        while ($this->ch !== null) {
            $array[] = $this->value();
            $wat = $this->at;
            $this->white();
            // in Hjson the comma is optional and trailing commas are allowed
            if ($this->ch === ',') {
                $this->next();
                $wat = $this->at;
                $this->white();
            }
            if ($kw !== null) {
                $c = $this->getComment($wat);
                if (trim($c)) {
                    $kw[] = $c;
                }
            }
            if ($this->ch === ']') {
                $this->next();
                return $array;
            }
            $this->white();
        }

        $this->error("End of input while parsing an array (did you forget a closing ']'?)");
    }

    private function object($withoutBraces = false)
    {
        // Parse an object value.
        $key = null;
        $object = new \stdClass;
        $kw = null;
        $wat = null;
        if ($this->keepWsc) {
            $kw = new \stdClass;
            $kw->c = new \stdClass;
            $kw->o = [];
            $object->__WSC__ = $kw;
            if ($withoutBraces) {
                $kw->noRootBraces = true;
            }
        }

        if (!$withoutBraces) {
            // assuming ch === '{'
            $this->next();
            $wat = $this->at;
        } else {
            $wat = 1;
        }

        $this->white();
        if ($kw) {
            $this->pushWhite(" ", $kw, $wat);
        }
        if ($this->ch === '}' && !$withoutBraces) {
            $this->next();
            return $object;  // empty object
        }
        while ($this->ch !== null) {
            $key = $this->keyname();
            $this->white();
            $this->next(':');
            // duplicate keys overwrite the previous value
            if ($key !== '') {
                $object->$key = $this->value();
            }
            $wat = $this->at;
            $this->white();
            // in Hjson the comma is optional and trailing commas are allowed
            if ($this->ch === ',') {
                $this->next();
                $wat = $this->at;
                $this->white();
            }
            if ($kw) {
                $this->pushWhite($key, $kw, $wat);
            }
            if ($this->ch === '}' && !$withoutBraces) {
                $this->next();
                return $object;
            }
            $this->white();
        }

        if ($withoutBraces) {
            return $object;
        } else {
            $this->error("End of input while parsing an object (did you forget a closing '}'?)");
        }
    }

    private function pushWhite($key, &$kw, $wat)
    {
        $kw->c->$key = $this->getComment($wat);
        if (trim($key)) {
            $kw->o[] = $key;
        }
    }

    private function white()
    {
        while ($this->ch !== null) {
            // Skip whitespace.
            while ($this->ch && $this->ch <= ' ') {
                $this->next();
            }
            // Hjson allows comments
            if ($this->ch === '#' || $this->ch === '/' && $this->peek(0) === '/') {
                while ($this->ch !== null && $this->ch !== "\n") {
                    $this->next();
                }
            } elseif ($this->ch === '/' && $this->peek(0) === '*') {
                $this->next();
                $this->next();
                while ($this->ch !== null && !($this->ch === '*' && $this->peek(0) === '/')) {
                    $this->next();
                }
                if ($this->ch !== null) {
                    $this->next();
                    $this->next();
                }
            } else {
                break;
            }
        }
    }

    private function error($m)
    {
        $col=0;
        $colBytes = 0;
        $line=1;

        $i = $this->at;
        while ($i > 0) {
            $ch = mb_substr(mb_strcut($this->text, $i - 1), 0, 1);
            $i -= strlen($ch);

            if ($ch === "\n") {
                break;
            }

            $col++;
            $colBytes += strlen($ch);
        }

        for (; $i > 0;
        $i--) {
            if ($this->text[$i] === "\n") {
                $line++;
            }
        }
        throw new HJSONException("$m at line $line, $col >>>". mb_substr(mb_strcut($this->text, $this->at - $colBytes), 0, 20) ." ...");
    }

    private function next($c = false)
    {
        // If a c parameter is provided, verify that it matches the current character.

        if ($c && $c !== $this->ch) {
            $this->error("Expected '$c' instead of '{$this->ch}'");
        }

        // Get the next character. When there are no more characters,
        // return the empty string.
        $this->ch = (strlen($this->text) > $this->at) ? mb_substr(mb_strcut($this->text, $this->at), 0, 1) : null;
        $this->at += strlen($this->ch);
        return $this->ch;
    }

    private function peek($offs)
    {
        // range check is not required
        if ($offs >= 0) {
            return mb_substr(mb_strcut($this->text, $this->at), $offs, 1);
        } else {
            return mb_substr(mb_strcut($this->text, 0, $this->at), $offs, 1);
        }
    }

    private function skipIndent($indent)
    {
        $skip = $indent;
        while ($this->ch && $this->ch <= ' ' && $this->ch !== "\n" && $skip-- > 0) {
            $this->next();
        }
    }

    private function mlString()
    {
        // Parse a multiline string value.
        $string = '';
        $triple = 0;

        // we are at ''' +1 - get indent
        $indent = 0;
        while (true) {
            $c = $this->peek(-$indent-5);
            if ($c === null || $c === "\n") {
                break;
            }
            $indent++;
        }

        // skip white/to (newline)
        while ($this->ch !== null && $this->ch <= ' ' && $this->ch !== "\n") {
            $this->next();
        }
        if ($this->ch === "\n") {
            $this->next();
            $this->skipIndent($indent);
        }

        // When parsing multiline string values, we must look for ' characters.
        while (true) {
            if ($this->ch === null) {
                $this->error("Bad multiline string");
            } elseif ($this->ch === '\'') {
                $triple++;
                $this->next();
                if ($triple === 3) {
                    if (substr($string, -1) === "\n") {
                        $string = mb_substr($string, 0, -1); // remove last EOL
                    }
                    return $string;
                } else {
                    continue;
                }
            } else {
                while ($triple > 0) {
                    $string .= '\'';
                    $triple--;
                }
            }
            if ($this->ch === "\n") {
                $string .= "\n";
                $this->next();
                $this->skipIndent($indent);
            } else {
                if ($this->ch !== "\r") {
                    $string .= $this->ch;
                }
                $this->next();
            }
        }
    }

    private function keyname()
    {
        // quotes for keys are optional in Hjson
        // unless they include {}[],: or whitespace.

        if ($this->ch === '"') {
            return $this->string('"');
        } else if ($this->ch === '\'') {
            return $this->string('\'');
        }

        $name = "";
        $start = $this->at;
        $space = -1;

        while (true) {
            if ($this->ch === ':') {
                if ($name === '') {
                    $this->error("Found ':' but no key name (for an empty key name use quotes)");
                } elseif ($space >=0 && $space !== mb_strlen($name)) {
                    $this->at = $start + $space;
                    $this->error("Found whitespace in your key name (use quotes to include)");
                }
                return $name;
            } elseif ($this->ch <= ' ') {
                if (!$this->ch) {
                    $this->error("Found EOF while looking for a key name (check your syntax)");
                } elseif ($space < 0) {
                    $space = mb_strlen($name);
                }
            } elseif ($this->isPunctuatorChar($this->ch)) {
                $this->error("Found '{$this->ch}' where a key name was expected (check your syntax or use quotes if the key name includes {}[],: or whitespace)");
            } else {
                $name .= $this->ch;
            }
            $this->next();
        }
    }

    private function tfnns()
    {
        // Hjson strings can be quoteless
        // returns string, true, false, or null.

        if ($this->isPunctuatorChar($this->ch)) {
            $this->error("Found a punctuator character '{$this->ch}' when expecting a quoteless string (check your syntax)");
        }

        $value = $this->ch;
        while (true) {
            $isEol = $this->next() === null;
            if (mb_strlen($value) === 3 && $value === "'''") {
                return $this->mlString();
            }
            $isEol = $isEol || $this->ch === "\r" || $this->ch === "\n";

            if ($isEol || $this->ch === ',' ||
                $this->ch === '}' || $this->ch === ']' ||
                $this->ch === '#' ||
                $this->ch === '/' && ($this->peek(0) === '/' || $this->peek(0) === '*')
            ) {
                $chf = $value[0];
                switch ($chf) {
                    case 'f':
                        if (trim($value) === "false") {
                            return false;
                        }
                        break;
                    case 'n':
                        if (trim($value) === "null") {
                            return null;
                        }
                        break;
                    case 't':
                        if (trim($value) === "true") {
                            return true;
                        }
                        break;
                    default:
                        if ($chf === '-' || $chf >= '0' && $chf <= '9') {
                            $n = HJSONUtils::tryParseNumber($value);
                            if ($n !== null) {
                                return $n;
                            }
                        }
                }
                if ($isEol) {
                    // remove any whitespace at the end (ignored in quoteless strings)
                    return trim($value);
                }
            }
            $value .= $this->ch;
        }
    }

    private function getComment($wat)
    {
        $i;
        $wat--;
        // remove trailing whitespace
        for ($i = $this->at - 2; $i > $wat && $this->text[$i] <= ' ' && $this->text[$i] !== "\n";
        $i--) {
        }

        // but only up to EOL
        if ($this->text[$i] === "\n") {
            $i--;
        }
        if ($this->text[$i] === "\r") {
            $i--;
        }

        $res = mb_substr($this->text, $wat, $i-$wat+1);
        for ($i = 0; $i < mb_strlen($res); $i++) {
            if ($res[$i] > ' ') {
                return $res;
            }
        }

        return "";
    }
}
