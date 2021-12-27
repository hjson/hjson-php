<?php

namespace HJSON;

/**
 * NOTE: this may return an empty string at the end of the array when the input
 * string ends with a newline character
 */
function mb_str_split($string)
{
    return preg_split('/(?<!^)/u', $string);
}

class HJSONStringifier
{

    // needsEscape tests if the string can be written without escapes
    private $needsEscape = '/[\\\"\x00-\x1f\x7f-\x9f\x{00ad}\x{0600}-\x{0604}\x{070f}\x{17b4}\x{17b5}\x{200c}-\x{200f}\x{2028}-\x{202f}\x{2060}-\x{206f}\x{feff}\x{fff0}-\x{ffff}\x]/u';
    // needsQuotes tests if the string can be written as a quoteless string (includes needsEscape but without \\ and \")
    private $needsQuotes = '/^\\s|^"|^\'|^\'\'\'|^#|^\\/\\*|^\\/\\/|^\\{|^\\}|^\\[|^\\]|^:|^,|\\s$|[\x00-\x1f\x7f-\x9f\x{00ad}\x{0600}-\x{0604}\x{070f}\x{17b4}\x{17b5}\x{200c}-\x{200f}\x{2028}-\x{202f}\x{2060}-\x{206f}\x{feff}\x{fff0}-\x{ffff}\x]/u';
    // needsEscapeML tests if the string can be written as a multiline string (includes needsEscape but without \n, \r, \\ and \")
    private $needsEscapeML = '/^\\s+$|\'\'\'|[\x00-\x08\x0b\x0c\x0e-\x1f\x7f-\x9f\x{00ad}\x{0600}-\x{0604}\x{070f}\x{17b4}\x{17b5}\x{200c}-\x{200f}\x{2028}-\x{202f}\x{2060}-\x{206f}\x{feff}\x{fff0}-\x{ffff}\x]/u';
    private $startsWithKeyword = '/^(true|false|null)\s*((,|\]|\}|#|\/\/|\/\*).*)?$/';
    private $needsEscapeName = '/[,\{\[\}\]\s:#"\']|\/\/|\/\*|\'\'\'/';
    private $gap = '';
    private $indent = '  ';

    // options
    private $eol;
    private $keepWsc;
    private $bracesSameLine;
    private $quoteAlways;
    private $forceKeyQuotes;
    private $emitRootBraces;

    private $defaultBracesSameLine = false;

    public function __construct()
    {
        $this->meta = [
            "\t" => "\\t",
            "\n" => "\\n",
            "\r" => "\\r",
            '"'  => '\\"',
            '\''  => '\\\'',
            '\\' => "\\\\"
        ];
        $this->meta[chr(8)] = '\\b';
        $this->meta[chr(12)] = '\\f';
    }


    public function stringify($value, $opt = [])
    {
        $this->eol = PHP_EOL;
        $this->indent = '  ';
        $this->keepWsc = false;
        $this->bracesSameLine = $this->defaultBracesSameLine;
        $this->quoteAlways = false;
        $this->forceKeyQuotes = false;
        $this->emitRootBraces = true;
        $space = null;

        if ($opt && is_array($opt)) {
            if (@$opt['eol'] === "\n" || @$opt['eol'] === "\r\n") {
                $this->eol = $opt['eol'];
            }
            $space = @$opt['space'];
            $this->keepWsc = @$opt['keepWsc'];
            $this->bracesSameLine = @$opt['bracesSameLine'] || $this->defaultBracesSameLine;
            $this->emitRootBraces = @$opt['emitRootBraces'];
            $this->quoteAlways = @$opt['quotes'] === 'always';
            $this->forceKeyQuotes = @$opt['keyQuotes'] === 'always';
        }

        // If the space parameter is a number, make an indent string containing that
        // many spaces. If it is a string, it will be used as the indent string.
        if (is_int($space)) {
            $this->indent = '';
            for ($i = 0; $i < $space; $i++) {
                $this->indent .= ' ';
            }
        } elseif (is_string($space)) {
            $this->indent = $space;
        }

        // Return the result of stringifying the value.
        return $this->str($value, null, true, true);
    }

    public function stringifyWsc($value, $opt = [])
    {
        return $this->stringify($value, array_merge($opt, ['keepWsc' => true]));
    }

    private function isWhite($c)
    {
        return $c <= ' ';
    }

    private function quoteReplace($string)
    {
        mb_ereg_search_init($string, $this->needsEscape);
        $r = mb_ereg_search();
        $chars = mb_str_split($string);
        $chars = array_map(function ($char) {
            if (preg_match($this->needsEscape, $char)) {
                $a = $char;
                $c = @$this->meta[$a] ?: null;
                if (gettype($c) === 'string') {
                    return $c;
                } else {
                    return $char;
                }
            } else {
                return $char;
            }
        }, $chars);

        return implode('', $chars);
    }

    private function quote($string = null, $gap = null, $hasComment = null, $isRootObject = null)
    {
        if ($string === '' || $string === null) {
            return '""';
        }

        // Check if we can insert this string without quotes
        // see hjson syntax (must not parse as true, false, null or number)
        if ($this->quoteAlways || $hasComment ||
            preg_match($this->needsQuotes, $string) ||
            HJSONUtils::tryParseNumber($string, true) !== null ||
            preg_match($this->startsWithKeyword, $string)) {
            // If the string contains no control characters, no quote characters, and no
            // backslash characters, then we can safely slap some quotes around it.
            // Otherwise we first check if the string can be expressed in multiline
            // format or we must replace the offending characters with safe escape
            // sequences.

            if (!preg_match($this->needsEscape, $string)) {
                return '"' . $string . '"';
            } elseif (!preg_match($this->needsEscapeML, $string) && !$isRootObject) {
                return $this->mlString($string, $gap);
            } else {
                return '"' . $this->quoteReplace($string) . '"';
            }
        } else {
            // return without quotes
            return $string;
        }
    }

    private function mlString($string, $gap)
    {
        // wrap the string into the ''' (multiline) format

        $a = explode("\n", mb_ereg_replace("\r", "", $string));
        $gap .= $this->indent;

        if (count($a) === 1) {
            // The string contains only a single line. We still use the multiline
            // format as it avoids escaping the \ character (e.g. when used in a
            // regex).
            return "'''" . $a[0] . "'''";
        } else {
            $res = $this->eol . $gap . "'''";
            for ($i = 0; $i < count($a); $i++) {
                $res .= $this->eol;
                if ($a[$i]) {
                    $res .= $gap . $a[$i];
                }
            }
            return $res . $this->eol . $gap . "'''";
        }
    }

    private function quoteName($name)
    {
        if (!$name) {
            return '""';
        }

        // Check if we can insert this name without quotes
        if (preg_match($this->needsEscapeName, $name)) {
            return '"' . (preg_match($this->needsEscape, $name) ? $this->quoteReplace($name) : $name) . '"';
        } else {
            // return without quotes
            return $name;
        }
    }

    private function str($value, $hasComment = null, $noIndent = null, $isRootObject = null)
    {
        // Produce a string from value.

        $startsWithNL = function ($str) {
            return $str && $str[$str[0] === "\r" ? 1 : 0] === "\n";
        };
        $testWsc = function ($str) use ($startsWithNL) {
            return $str && !$startsWithNL($str);
        };
        $wsc = function ($str) {
            if (!$str) {
                return "";
            }
            for ($i = 0; $i < mb_strlen($str); $i++) {
                $c = $str[$i];
                if ($c === "\n" ||
                    $c === '#' ||
                    $c === '/' && ($str[$i+1] === '/' || $str[$i+1] === '*')) {
                    break;
                }
                if ($c > ' ') {
                    return ' # ' . $str;
                }
            }
            return $str;
        };

        // What happens next depends on the value's type.
        switch (gettype($value)) {
            case 'string':
                $str = $this->quote($value, $this->gap, $hasComment, $isRootObject);
                return $str;

            case 'integer':
            case 'double':
                return is_numeric($value) ? str_replace('E', 'e', "$value") : 'null';

            case 'boolean':
                return $value ? 'true' : 'false';

            case 'NULL':
                return 'null';

            case 'object':
            case 'array':
                $isArray = is_array($value);

                $isAssocArray = function (array $arr) {
                    if (array() === $arr) {
                        return false;
                    }
                    return array_keys($arr) !== range(0, count($arr) - 1);
                };
                if ($isArray && $isAssocArray($value)) {
                    $value = (object) $value;
                    $isArray = false;
                }

                $kw = null;
                $kwl = null; // whitespace & comments
                if ($this->keepWsc) {
                    if ($isArray) {
                        $kw = @$value['__WSC__'];
                    } else {
                        $kw = @$value->__WSC__;
                    }
                }

                $showBraces = $isArray || !$isRootObject || ($kw ? !@$kw->noRootBraces : $this->emitRootBraces);

                // Make an array to hold the partial results of stringifying this object value.
                $mind = $this->gap;
                if ($showBraces) {
                    $this->gap .= $this->indent;
                }
                $eolMind = $this->eol . $mind;
                $eolGap = $this->eol . $this->gap;
                $prefix = $noIndent || $this->bracesSameLine ? '' : $eolMind;
                $partial = [];

                $k;
                $v; // key, value

                if ($isArray) {
                    // The value is an array. Stringify every element. Use null as a placeholder
                    // for non-JSON values.

                    $length = count($value);
                    if (array_key_exists('__WSC__', $value)) {
                        $length--;
                    }

                    for ($i = 0; $i < $length; $i++) {
                        if ($kw) {
                            $partial[] = $wsc(@$kw[$i]) . $eolGap;
                        }
                        $str = $this->str($value[$i], $kw ? $testWsc(@$kw[$i+1]) : false, true);
                        $partial[] = $str !== null ? $str : 'null';
                    }
                    if ($kw) {
                        $partial[] = $wsc(@$kw[$i]) . $eolMind;
                    }

                    // Join all of the elements together, separated with newline, and wrap them in
                    // brackets.
                    if ($kw) {
                        $v = $prefix . '[' . implode('', $partial) . ']';
                    } elseif (count($partial) === 0) {
                        $v = '[]';
                    } else {
                        $v = $prefix . '[' . $eolGap . implode($eolGap, $partial) . $eolMind . ']';
                    }
                } else {
                    // Otherwise, iterate through all of the keys in the object.

                    if ($kw) {
                        $emptyKey = " ";
                        $kwl = $wsc($kw->c->$emptyKey);
                        $keys = $kw->o;
                        foreach ($value as $k => $vvv) {
                            $keys[] = $k;
                        }
                        $keys = array_unique($keys);

                        for ($i = 0, $length = count($keys); $i < $length; $i++) {
                            $k = $keys[$i];
                            if ($k === '__WSC__') {
                                continue;
                            }
                            if ($showBraces || $i>0 || $kwl) {
                                $partial[] = $kwl . $eolGap;
                            }
                            $kwl = $wsc($kw->c->$k);
                            $v = $this->str($value->$k, $testWsc($kwl));
                            if ($v !== null) {
                                $partial[] = $this->quoteName($k) . ($startsWithNL($v) ? ':' : ': ') . $v;
                            }
                        }
                        if ($showBraces || $kwl) {
                            $partial[] = $kwl . $eolMind;
                        }
                    } else {
                        foreach ($value as $k => $vvv) {
                            $v = $this->str($vvv);
                            if ($v !== null) {
                                $partial[] = $this->quoteName($k) . ($startsWithNL($v) ? ':' : ': ') . $v;
                            }
                        }
                    }

                    // Join all of the member texts together, separated with newlines
                    if (count($partial) === 0) {
                        $v = '{}';
                    } elseif ($showBraces) {
                        // and wrap them in braces
                        if ($kw) {
                            $v = $prefix . '{' . implode('', $partial) . '}';
                        } else {
                            $v = $prefix . '{' . $eolGap . implode($eolGap, $partial) . $eolMind . '}';
                        }
                    } else {
                        $v = implode($kw ? '' : $eolGap, $partial);
                    }
                }

                $this->gap = $mind;
                return $v;
        }
    }
}
