<?php

declare(strict_types=1);

namespace Pam\Native\Observability;

final class EndpointPolicy
{
    private function __construct() {}

    public static function valid(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if ($parts === false
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return false;
        }

        return $parts['scheme'] === 'https'
            || ($parts['scheme'] === 'http' && self::loopback($parts['host']));
    }

    public static function permitsHttp(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'http'
            && isset($parts['host'])
            && self::loopback($parts['host']);
    }

    private static function loopback(string $host): bool
    {
        $host = trim($host, '[]');
        if (strcasecmp($host, 'localhost') === 0 || $host === '::1') {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && str_starts_with($host, '127.');
    }
}
