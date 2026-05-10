<?php

namespace App\Support\Http;

final class IpAddressResolver
{
    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $headers
     */
    public static function resolve(array $server = [], array $headers = []): ?string
    {
        $remoteAddr = self::normalizeIp($server['REMOTE_ADDR'] ?? null);
        if ($remoteAddr === null) {
            return null;
        }

        if (!self::isTrustedProxy($remoteAddr)) {
            return $remoteAddr;
        }

        $forwardedFor = self::headerValue($headers, $server, 'X-Forwarded-For');
        if ($forwardedFor !== null) {
            // Walk right-to-left, dropping any entry that itself matches a
            // trusted-proxy CIDR. The first untrusted entry from the right
            // is the real client. Standard proxy behavior is to *append*
            // the connecting client to X-Forwarded-For, so left-to-right
            // walking would trust whatever the original client sent in its
            // own X-Forwarded-For header (spoofable).
            $entries = array_reverse(explode(',', $forwardedFor));
            foreach ($entries as $candidate) {
                $ip = self::normalizeIp($candidate);
                if ($ip === null) {
                    continue;
                }
                if (self::isTrustedProxy($ip)) {
                    continue;
                }
                return $ip;
            }
        }

        $realIp = self::normalizeIp(self::headerValue($headers, $server, 'X-Real-IP'));
        if ($realIp !== null) {
            return $realIp;
        }

        return $remoteAddr;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $server
     */
    private static function headerValue(array $headers, array $server, string $name): ?string
    {
        $normalized = strtoupper(str_replace('-', '_', $name));

        if (isset($headers[strtoupper(str_replace('_', '-', $normalized))])) {
            return $headers[strtoupper(str_replace('_', '-', $normalized))];
        }

        $serverKey = 'HTTP_' . $normalized;
        if (isset($server[$serverKey]) && is_string($server[$serverKey])) {
            return $server[$serverKey];
        }

        return null;
    }

    private static function normalizeIp(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $candidate = trim($value);
        if ($candidate === '') {
            return null;
        }

        return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : null;
    }

    private static function isTrustedProxy(string $remoteAddr): bool
    {
        foreach (self::trustedProxies() as $proxy) {
            if (self::ipMatches($remoteAddr, $proxy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private static function trustedProxies(): array
    {
        $raw = null;

        if (function_exists('env')) {
            $raw = env('TRUSTED_PROXIES', '');
        }

        if (!is_string($raw) || $raw === '') {
            $raw = $_ENV['TRUSTED_PROXIES'] ?? $_SERVER['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES') ?: '';
        }

        $entries = array_map('trim', explode(',', (string) $raw));

        return array_values(array_filter($entries, static fn (string $entry): bool => $entry !== ''));
    }

    private static function ipMatches(string $ip, string $rule): bool
    {
        $rule = trim($rule);
        if ($rule === '') {
            return false;
        }

        if (!str_contains($rule, '/')) {
            return $ip === $rule;
        }

        [$subnet, $prefixLength] = explode('/', $rule, 2);
        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);
        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $prefix = (int) $prefixLength;
        $maxBits = strlen($ipBinary) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($subnetBinary[$fullBytes]) & $mask);
    }
}
