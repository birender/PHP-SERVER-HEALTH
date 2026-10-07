<?php
declare(strict_types=1);

namespace ServerHealth\Check;

use ServerHealth\Result;

final class LoadCheck implements CheckInterface
{
    /** Thresholds are load-per-core (1.0 = fully busy). */
    public function __construct(private array $cfg = []) {}

    public function run(): array
    {
        $load = sys_getloadavg();
        if ($load === false) {
            return [new Result('load', Result::UNKNOWN, 'Cannot read load average')];
        }
        $cores = max(1, $this->cores());
        $perCore = $load[1] / $cores; // 5-minute average
        $status = Result::level($perCore, $this->cfg['warn'] ?? 0.7, $this->cfg['crit'] ?? 1.0);

        return [new Result(
            'load',
            $status,
            sprintf('%.2f %.2f %.2f (%d cores, %.2f/core)', $load[0], $load[1], $load[2], $cores, $perCore),
            ['load1' => $load[0], 'load5' => $load[1], 'load15' => $load[2], 'cores' => $cores]
        )];
    }

    private function cores(): int
    {
        $info = @file_get_contents('/proc/cpuinfo');
        return $info === false ? 1 : (int)preg_match_all('/^processor\s*:/m', $info);
    }
}
