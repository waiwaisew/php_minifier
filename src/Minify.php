<?php
namespace Waiwaisew\Minifier;

final class Minify
{
    /** Minify (and by default mangle) a JavaScript string. */
    public static function js(string $code, bool $mangle = true, bool $collapseInlineWhitespace = false): string
    {
        $m = new JsMinifier();
        $m->collapseInlineWhitespace = $collapseInlineWhitespace;
        return $m->minify($code, $mangle);
    }

    /** Minify a file and return the result. */
    public static function file(string $path, bool $mangle = true, bool $collapseInlineWhitespace = false): string
    {
        if (!is_file($path)) throw new \RuntimeException("File not found: $path");
        return self::js((string)file_get_contents($path), $mangle, $collapseInlineWhitespace);
    }

    /** Minify $in and write the result to $out. Returns bytes written. */
    public static function toFile(string $in, string $out, bool $mangle = true): int
    {
        $code = self::file($in, $mangle);
        $n = file_put_contents($out, $code);
        if ($n === false) throw new \RuntimeException("Cannot write: $out");
        return $n;
    }
}
