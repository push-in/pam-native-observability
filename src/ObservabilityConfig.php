<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

use InvalidArgumentException;

final readonly class ObservabilityConfig
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $endpoint,
        public string $serviceName,
        public string $serviceVersion = '0.0.0',
        public float $sampleRate = 1.0,
        public int $batchSize = 64,
        public int $maxQueue = 1024,
        public int $timeoutMillis = 5000,
        public array $headers = [],
        public WireProtocol $wireProtocol = WireProtocol::PamJson,
        public bool $captureExceptionDetails = false,
    ) {
        if (!EndpointPolicy::valid($endpoint)) {
            throw new InvalidArgumentException(
                'Telemetry endpoint must use HTTPS; HTTP is allowed only on loopback.',
            );
        }
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $serviceName) !== 1
            || preg_match('/^[^\x00-\x1f]{1,128}$/u', $serviceVersion) !== 1
            || $sampleRate < 0
            || $sampleRate > 1
            || $batchSize < 1
            || $batchSize > 512
            || $maxQueue < $batchSize
            || $maxQueue > 10000
            || $timeoutMillis < 100
            || $timeoutMillis > 30000
        ) {
            throw new InvalidArgumentException('Invalid observability configuration.');
        }
        foreach ($headers as $key => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/D', $key) !== 1
                || str_contains($value, "\r")
                || str_contains($value, "\n")
            ) {
                throw new InvalidArgumentException('Invalid telemetry header.');
            }
        }
    }

    public function signalEndpoint(SignalFamily $family): string
    {
        if ($this->wireProtocol === WireProtocol::PamJson) {
            return $this->endpoint;
        }

        return rtrim($this->endpoint, '/').match ($family) {
            SignalFamily::Trace => '/v1/traces',
            SignalFamily::Log => '/v1/logs',
            SignalFamily::Metric => '/v1/metrics',
        };
    }
}
