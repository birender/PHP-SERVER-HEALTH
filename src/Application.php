<?php
declare(strict_types=1);

namespace ServerHealth;

use ServerHealth\Tune\TuneCommand;
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
        'tuning'   => ['reserve_percent' => 30, 'fpm_share' => 0.8, 'max_children_cap' => 500, 'fpm_assumed_worker_mb' => 40],
        'php'      => ['min_version' => '8.0.0', 'required_extensions' => ['json', 'mbstring']],
    ];

    public function run(array $argv): int
    {
        $opts = getopt('hvc:f:', ['help', 'version', 'config:', 'format:', 'no-color', 'tune', 'only:', 'pool:', 'php-ini:', 'apache-dir:']);

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

        $color = !isset($opts['no-color']) && function_exists('posix_isatty') && posix_isatty(STDOUT);

        if (isset($opts['tune'])) {
            return (new TuneCommand())->run($config['tuning'], $opts, $format, $color);
        }

        $results = [];
        foreach ($this->checks($config) as $check) {
            array_push($results, ...$check->run());
        }

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

Tuning mode (suggests PHP-FPM / Apache settings from this server's RAM and CPU):
      --tune          Analyze configs and print recommendations (changes nothing)
      --only WHAT     fpm, apache or all (default all)
      --pool FILE     PHP-FPM pool file (default: auto-detect /etc/php/*/fpm/pool.d/*.conf)
      --php-ini FILE  php.ini used by FPM (default: auto-detect)
      --apache-dir D  Apache config dir (default: /etc/apache2 or /etc/httpd)

  -v, --version       Show version
  -h, --help          Show this help

Exit codes: 0 OK, 1 WARN, 2 CRIT, 3 UNKNOWN/error
(with --tune: 0 nothing to change, 1 changes suggested, 2 risky setting, 3 usage error)

TXT;
    }
}
