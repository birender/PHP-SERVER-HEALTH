<?php
declare(strict_types=1);

namespace ServerHealth\Check;

use ServerHealth\Result;

final class PhpCheck implements CheckInterface
{
    public function __construct(private array $cfg = []) {}

    public function run(): array
    {
        $out = [];

        $min = $this->cfg['min_version'] ?? '8.1.0';
        $out[] = new Result(
            'php:version',
            version_compare(PHP_VERSION, $min, '>=') ? Result::OK : Result::WARN,
            PHP_VERSION . " (minimum $min)",
            ['version' => PHP_VERSION, 'sapi' => PHP_SAPI]
        );

        $missing = array_values(array_filter(
            $this->cfg['required_extensions'] ?? [],
            fn($e) => !extension_loaded($e)
        ));
        $out[] = new Result(
            'php:extensions',
            $missing ? Result::CRIT : Result::OK,
            $missing ? 'Missing: ' . implode(', ', $missing) : 'All required extensions loaded',
            ['missing' => $missing]
        );

        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $out[] = new Result(
            'php:opcache',
            $opcache ? Result::OK : Result::WARN,
            $opcache ? 'enabled' : 'disabled (CLI often has it off; check your FPM config)',
            ['enabled' => (bool)$opcache]
        );

        $out[] = new Result('php:memory_limit', Result::OK, (string)ini_get('memory_limit'));
        return $out;
    }
}
