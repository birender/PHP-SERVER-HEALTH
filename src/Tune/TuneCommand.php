<?php
declare(strict_types=1);

namespace ServerHealth\Tune;

use ServerHealth\Check\MemoryCheck;

final class TuneCommand
{
    /** @return int 0 = nothing to change, 1 = changes suggested, 2 = risky setting found, 3 = usage error */
    public function run(array $cfg, array $opts, string $format, bool $color): int
    {
        $opt = static function (string $k) use ($opts): ?string {
            if (!isset($opts[$k])) {
                return null;
            }
            return (string)(is_array($opts[$k]) ? end($opts[$k]) : $opts[$k]);
        };

        $only = $opt('only') ?? 'all';
        if (!in_array($only, ['all', 'fpm', 'apache'], true)) {
            fwrite(STDERR, "--only must be fpm, apache or all\n");
            return 3;
        }

        $sys = System::detect();
        $reserve = (float)($cfg['reserve_percent'] ?? 30);
        $share = (float)($cfg['fpm_share'] ?? 0.8);
        $usable = $sys->ramBytes * (1 - $reserve / 100);

        $sections = [[
            'title' => 'System',
            'facts' => [
                'Total RAM' => MemoryCheck::fmt($sys->ramBytes),
                'CPU cores' => (string)$sys->cores,
                'Reserved for OS/DB/other' => sprintf('%d%% (%s)', (int)$reserve, MemoryCheck::fmt($sys->ramBytes * $reserve / 100)),
                'Usable for web stack' => sprintf('%s: %d%% PHP-FPM, %d%% Apache', MemoryCheck::fmt($usable), (int)round($share * 100), (int)round((1 - $share) * 100)),
            ],
            'groups' => [],
            'notes' => $reserve < 30 ? [] : ['If MySQL/MariaDB runs on this server, check its buffer pool size too: it is usually the largest consumer. Adjust "tuning.reserve_percent" in the config.'],
        ]];

        $fpm = new FpmTuner($cfg, $sys, $opt('pool'), $opt('php-ini'));
        if ($only !== 'apache') {
            $sections[] = $fpm->analyze();
        }
        if ($only !== 'fpm') {
            $sections[] = (new ApacheTuner($cfg, $sys, $opt('apache-dir'), (bool)$fpm->poolFiles()))->analyze();
        }

        echo $format === 'json' ? TuneReporter::json($sections) : TuneReporter::text($sections, $color);

        $rank = 0;
        foreach ($sections as $s) {
            foreach ($s['groups'] as $g) {
                foreach ($g['findings'] as $f) {
                    if ($f->status === Finding::WARN) {
                        $rank = max($rank, 2);
                    } elseif ($f->status === Finding::CHANGE) {
                        $rank = max($rank, 1);
                    }
                }
            }
        }
        return $rank;
    }
}
