<?php
declare(strict_types=1);

namespace ServerHealth;

final class Result
{
    public const OK = 0;
    public const WARN = 1;
    public const CRIT = 2;
    public const UNKNOWN = 3;

    public function __construct(
        public string $name,
        public int $status,
        public string $message,
        public array $metrics = []
    ) {}

    public function label(): string
    {
        return match ($this->status) {
            self::OK => 'OK',
            self::WARN => 'WARN',
            self::CRIT => 'CRIT',
            default => 'UNKNOWN',
        };
    }

    /** Pick WARN/CRIT/OK from a value and thresholds (higher = worse). */
    public static function level(float $value, float $warn, float $crit): int
    {
        return $value >= $crit ? self::CRIT : ($value >= $warn ? self::WARN : self::OK);
    }
}
