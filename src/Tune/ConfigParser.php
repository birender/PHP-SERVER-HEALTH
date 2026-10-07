<?php
declare(strict_types=1);

namespace ServerHealth\Tune;

final class ConfigParser
{
    /** Parse an INI-like file (php.ini, FPM pool). @return array<string|int, array<string,string>> */
    public static function ini(string $file): array
    {
        $out = ['' => []];
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return $out;
        }
        $section = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^\[([^\]]+)\]$/', $line, $m)) {
                $section = $m[1];
                $out[$section] ??= [];
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            $v = trim((string)preg_replace('/\s;.*$/', '', $v));
            $out[$section][$k] = trim($v, "\"'");
        }
        return $out;
    }

    /** "256M" -> bytes, "-1" -> -1, unparsable -> null. */
    public static function bytes(string $v): ?int
    {
        $v = trim($v);
        if ($v === '-1') {
            return -1;
        }
        if (!preg_match('/^(\d+)\s*([KMG]?)B?$/i', $v, $m)) {
            return null;
        }
        return (int)$m[1] * match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1048576,
            'G' => 1073741824,
            default => 1,
        };
    }
}
