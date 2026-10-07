<?php
declare(strict_types=1);

namespace ServerHealth;

final class Reporter
{
    /** @param Result[] $results */
    public static function text(array $results, bool $color): string
    {
        $colors = [Result::OK => "\033[32m", Result::WARN => "\033[33m", Result::CRIT => "\033[31m", Result::UNKNOWN => "\033[35m"];
        $width = max(array_map(fn($r) => strlen($r->name), $results) ?: [10]);
        $lines = [sprintf('Server health: %s @ %s', gethostname() ?: 'unknown', date('Y-m-d H:i:s')), ''];
        foreach ($results as $r) {
            $tag = sprintf('%-7s', $r->label());
            if ($color) {
                $tag = $colors[$r->status] . $tag . "\033[0m";
            }
            $lines[] = sprintf('%s %-' . $width . 's  %s', $tag, $r->name, $r->message);
        }
        $lines[] = '';
        $lines[] = 'Overall: ' . (new Result('overall', self::overall($results), ''))->label();
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /** @param Result[] $results */
    public static function json(array $results): string
    {
        return json_encode([
            'host' => gethostname(),
            'timestamp' => date(DATE_ATOM),
            'overall' => (new Result('overall', self::overall($results), ''))->label(),
            'checks' => array_map(fn($r) => [
                'name' => $r->name,
                'status' => $r->label(),
                'message' => $r->message,
                'metrics' => $r->metrics,
            ], $results),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    /** Severity order: CRIT > UNKNOWN > WARN > OK. Returns the exit code. */
    public static function overall(array $results): int
    {
        $rank = [Result::OK => 0, Result::WARN => 1, Result::UNKNOWN => 2, Result::CRIT => 3];
        $worst = Result::OK;
        foreach ($results as $r) {
            if ($rank[$r->status] > $rank[$worst]) {
                $worst = $r->status;
            }
        }
        return $worst;
    }
}
