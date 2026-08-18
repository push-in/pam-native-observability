<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

use InvalidArgumentException;

final readonly class TraceContext
{
    private function __construct(
        public string $traceId,
        public string $spanId,
        public int $flags,
    ) {}

    public static function fromTraceparent(string $value): self
    {
        $parts = explode('-', $value);
        $valid = count($parts) === 4
            && $parts[0] === '00'
            && preg_match('/^[0-9a-f]{32}$/D', $parts[1]) === 1
            && $parts[1] !== str_repeat('0', 32)
            && preg_match('/^[0-9a-f]{16}$/D', $parts[2]) === 1
            && $parts[2] !== str_repeat('0', 16)
            && preg_match('/^[0-9a-f]{2}$/D', $parts[3]) === 1;
        if (!$valid) {
            throw new InvalidArgumentException('Invalid W3C version 00 traceparent.');
        }

        return new self(
            traceId: $parts[1],
            spanId: $parts[2],
            flags: intval($parts[3], 16),
        );
    }

    public function traceparent(): string
    {
        return sprintf('00-%s-%s-%02x', $this->traceId, $this->spanId, $this->flags);
    }

    public function sampled(): bool
    {
        return ($this->flags & 1) === 1;
    }
}
