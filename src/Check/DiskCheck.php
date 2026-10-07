<?php
declare(strict_types=1);

namespace ServerHealth\Check;

use ServerHealth\Result;

final class DiskCheck implements CheckInterface
{
    public function __construct(private array $cfg = []) {}

    public function run(): array
    {
        $out = [];
        foreach ($this->cfg['paths'] ?? ['/'] as $path) {
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
            if (!$total || $free === false) {
                $out[] = new Result("disk:$path", Result::UNKNOWN, 'Cannot stat path');
                continue;
            }
            $pct = (1 - $free / $total) * 100;
            $out[] = new Result(
                "disk:$path",
                Result::level($pct, $this->cfg['warn'] ?? 80, $this->cfg['crit'] ?? 90),
                sprintf('%.1f%% used (%s free of %s)', $pct, MemoryCheck::fmt($free), MemoryCheck::fmt($total)),
                ['used_percent' => round($pct, 1), 'free_bytes' => $free, 'total_bytes' => $total]
            );
        }
        return $out;
    }
}
