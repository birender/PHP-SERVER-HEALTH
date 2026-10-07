<?php
declare(strict_types=1);

namespace ServerHealth;

use ServerHealth\Check\{CheckInterface, DiskCheck, LoadCheck, MemoryCheck, PhpCheck, ServiceCheck, UptimeCheck};

final class Application
{
    public const VERSION = '1.0.0';

    private const DEFAULTS = [
        'load'     => ['warn' => 0.7, 'crit' => 1.0],
        'memory'   => ['warn' => 80, 'crit' => 90, 'swap_warn' => 50, 'swap_crit' => 80],
        'disk'     => ['paths' => ['/'], 'warn' => 80, 'crit' => 90],
        'uptime'   => ['recent_reboot_seconds' => 300],
        'services' => ['services' => []],
        'php'      => ['min_version' => '8.1.0', 'required_extensions' => ['json', 'mbstring']],
    ];

    public function run(array $argv): int
    {
        $opts = getopt('hvc:f:', ['help', 'version', 'config:', 'format:', 'no-color']);

        if (isset($opts['h']) || isset($opts['help'])) {
            echo $this->help();
            return 0;
        }
        if (isset($opts['v']) || isset($opts['version'])) {
            echo 'php-server-health ' . self::VERSION . PHP_EOL;
            return 0;
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            fwrite(STDERR, "This tool only supports Linux.\n");
            return 3;
        }

        $format = $opts['f'] ?? $opts['format'] ?? 'text';
        if (!in_array($format, ['text', 'json'], true)) {
            fwrite(STDERR, "Unknown format: $format (use text or json)\n");
            return 3;
        }

        try {
            $config = $this->loadConfig($opts['c'] ?? $opts['config'] ?? null);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, 'Config error: ' . $e->getMessage() . PHP_EOL);
            return 3;
        }

        $results = [];
        foreach ($this->checks($config) as $check) {
            array_push($results, ...$check->run());
        }

        $color = !isset($opts['no-color']) && function_exists('posix_isatty') && posix_isatty(STDOUT);
        echo $format === 'json' ? Reporter::json($results) : Reporter::text($results, $color);

        return Reporter::overall($results); // 0 OK, 1 WARN, 2 CRIT, 3 UNKNOWN
    }

    /** @return CheckInterface[] */
    private function checks(array $c): array
    {
        return [
            new UptimeCheck($c['uptime']),
            new LoadCheck($c['load']),
            new MemoryCheck($c['memory']),
            new DiskCheck($c['disk']),
            new PhpCheck($c['php']),
            new ServiceCheck($c['services']),
        ];
    }

    private function loadConfig(?string $path): array
    {
        $config = self::DEFAULTS;
        $candidates = $path ? [$path] : ['/etc/php-server-health.json'];

        foreach ($candidates as $file) {
            if (!is_file($file)) {
                if ($path) {
                    throw new \RuntimeException("File not found: $file");
                }
                continue;
            }
            $data = json_decode((string)file_get_contents($file), true);
            if (!is_array($data)) {
                throw new \RuntimeException("Invalid JSON in $file");
            }
            foreach ($data as $section => $values) {
                if (isset($config[$section]) && is_array($values)) {
                    $config[$section] = array_replace($config[$section], $values);
                }
            }
            break;
        }
        return $config;
    }

    private function help(): string
    {
        return 'php-server-health v' . self::VERSION . PHP_EOL . <<<'TXT'

Usage: php-server-health [options]

  -c, --config FILE   JSON config (default: /etc/php-server-health.json)
  -f, --format FMT    text (default) or json
      --no-color      Disable colored output
  -v, --version       Show version
  -h, --help          Show this help

Exit codes: 0 OK, 1 WARN, 2 CRIT, 3 UNKNOWN/error

TXT;
    }
}
