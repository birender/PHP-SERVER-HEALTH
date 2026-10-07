<?php
declare(strict_types=1);

namespace ServerHealth\Tune;

final class Finding
{
    public const OK = 'OK';
    public const CHANGE = 'CHANGE';
    public const WARN = 'WARN';
    public const INFO = 'INFO';

    public function __construct(
        public string $key,
        public ?string $current,
        public ?string $recommended,
        public string $status,
        public string $reason = '',
        public bool $inSnippet = true
    ) {}

    public function isAction(): bool
    {
        return $this->recommended !== null && in_array($this->status, [self::CHANGE, self::WARN], true);
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'status' => $this->status,
            'current' => $this->current,
            'recommended' => $this->recommended,
            'reason' => $this->reason,
        ];
    }
}
