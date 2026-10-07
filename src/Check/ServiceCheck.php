<?php
declare(strict_types=1);

namespace ServerHealth\Check;

use ServerHealth\Result;

final class ServiceCheck implements CheckInterface
{
    public function __construct(private array $cfg = []) {}

    public function run(): array
    {
        $out = [];
        foreach ($this->cfg['services'] ?? [] as $svc) {
            if (!preg_match('/^[A-Za-z0-9@._:-]+$/', (string)$svc)) {
                $out[] = new Result("service:$svc", Result::UNKNOWN, 'Invalid service name');
                continue;
            }
            $output = [];
            $code = 0;
            @exec('systemctl is-active ' . escapeshellarg($svc) . ' 2>&1', $output, $code);
            $state = trim($output[0] ?? 'unknown');
            $known = ['active', 'inactive', 'failed', 'activating', 'deactivating', 'reloading'];
            if (!in_array($state, $known, true)) {
                $out[] = new Result("service:$svc", Result::UNKNOWN, 'systemctl unavailable or error: ' . $state);
                continue;
            }
            $out[] = new Result(
                "service:$svc",
                $state === 'active' ? Result::OK : Result::CRIT,
                $state,
                ['state' => $state]
            );
        }
        return $out;
    }
}
