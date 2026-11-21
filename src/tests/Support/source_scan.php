<?php

/*
 * Helpers for the source-level guards (tests/Unit/Architecture/*Guard*). Test-only. Loaded with require_once by each guard so a guard can run alone.
 */

if (! function_exists('guardStrip')) {
    /** The source with every comment and doc block removed (line count kept), so prose that names a table or a word is not a use of it. */
    function guardStrip(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($token[1], "\n")) : $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    /** @return array<string, string> method name => its source, for the methods of a class file with comments removed */
    function guardMethods(string $source): array
    {
        $code = guardStrip($source);
        preg_match_all('/\n    (?:(?:public|protected|private|static|final)\s+)*function\s+(\w+)\s*\(/', $code, $found, PREG_OFFSET_CAPTURE);
        $methods = [];

        foreach ($found[0] as $i => [, $start]) {
            $end = isset($found[0][$i + 1]) ? $found[0][$i + 1][1] : strlen($code);
            $methods[$found[1][$i][0]] = substr($code, $start, $end - $start);
        }

        return $methods;
    }
}
