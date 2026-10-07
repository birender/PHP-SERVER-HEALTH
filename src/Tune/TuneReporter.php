<?php
declare(strict_types=1);

namespace ServerHealth\Tune;

final class TuneReporter
{
    public static function text(array $sections, bool $color): string
    {
        $c = static fn(string $s, string $code): string => $color ? "\033[{$code}m{$s}\033[0m" : $s;
        $col = ['OK' => '32', 'CHANGE' => '33', 'WARN' => '31', 'INFO' => '36'];

        $o = [sprintf('Tuning report: %s @ %s', gethostname() ?: 'unknown', date('Y-m-d H:i:s')), ''];
        foreach ($sections as $s) {
            $o[] = $c('== ' . $s['title'] . ' ==', '1');
            foreach ($s['facts'] as $k => $v) {
                $o[] = sprintf('  %-26s %s', $k, $v);
            }
            foreach ($s['groups'] as $g) {
                $o[] = '';
                $o[] = '  ' . $c($g['title'], '1') . '  (' . $g['file'] . ')';
                $w = max(array_map(static fn(Finding $f): int => strlen($f->key), $g['findings']) ?: [10]);
                foreach ($g['findings'] as $f) {
                    $cur = $f->current ?? '(not set)';
                    $val = ($f->status === Finding::OK || $f->recommended === null || $f->recommended === $f->current)
                        ? $cur
                        : "$cur  ->  {$f->recommended}";
                    $o[] = sprintf('    %s %-' . $w . 's  %s', $c(sprintf('%-6s', $f->status), $col[$f->status]), $f->key, $val);
                    if ($f->reason !== '' && $f->status !== Finding::OK) {
                        $o[] = '           ' . str_repeat(' ', $w) . '  why: ' . $f->reason;
                    }
                }
                if ($g['snippet'] !== '') {
                    $o[] = '';
                    $o[] = '    Suggested configuration:';
                    foreach (explode("\n", $g['snippet']) as $l) {
                        $o[] = '      | ' . $l;
                    }
                }
                if ($g['apply']) {
                    $o[] = '';
                    $o[] = '    How to apply:';
                    foreach ($g['apply'] as $cmd) {
                        $o[] = '      $ ' . $cmd;
                    }
                }
            }
            foreach ($s['notes'] as $n) {
                $o[] = '  * ' . $n;
            }
            $o[] = '';
        }
        $o[] = 'Nothing was modified. Back up files before editing, and re-run this report after changes.';
        return implode(PHP_EOL, $o) . PHP_EOL;
    }

    public static function json(array $sections): string
    {
        foreach ($sections as &$s) {
            foreach ($s['groups'] as &$g) {
                $g['findings'] = array_map(static fn(Finding $f): array => $f->toArray(), $g['findings']);
            }
            unset($g);
        }
        unset($s);
        return json_encode(['host' => gethostname(), 'timestamp' => date(DATE_ATOM), 'sections' => $sections],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}
