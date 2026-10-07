<?php
declare(strict_types=1);

namespace ServerHealth\Check;

use ServerHealth\Result;

final class MemoryCheck implements CheckInterface
{
    public function __construct(private array $cfg = []) {}

    public function run(): array
    {
        $raw = @file_get_contents('/proc/meminfo');
        if ($raw === false) {
            return [new Result('memory', Result::UNKNOWN, 'Cannot read /proc/meminfo')];
        }
        preg_match_all('/^(\w+):\s+(\d+)\s*kB/m', $raw, $m);
        $mem = array_combine($m[1], array_map(fn($v) => (int)$v * 1024, $m[2]));

        $total = $mem['MemTotal'] ?? 0;
        $avail = $mem['MemAvailable'] ?? ($mem['MemFree'] ?? 0);
        if ($total === 0) {
            return [new Result('memory', Result::UNKNOWN, 'MemTotal not found')];
        }
        $usedPct = (1 - $avail / $total) * 100;
        $results = [new Result(
            'memory',
            Result::level($usedPct, $this->cfg['warn'] ?? 80, $this->cfg['crit'] ?? 90),
            sprintf('%.1f%% used (%s of %s)', $usedPct, self::fmt($total - $avail), self::fmt($total)),
            ['used_percent' => round($usedPct, 1), 'total_bytes' => $total, 'available_bytes' => $avail]
        )];

        $swapTotal = $mem['SwapTotal'] ?? 0;
        if ($swapTotal > 0) {
            $swapPct = (1 - ($mem['SwapFree'] ?? 0) / $swapTotal) * 100;
            $results[] = new Result(
                'swap',
                Result::level($swapPct, $this->cfg['swap_warn'] ?? 50, $this->cfg['swap_crit'] ?? 80),
                sprintf('%.1f%% used of %s', $swapPct, self::fmt($swapTotal)),
                ['used_percent' => round($swapPct, 1)]
            );
        }
        return $results;
    }

    public static function fmt(float|int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $bytes, $units[$i]);
    }
}
