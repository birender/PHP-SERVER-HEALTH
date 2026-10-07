<?php
declare(strict_types=1);

namespace ServerHealth\Tune;

use ServerHealth\Check\MemoryCheck;

final class FpmTuner
{
    /** @var string[] */
    private array $files;

    public function __construct(
        private array $cfg,
        private System $sys,
        ?string $poolPath = null,
        private ?string $iniPath = null
    ) {
        $this->files = $poolPath !== null
            ? [$poolPath]
            : array_merge(glob('/etc/php/*/fpm/pool.d/*.conf') ?: [], glob('/etc/php-fpm.d/*.conf') ?: []);
        sort($this->files);
    }

    /** @return string[] */
    public function poolFiles(): array
    {
        return $this->files;
    }

    public function analyze(): array
    {
        $sec = ['title' => 'PHP-FPM', 'facts' => [], 'groups' => [], 'notes' => []];

        if (!$this->files) {
            $sec['notes'][] = 'No PHP-FPM pool file found (looked in /etc/php/*/fpm/pool.d and /etc/php-fpm.d). Use --pool=FILE.';
            return $sec;
        }
        if ($this->sys->ramBytes <= 0) {
            $sec['notes'][] = 'Could not read total RAM from /proc/meminfo.';
            return $sec;
        }

        $pools = [];
        foreach ($this->files as $file) {
            if (!is_readable($file)) {
                $sec['notes'][] = "Cannot read $file (run with sudo?).";
                continue;
            }
            foreach (ConfigParser::ini($file) as $name => $vals) {
                if ((string)$name === '' || $name === 'global') {
                    continue;
                }
                $pools[] = ['file' => $file, 'name' => (string)$name, 'v' => $vals];
            }
        }
        if (!$pools) {
            $sec['notes'][] = 'No [pool] sections found in the pool files.';
            return $sec;
        }

        $reserve = (float)($this->cfg['reserve_percent'] ?? 30);
        $share = (float)($this->cfg['fpm_share'] ?? 0.8);
        $usable = $this->sys->ramBytes * (1 - $reserve / 100);
        $budget = $usable * $share;
        $perPool = $budget / count($pools);

        $m = System::measure('/^php-fpm: pool /', false);
        $assumed = (float)($this->cfg['fpm_assumed_worker_mb'] ?? 40) * 1048576;
        // Idle workers look smaller than busy ones, so never assume less than a floor (default 20 MB).
        $floor = (float)($this->cfg['fpm_min_worker_mb'] ?? 20) * 1048576;
        $floored = $m['count'] > 0 && $m['avg'] < $floor;
        $avg = $m['count'] > 0 ? max($m['avg'], $floor) : $assumed;
        $recMax = (int)max(5, min((int)($this->cfg['max_children_cap'] ?? 500), floor($perPool / $avg)));

        [$hits, $logFile] = $this->logHits($this->version($this->files[0]));

        $sec['facts'] = [
            'Pools analysed' => count($pools) . ' in ' . count($this->files) . ' file(s)',
            'RAM budget for PHP-FPM' => sprintf('%s (%d%% of RAM, minus %d%% OS/DB reserve and Apache share)', MemoryCheck::fmt($budget), (int)round($share * 100), (int)$reserve),
            'Running workers' => $m['count'] > 0
                ? sprintf('%d, avg %s each (%s), total %s%s', $m['count'], MemoryCheck::fmt($m['avg']), $m['method'], MemoryCheck::fmt($m['total']), $floored ? '; using ' . MemoryCheck::fmt($floor) . ' per worker for sizing' : '')
                : 'none found, assuming ' . MemoryCheck::fmt($assumed) . ' per worker',
            'Log: max_children reached' => $logFile !== null ? "$hits time(s) in recent log ($logFile)" : 'FPM log not found or not readable',
        ];
        if ($m['count'] === 0) {
            $sec['notes'][] = 'No running FPM workers were measured, so a 40 MB/worker guess was used. Re-run while the site is busy for accurate numbers.';
        }
        if (count($pools) > 1) {
            $sec['notes'][] = 'The memory budget is split evenly across pools.';
        }

        foreach ($pools as $p) {
            $sec['groups'][] = $this->poolGroup($p, $avg, $perPool, $recMax, $hits);
        }
        foreach ($this->iniFiles() as $ini) {
            $sec['groups'][] = $this->iniGroup($ini);
        }
        return $sec;
    }

    private function poolGroup(array $p, float $avg, float $perPool, int $recMax, int $hits): array
    {
        $v = $p['v'];
        $cores = $this->sys->cores;
        $F = [];

        // pm.max_children
        $cur = $this->num($v['pm.max_children'] ?? null);
        if ($cur === null) {
            $F[] = new Finding('pm.max_children', null, (string)$recMax, Finding::CHANGE, 'required setting');
        } elseif ($cur > $recMax * 1.15) {
            $F[] = new Finding('pm.max_children', (string)$cur, (string)$recMax, Finding::WARN, sprintf(
                '%d workers x ~%s = %s, above this pool\'s %s budget: OOM-kill risk under load',
                $cur, MemoryCheck::fmt($avg), MemoryCheck::fmt($cur * $avg), MemoryCheck::fmt($perPool)
            ));
        } elseif ($hits > 0 && $cur < $recMax) {
            $F[] = new Finding('pm.max_children', (string)$cur, (string)$recMax, Finding::CHANGE,
                "log shows the limit was hit $hits time(s) and RAM allows more");
        } elseif ($cur < $recMax * 0.6) {
            $F[] = new Finding('pm.max_children', (string)$cur, (string)$recMax, Finding::INFO,
                'RAM allows more; raise only if you see "reached pm.max_children" in the log');
        } else {
            $F[] = new Finding('pm.max_children', (string)$cur, (string)$cur, Finding::OK);
        }
        $effMax = ($cur !== null && $cur > 0 && $cur <= $recMax * 1.15) ? $cur : $recMax;

        // pm mode
        $pm = $v['pm'] ?? null;
        if ($pm === 'ondemand') {
            $F[] = new Finding('pm', 'ondemand', 'dynamic', Finding::INFO, 'ondemand saves RAM on idle sites but adds latency on traffic spikes', false);
        } elseif ($pm === null) {
            $F[] = new Finding('pm', null, 'dynamic', Finding::INFO, 'not set', false);
        } else {
            $F[] = new Finding('pm', $pm, $pm, Finding::OK);
        }

        // dynamic spare servers
        if ($pm === 'dynamic') {
            $minS = (int)max(2, min($cores * 2, $effMax));
            $maxS = (int)min($effMax, max($minS + 2, $cores * 4));
            $start = (int)max($minS, min($maxS, $minS + intdiv($maxS - $minS, 2)));
            $cs = $this->num($v['pm.start_servers'] ?? null);
            $cmin = $this->num($v['pm.min_spare_servers'] ?? null);
            $cmax = $this->num($v['pm.max_spare_servers'] ?? null);
            $bad = ($cs !== null && $cmin !== null && $cmax !== null) && !($cmin <= $cs && $cs <= $cmax && $cmax <= $effMax);
            foreach ([['pm.start_servers', $start, $cs], ['pm.min_spare_servers', $minS, $cmin], ['pm.max_spare_servers', $maxS, $cmax]] as [$key, $rec, $c]) {
                if ($c === null) {
                    $F[] = new Finding($key, null, (string)$rec, Finding::CHANGE, 'required for pm = dynamic');
                } elseif ($bad) {
                    $F[] = new Finding($key, (string)$c, (string)$rec, Finding::WARN, 'must satisfy min_spare <= start_servers <= max_spare <= max_children');
                } elseif ($c / $rec > 2 || $c / $rec < 0.5) {
                    $F[] = new Finding($key, (string)$c, (string)$rec, Finding::CHANGE, "sized for $cores CPU core(s)");
                } else {
                    $F[] = new Finding($key, (string)$c, (string)$c, Finding::OK);
                }
            }
        }

        // recycling and safety nets
        $mr = $this->num($v['pm.max_requests'] ?? null);
        if ($mr === null || $mr === 0) {
            $F[] = new Finding('pm.max_requests', $mr === null ? null : '0', '500', Finding::CHANGE, 'recycles workers to contain slow memory leaks');
        } else {
            $F[] = new Finding('pm.max_requests', (string)$mr, (string)$mr, Finding::OK);
        }

        $rtt = $v['request_terminate_timeout'] ?? null;
        if ($rtt === null || (int)$rtt === 0) {
            $F[] = new Finding('request_terminate_timeout', $rtt, '60s', Finding::CHANGE, 'a hung script otherwise holds a worker forever; raise it if you have legitimately long requests');
        } else {
            $F[] = new Finding('request_terminate_timeout', $rtt, $rtt, Finding::OK);
        }

        $slow = $v['request_slowlog_timeout'] ?? null;
        if ($slow === null || (int)$slow === 0) {
            $ver = $this->version($p['file']);
            $F[] = new Finding('request_slowlog_timeout', $slow, '5s', Finding::CHANGE, 'logs a backtrace of requests slower than 5s, so you can find slow code');
            if (!isset($v['slowlog'])) {
                $F[] = new Finding('slowlog', null, $ver ? "/var/log/php{$ver}-fpm-slow.log" : '/var/log/php-fpm/www-slow.log', Finding::CHANGE, 'where slow-request traces are written');
            }
        } else {
            $F[] = new Finding('request_slowlog_timeout', $slow, $slow, Finding::OK);
        }

        if (!isset($v['pm.status_path'])) {
            $F[] = new Finding('pm.status_path', null, '/fpm-status', Finding::INFO, 'optional: live pool stats for monitoring (restrict access in Apache)', false);
        }

        $listen = $v['listen'] ?? null;
        if ($listen !== null && preg_match('/^(0\.0\.0\.0|\*|\[::\]):\d+$/', $listen)) {
            $F[] = new Finding('listen', $listen, null, Finding::INFO, 'listening on all interfaces; bind to 127.0.0.1 or a unix socket unless Apache runs on another host', false);
        }

        $lines = [];
        foreach ($F as $f) {
            if ($f->isAction() && $f->inSnippet) {
                $lines[] = sprintf('%s = %s', $f->key, $f->recommended);
            }
        }
        $file = $p['file'];
        $ver = $this->version($file);
        return [
            'title' => 'Pool [' . $p['name'] . ']',
            'file' => $file,
            'findings' => $F,
            'snippet' => implode("\n", $lines),
            'apply' => $lines ? [
                "sudo cp $file $file.bak",
                "sudo nano $file     # set the values listed above",
                $ver ? "sudo php-fpm$ver -t && sudo systemctl reload php$ver-fpm" : 'sudo php-fpm -t && sudo systemctl reload php-fpm',
            ] : [],
        ];
    }

    /** @return string[] */
    private function iniFiles(): array
    {
        if ($this->iniPath !== null) {
            return [$this->iniPath];
        }
        $out = [];
        foreach ($this->files as $f) {
            $ver = $this->version($f);
            if ($ver !== null && is_file("/etc/php/$ver/fpm/php.ini")) {
                $out["/etc/php/$ver/fpm/php.ini"] = true;
            }
        }
        if (!$out && is_file('/etc/php.ini')) {
            $out['/etc/php.ini'] = true;
        }
        return array_keys($out);
    }

    private function iniGroup(string $ini): array
    {
        $debian = str_starts_with($ini, '/etc/php/');
        $confd = ($debian || is_dir(dirname($ini) . '/conf.d')) ? dirname($ini) . '/conf.d' : '/etc/php.d';
        $extra = glob($confd . '/*.ini') ?: [];
        sort($extra);
        $vals = [];
        foreach (array_merge([$ini], $extra) as $f) {
            foreach (ConfigParser::ini($f) as $sec) {
                foreach ($sec as $k => $val) {
                    $vals[$k] = $val;
                }
            }
        }

        $on = static fn(string $s): bool => in_array(strtolower(trim($s)), ['1', 'on', 'true', 'yes'], true);
        $F = [];
        $checks = [
            ['opcache.enable', '1', 'on', 0, 'without OPcache every request recompiles all PHP files'],
            ['opcache.memory_consumption', '128', 'min', 256, 'a full cache causes restarts and slowdowns'],
            ['opcache.interned_strings_buffer', '8', 'min', 16, 'big frameworks overflow the small default'],
            ['opcache.max_accelerated_files', '10000', 'min', 20000, 'WordPress/Laravel/Symfony trees can exceed the default'],
            ['expose_php', '1', 'off', 0, 'hides the PHP version from response headers'],
            ['display_errors', '1', 'off', 0, 'never show errors to visitors in production'],
        ];
        foreach ($checks as [$key, $def, $type, $target, $why]) {
            $raw = $vals[$key] ?? null;
            $eff = $raw ?? $def;
            $shown = $raw ?? "(default $def)";
            if ($type === 'on') {
                $F[] = $on($eff) ? new Finding($key, $shown, '1', Finding::OK) : new Finding($key, $shown, '1', Finding::CHANGE, $why);
            } elseif ($type === 'off') {
                $F[] = !$on($eff) ? new Finding($key, $shown, 'Off', Finding::OK) : new Finding($key, $shown, 'Off', Finding::CHANGE, $why);
            } else {
                $F[] = (int)$eff >= $target ? new Finding($key, $shown, $shown, Finding::OK) : new Finding($key, $shown, (string)$target, Finding::CHANGE, $why);
            }
        }
        if ($on($vals['opcache.validate_timestamps'] ?? '1')) {
            $F[] = new Finding('opcache.validate_timestamps', $vals['opcache.validate_timestamps'] ?? '(default 1)', '0', Finding::INFO,
                'optional: 0 is fastest, but you must reload php-fpm after every deploy', false);
        }
        if (isset($vals['memory_limit'])) {
            $F[] = new Finding('memory_limit', $vals['memory_limit'], null, Finding::INFO, 'per-request ceiling; worst case = pm.max_children x memory_limit', false);
        }

        $lines = [];
        foreach ($F as $f) {
            if ($f->isAction() && $f->inSnippet) {
                $lines[] = sprintf('%s = %s', $f->key, $f->recommended);
            }
        }
        $target = $confd . '/99-tuning.ini';
        $ver = $debian && preg_match('#/etc/php/(\d+\.\d+)/#', $ini, $m) ? $m[1] : null;
        return [
            'title' => 'PHP settings (OPcache & hardening)',
            'file' => $ini,
            'findings' => $F,
            'snippet' => implode("\n", $lines),
            'apply' => $lines ? [
                "sudo nano $target     # create it with the lines above (overrides php.ini safely)",
                $ver ? "sudo php-fpm$ver -t && sudo systemctl reload php$ver-fpm" : 'sudo php-fpm -t && sudo systemctl reload php-fpm',
            ] : [],
        ];
    }

    private function version(string $file): ?string
    {
        return preg_match('#/etc/php/(\d+\.\d+)/#', $file, $m) ? $m[1] : null;
    }

    private function num(?string $s): ?int
    {
        return ($s !== null && preg_match('/^\d+$/', trim($s))) ? (int)$s : null;
    }

    /** @return array{0:int,1:?string} */
    private function logHits(?string $ver): array
    {
        $cands = $ver !== null ? ["/var/log/php{$ver}-fpm.log"] : [];
        array_push($cands, '/var/log/php-fpm/error.log', '/var/log/php-fpm/www-error.log', '/var/log/php-fpm.log');
        foreach ($cands as $file) {
            if (!is_readable($file)) {
                continue;
            }
            $fh = @fopen($file, 'rb');
            if (!$fh) {
                continue;
            }
            $size = (int)filesize($file);
            if ($size > 262144) {
                fseek($fh, $size - 262144);
            }
            $txt = (string)stream_get_contents($fh);
            fclose($fh);
            return [(int)preg_match_all('/reached pm\.max_children/', $txt), $file];
        }
        return [0, null];
    }
}
