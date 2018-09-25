<?php

namespace HJSON;

class HJSONUtils
{

    public static function tryParseNumber($text, $stopAtNext = null)
    {
        // Parse a number value.
        $number = null;
        $string = '';
        $leadingZeros = 0;
        $testLeading = true;
        $at = 0;
        $ch = null;
        
        $next = function () use ($text, &$ch, &$at) {
            $ch = mb_strlen($text) > $at ? $text[$at] : null;
            $at++;
            return $ch;
        };

        $next();

        if ($ch === '-') {
            $string = '-';
            $next();
        }

        while ($ch !== null && $ch >= '0' && $ch <= '9') {
            if ($testLeading) {
                if ($ch == '0') {
                    $leadingZeros++;
                } else {
                    $testLeading = false;
                }
            }
            $string .= $ch;
            $next();
        }
        if ($testLeading) {
            $leadingZeros--; // single 0 is allowed
        }
        if ($ch === '.') {
            $string .= '.';
            while ($next() !== null && $ch >= '0' && $ch <= '9') {
                $string .= $ch;
            }
        }
        if ($ch === 'e' || $ch === 'E') {
            $string .= $ch;
            $next();
            if ($ch === '-' || $ch === '+') {
                $string .= $ch;
                $next();
            }
            while ($ch !== null && $ch >= '0' && $ch <= '9') {
                $string .= $ch;
                $next();
            }
        }

        // skip white/to (newline)
        while ($ch !== null && $ch <= ' ') {
            $next();
        }

        if ($stopAtNext) {
            // end scan if we find a control character like ,}] or a comment
            if ($ch === ',' || $ch === '}' || $ch === ']' ||
                $ch === '#' || $ch === '/' && ($text[$at] === '/' || $text[$at] === '*')) {
                $ch = null;
            }
        }

        $number = $string;
        if (is_numeric($string)) {
            $number = 0+$string;
        }


        if ($ch !== null || $leadingZeros || !is_numeric($number)) {
            return null;
        } else {
            return $number;
        }
    }
}
