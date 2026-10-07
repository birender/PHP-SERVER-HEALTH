<?php
declare(strict_types=1);

namespace ServerHealth\Tune;

final class System
{
    public int $ramBytes = 0;
    public int $cores = 1;

    public static function detect(): self
    {
        $s = new self();
        $mem = @file_get_contents('/proc/meminfo');
        if ($mem !== false && preg_match('/^MemTotal:\s+(\d+)\s*kB/m', $mem, $m)) {
            $s->ramBytes = (int)$m[1] * 1024;
        }
        $cpu = @file_get_contents('/proc/cpuinfo');
        if ($cpu !== false) {
            $s->cores = max(1, (int)preg_match_all('/^processor\s*:/m', $cpu));
        }
        return $s;
    }

    /**
     * Measure memory of running processes whose command line matches $regex.
     * Uses PSS (accurate, needs root) and falls back to RSS.
     * @return array{count:int, avg:float, total:int, method:string}
     */
    public static function measure(string $regex, bool $skipRoot): array
    {
        $count = 0;
        $total = 0;
        $pss = 0;
        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $dir) {
            $cmd = @file_get_contents($dir . '/cmdline');
            if ($cmd === false || $cmd === '') {
                continue;
            }
            if (!preg_match($regex, trim(str_replace("\0", ' ', $cmd)))) {
                continue;
            }
            $status = @file_get_contents($dir . '/status');
            if ($status === false || ($skipRoot && preg_match('/^Uid:\s+0\b/m', $status))) {
                continue;
            }
            $bytes = null;
            $roll = @file_get_contents($dir . '/smaps_rollup');
            if ($roll !== false && preg_match('/^Pss:\s+(\d+)\s*kB/m', $roll, $m)) {
                $bytes = (int)$m[1] * 1024;
                $pss++;
            } elseif (preg_match('/^VmRSS:\s+(\d+)\s*kB/m', $status, $m)) {
                $bytes = (int)$m[1] * 1024;
            }
            if ($bytes === null) {
                continue;
            }
            $count++;
            $total += $bytes;
        }
        return [
            'count' => $count,
            'avg' => $count ? $total / $count : 0.0,
            'total' => $total,
            'method' => ($count > 0 && $pss === $count) ? 'PSS' : 'RSS',
        ];
    }
}
