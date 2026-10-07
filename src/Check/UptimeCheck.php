<?php
declare(strict_types=1);

namespace ServerHealth\Check;

use ServerHealth\Result;

final class UptimeCheck implements CheckInterface
{
    public function __construct(private array $cfg = []) {}

    public function run(): array
    {
        $raw = @file_get_contents('/proc/uptime');
        if ($raw === false) {
            return [new Result('uptime', Result::UNKNOWN, 'Cannot read /proc/uptime')];
        }
        $sec = (int)explode(' ', $raw)[0];
        $d = intdiv($sec, 86400);
        $h = intdiv($sec % 86400, 3600);
        $m = intdiv($sec % 3600, 60);

        // Warn on a very recent reboot (default: under 5 minutes).
        $status = $sec < ($this->cfg['recent_reboot_seconds'] ?? 300) ? Result::WARN : Result::OK;
        return [new Result('uptime', $status, "{$d}d {$h}h {$m}m", ['seconds' => $sec])];
    }
}
