<?php
declare(strict_types=1);

namespace ServerHealth\Tune;

use ServerHealth\Check\MemoryCheck;

final class ApacheTuner
{
    private const MPM_KEYS = [
        'startservers', 'minspareservers', 'maxspareservers', 'serverlimit', 'maxrequestworkers',
        'minsparethreads', 'maxsparethreads', 'maxconnectionsperchild', 'threadsperchild', 'threadlimit',
    ];

    /** @var array<string, array<int, array{v:string, ctx:?string, file:string}>> */
    private array $d = [];
    /** @var array<string, bool> */
    private array $modules = [];
    private ?string $mpm = null;

    public function __construct(
        private array $cfg,
        private System $sys,
        private ?string $dirOpt,
        private bool $fpmPresent
    ) {}

    public function analyze(): array
    {
        $sec = ['title' => 'Apache', 'facts' => [], 'groups' => [], 'notes' => []];

        $dir = $this->dirOpt ?? (is_dir('/etc/apache2') ? '/etc/apache2' : (is_dir('/etc/httpd') ? '/etc/httpd' : null));
        if ($dir === null || !is_dir($dir)) {
            $sec['notes'][] = 'Apache config directory not found (tried /etc/apache2 and /etc/httpd). Use --apache-dir=DIR.';
            return $sec;
        }
        if ($this->sys->ramBytes <= 0) {
            $sec['notes'][] = 'Could not read total RAM from /proc/meminfo.';
            return $sec;
        }
        try {
            $this->scan($dir);
        } catch (\Throwable $e) {
            $sec['notes'][] = 'Could not read ' . $dir . ': ' . $e->getMessage();
            return $sec;
        }

        $detected = $this->detectMpm();
        $active = $detected ?? 'event';
        if ($detected === null) {
            $sec['notes'][] = 'Could not detect the active MPM (apachectl unavailable); assuming event.';
        }
        $phpModule = false;
        foreach (array_keys($this->modules) as $mod) {
            if (preg_match('/^php\d*_module$/', $mod)) {
                $phpModule = true;
            }
        }
        $target = ($active === 'prefork' && $this->fpmPresent) ? 'event' : $active;
        $this->mpm = $target;
        $threaded = in_array($target, ['event', 'worker'], true);
        $cores = $this->sys->cores;

        // memory budget
        $reserve = (float)($this->cfg['reserve_percent'] ?? 30);
        $share = (float)($this->cfg['fpm_share'] ?? 0.8);
        $budget = $this->sys->ramBytes * (1 - $reserve / 100) * (1 - $share);
        $m = System::measure('#^(\S*/)?(apache2|httpd)( |$)#', true);
        $assumed = (float)($this->cfg['apache_assumed_process_mb'] ?? ($threaded ? 30 : 20)) * 1048576;
        $avg = ($m['count'] > 0 && $target === $active) ? $m['avg'] : $assumed;

        $sec['facts'] = [
            'Config directory' => $dir,
            'Active MPM' => $active . ($target !== $active ? " (recommended: $target)" : ''),
            'RAM budget for Apache' => MemoryCheck::fmt($budget) . sprintf(' (%d%% of the usable RAM)', (int)round((1 - $share) * 100)),
            'Running workers' => $m['count'] > 0
                ? sprintf('%d, avg %s each (%s)', $m['count'], MemoryCheck::fmt($m['avg']), $m['method'])
                : 'none found, assuming ' . MemoryCheck::fmt($assumed) . ' per process',
        ];

        $F = [];

        // MPM choice
        if ($active === 'prefork' && $this->fpmPresent) {
            $F[] = new Finding('MPM', 'prefork', 'event', Finding::CHANGE,
                'PHP runs in PHP-FPM, so prefork is not needed; event handles keep-alive connections far cheaper', false);
            $sec['notes'][] = 'Switching MPM needs a restart, not a reload: '
                . ($phpModule ? 'sudo a2dismod phpX.Y ; ' : '')
                . 'sudo a2dismod mpm_prefork && sudo a2enmod mpm_event proxy_fcgi && sudo systemctl restart apache2 (Debian/Ubuntu). '
                . 'Make sure your vhost passes .php to FPM (SetHandler "proxy:unix:/run/php/phpX.Y-fpm.sock|fcgi://localhost") first.';
        } else {
            $F[] = new Finding('MPM', $active, $active, Finding::OK);
        }

        // capacity
        if ($threaded) {
            [, $tpcE] = $this->eff('threadsperchild', '25');
            $tpc = max(1, (int)$tpcE);
            $recMrw = (int)max($tpc * 2, min(2000, max(2, (int)floor($budget / $avg)) * $tpc));
            [$mrwD, $mrwE] = $this->eff('maxrequestworkers', '400');
            $worst = ceil(((int)$mrwE) / $tpc) * $avg;
        } else {
            $tpc = 1;
            $recMrw = (int)max(10, min(1500, floor($budget / $avg)));
            [$mrwD, $mrwE] = $this->eff('maxrequestworkers', '256');
            $worst = ((int)$mrwE) * $avg;
        }
        $mrwEff = (int)$mrwE;
        if ($mrwEff > $recMrw * 1.15) {
            $F[] = new Finding('MaxRequestWorkers', $mrwD, (string)$recMrw, Finding::WARN, sprintf(
                'at full load Apache could use ~%s, above its %s budget: OOM risk', MemoryCheck::fmt($worst), MemoryCheck::fmt($budget)));
            $targetMrw = $recMrw;
        } elseif ($mrwEff < $recMrw * 0.5) {
            $F[] = new Finding('MaxRequestWorkers', $mrwD, (string)$recMrw, Finding::INFO, 'RAM allows more concurrent connections; raise it only if the error log reports "server reached MaxRequestWorkers"');
            $targetMrw = $mrwEff;
        } else {
            $F[] = new Finding('MaxRequestWorkers', $mrwD, $mrwD, Finding::OK);
            $targetMrw = $mrwEff;
        }

        $needSl = (int)ceil($targetMrw / $tpc);
        [$slD, $slE] = $this->eff('serverlimit', $threaded ? '16' : '256');
        $F[] = ((int)$slE < $needSl)
            ? new Finding('ServerLimit', $slD, (string)$needSl, Finding::CHANGE, 'must be >= MaxRequestWorkers / ThreadsPerChild, otherwise Apache silently caps MaxRequestWorkers')
            : new Finding('ServerLimit', $slD, $slD, Finding::OK);

        if ($threaded) {
            [$minT, $maxT] = $targetMrw >= 300 ? [75, 250] : [max($tpc, intdiv($targetMrw, 4)), max($tpc * 2, intdiv($targetMrw, 2))];
            $tun = [['StartServers', '3', max(2, min($cores, $needSl))], ['MinSpareThreads', '75', $minT], ['MaxSpareThreads', '250', $maxT]];
        } else {
            $tun = [['StartServers', '5', max(5, min($cores * 2, $targetMrw))], ['MinSpareServers', '5', 5], ['MaxSpareServers', '10', 10]];
        }
        foreach ($tun as [$name, $def, $rec]) {
            $F[] = $this->tunable($name, $def, (int)$rec);
        }

        [$mcD, $mcE] = $this->eff('maxconnectionsperchild', '0');
        $F[] = ((int)$mcE === 0)
            ? new Finding('MaxConnectionsPerChild', $mcD, $threaded ? '10000' : '1000', Finding::CHANGE, 'recycles child processes so memory growth cannot accumulate forever')
            : new Finding('MaxConnectionsPerChild', $mcD, $mcD, Finding::OK);

        // connection handling
        [$kaD, $kaE] = $this->eff('keepalive', 'On');
        $F[] = strcasecmp($kaE, 'On') === 0
            ? new Finding('KeepAlive', $kaD, $kaD, Finding::OK)
            : new Finding('KeepAlive', $kaD, 'On', Finding::CHANGE, 'reusing connections avoids repeated TCP/TLS handshakes');

        [$mkD, $mkE] = $this->eff('maxkeepaliverequests', '100');
        $F[] = ((int)$mkE >= 100)
            ? new Finding('MaxKeepAliveRequests', $mkD, $mkD, Finding::OK)
            : new Finding('MaxKeepAliveRequests', $mkD, '100', Finding::CHANGE, $mkE === '0' ? 'unlimited requests per connection can pin workers' : 'too low: forces clients to reconnect often');

        $kaLimit = $threaded ? 5 : 2;
        [$ktD, $ktE] = $this->eff('keepalivetimeout', '5');
        $F[] = ((int)$ktE <= $kaLimit)
            ? new Finding('KeepAliveTimeout', $ktD, $ktD, Finding::OK)
            : new Finding('KeepAliveTimeout', $ktD, (string)$kaLimit, Finding::CHANGE, 'idle keep-alive connections occupy a worker/thread; ' . ($threaded ? 'keep it at 5s or less' : 'prefork ties up a whole process, keep it at 2s or less'));

        [$toD, $toE] = $this->eff('timeout', '60');
        $F[] = ((int)$toE <= 60)
            ? new Finding('Timeout', $toD, $toD, Finding::OK)
            : new Finding('Timeout', $toD, '60', Finding::CHANGE, 'long timeouts let slow or stalled clients hold workers (Debian ships 300)');

        foreach ([['ServerTokens', 'Full', 'Prod', 'hides the Apache/OS version from headers'], ['ServerSignature', 'Off', 'Off', 'no version footer on error pages'], ['HostnameLookups', 'Off', 'Off', 'DNS lookups per request slow every response']] as [$name, $def, $want, $why]) {
            [$d, $e] = $this->eff(strtolower($name), $def);
            $F[] = strcasecmp($e, $want) === 0
                ? new Finding($name, $d, $d, Finding::OK)
                : new Finding($name, $d, $want, Finding::CHANGE, $why);
        }

        // modules
        $enable = [];
        if ($this->modules) {
            foreach (['deflate' => 'compresses text responses (HTML/CSS/JS), saving 60-80% bandwidth', 'expires' => 'lets browsers cache static files', 'headers' => 'needed for cache and security headers'] as $mod => $why) {
                if (isset($this->modules[$mod . '_module'])) {
                    $F[] = new Finding("module: $mod", 'loaded', 'loaded', Finding::OK);
                } else {
                    $F[] = new Finding("module: $mod", 'not loaded', 'enable', Finding::CHANGE, $why, false);
                    $enable[] = $mod;
                }
            }
            if ($this->fpmPresent && !isset($this->modules['proxy_fcgi_module'])) {
                $F[] = new Finding('module: proxy_fcgi', 'not loaded', 'enable', Finding::WARN, 'required for Apache to hand PHP requests to PHP-FPM', false);
                $enable[] = 'proxy_fcgi';
            }
            if ($threaded && !isset($this->modules['http2_module'])) {
                $F[] = new Finding('module: http2', 'not loaded', 'enable', Finding::INFO, 'optional: HTTP/2 over HTTPS (add "Protocols h2 http/1.1" to your SSL vhost)', false);
            }
        } else {
            $sec['notes'][] = 'No LoadModule lines found, module checks were skipped.';
        }
        if ($phpModule && $target === 'event') {
            $sec['notes'][] = 'mod_php is loaded; it only works with prefork. Disable it once all sites use PHP-FPM.';
        }

        // drop-in snippet
        $global = [];
        $mpmLines = [];
        foreach ($F as $f) {
            if (!$f->isAction() || !$f->inSnippet) {
                continue;
            }
            if (in_array(strtolower($f->key), self::MPM_KEYS, true)) {
                $mpmLines[] = "    {$f->key} {$f->recommended}";
            } else {
                $global[] = "{$f->key} {$f->recommended}";
            }
        }
        $snippet = implode("\n", $global);
        if ($mpmLines) {
            $snippet .= ($snippet !== '' ? "\n\n" : '') . "<IfModule mpm_{$target}_module>\n" . implode("\n", $mpmLines) . "\n</IfModule>";
        }

        $debian = is_dir($dir . '/conf-available');
        $dropin = $debian ? $dir . '/conf-available/zz-tuning.conf' : $dir . '/conf.d/zz-tuning.conf';
        $apply = [];
        if ($snippet !== '' || $enable) {
            if ($snippet !== '') {
                $apply[] = "sudo nano $dropin     # create it with the snippet above";
                if ($debian) {
                    $apply[] = 'sudo a2enconf zz-tuning';
                }
            }
            if ($enable && $debian) {
                $apply[] = 'sudo a2enmod ' . implode(' ', $enable);
            } elseif ($enable) {
                $apply[] = '# enable modules (' . implode(', ', $enable) . ') with LoadModule lines in conf.modules.d/';
            }
            $apply[] = $debian
                ? 'sudo apachectl configtest && sudo systemctl reload apache2'
                : 'sudo apachectl configtest && sudo systemctl reload httpd';
        }

        $sec['groups'][] = [
            'title' => 'Capacity, connections and hardening',
            'file' => $dropin . ' (suggested new file)',
            'findings' => $F,
            'snippet' => $snippet,
            'apply' => $apply,
        ];
        $sec['notes'][] = 'PHP concurrency is limited by pm.max_children, not MaxRequestWorkers: extra PHP requests queue in FPM while Apache serves static files directly.';
        $sec['notes'][] = 'Settings are read statically from the .conf files (virtual-host overrides are ignored); the last matching line wins.';
        return $sec;
    }

    private function tunable(string $name, string $default, int $rec): Finding
    {
        [$d, $e] = $this->eff(strtolower($name), $default);
        $ratio = $rec > 0 ? ((int)$e / $rec) : 1;
        return ($ratio > 2 || $ratio < 0.5)
            ? new Finding($name, $d, (string)$rec, Finding::CHANGE, 'sized for this server\'s CPU and capacity')
            : new Finding($name, $d, $d, Finding::OK);
    }

    /** @return array{0:string,1:string} [display, effective] */
    private function eff(string $name, string $default): array
    {
        foreach (array_reverse($this->d[$name] ?? []) as $e) {
            if ($e['ctx'] === null || $e['ctx'] === $this->mpm) {
                return [$e['v'], $e['v']];
            }
        }
        return ["(default $default)", $default];
    }

    private function scan(string $dir): void
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $dir,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
        ));
        foreach ($it as $fi) {
            $p = $fi->getPathname();
            if (preg_match('/\.(conf|load)$/', $p) && !preg_match('#/(mods|conf|sites)-available/#', $p)) {
                $files[] = $p;
            }
        }
        usort($files, static function (string $a, string $b): int {
            $ra = in_array(basename($a), ['apache2.conf', 'httpd.conf'], true) ? 0 : 1;
            $rb = in_array(basename($b), ['apache2.conf', 'httpd.conf'], true) ? 0 : 1;
            return [$ra, $a] <=> [$rb, $b];
        });

        foreach ($files as $file) {
            $stack = [];
            foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (preg_match('#^</\w+#', $line)) {
                    array_pop($stack);
                    continue;
                }
                if (preg_match('/^<(\w+)\s*([^>]*)>/', $line, $m)) {
                    $stack[] = [strtolower($m[1]), trim($m[2])];
                    continue;
                }
                if (!preg_match('/^(\w+)\s+(.*)$/', $line, $m)) {
                    continue;
                }
                $name = strtolower($m[1]);
                $val = trim($m[2]);
                if ($name === 'loadmodule') {
                    if (preg_match('/^(\w+)/', $val, $mm)) {
                        $this->modules[$mm[1]] = true;
                    }
                    continue;
                }
                $ctx = null;
                $skip = false;
                foreach ($stack as [$tag, $arg]) {
                    if ($tag === 'ifmodule') {
                        if (str_starts_with($arg, '!')) {
                            $skip = true;
                        } elseif (preg_match('/^mpm_(\w+)_module$/i', $arg, $mm)) {
                            $ctx = strtolower($mm[1]);
                        }
                    } elseif (!in_array($tag, ['ifdefine', 'ifversion'], true)) {
                        $skip = true; // VirtualHost, Directory, Location, ...
                    }
                }
                if (!$skip) {
                    $this->d[$name][] = ['v' => $val, 'ctx' => $ctx, 'file' => $file];
                }
            }
        }
    }

    private function detectMpm(): ?string
    {
        if (function_exists('exec')) {
            foreach (['apache2ctl', 'apachectl', 'httpd'] as $bin) {
                $out = [];
                $rc = 1;
                @exec($bin . ' -V 2>&1', $out, $rc);
                if ($rc === 0 && preg_match('/Server MPM:\s+(\w+)/i', implode("\n", $out), $m)) {
                    return strtolower($m[1]);
                }
            }
        }
        foreach (['event', 'worker', 'prefork'] as $mpm) {
            if (isset($this->modules["mpm_{$mpm}_module"])) {
                return $mpm;
            }
        }
        return null;
    }
}
