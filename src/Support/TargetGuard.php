<?php

declare(strict_types=1);

namespace Pluck\Support;

use Closure;
use Pluck\Contracts\TargetValidator;

/**
 * SSRF guard for direct fetches (PROXY_CONTRACT §6 target validation; the
 * donors' GuardedClient/IpClassifier semantics): public http(s) targets only,
 * no credentials, every resolved A/AAAA address must be public, and the vetted
 * address is returned so the connection can be pinned to it (no DNS rebinding
 * between check and connect).
 *
 * Written against the documented behaviour; NutriCompare's source was not
 * available when this was built (see docs/BUILD_NOTES.md).
 */
final class TargetGuard implements TargetValidator
{
    /** Ranges PHP's FILTER_FLAG_NO_PRIV/RES_RANGE does not (reliably) cover. */
    private const EXTRA_BLOCKED_CIDRS = [
        '0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /** @var Closure(string):list<string> */
    private readonly Closure $resolver;

    /** @param null|callable(string):list<string> $resolver host => IP list; defaults to real DNS (A + AAAA) */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver !== null ? Closure::fromCallable($resolver) : self::dnsResolver(...);
    }

    /**
     * @return array{scheme:string,host:string,port:int,ip:string} the vetted, pinnable target
     * @throws BlockedTarget
     */
    public function vet(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'], $parts['scheme'])) {
            throw new BlockedTarget('Not an absolute URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new BlockedTarget("Scheme '{$scheme}' is not allowed.");
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BlockedTarget('Credentials in the URL are not allowed.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            throw new BlockedTarget("Host '{$host}' is not public.");
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        if ($ips === []) {
            throw new BlockedTarget("Host '{$host}' did not resolve.");
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new BlockedTarget("Host '{$host}' resolves to a non-public address.");
            }
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        // IPv4-mapped IPv6 (::ffff:a.b.c.d): judge the embedded IPv4 address.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return self::isPublicIp(inet_ntop(substr($packed, 12)));
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::EXTRA_BLOCKED_CIDRS as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $packedIp, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $packedNet = inet_pton($net);
        if ($packedNet === false || strlen($packedNet) !== strlen($packedIp)) {
            return false;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        if (substr($packedIp, 0, $fullBytes) !== substr($packedNet, 0, $fullBytes)) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($packedIp[$fullBytes]) & $mask) === (ord($packedNet[$fullBytes]) & $mask);
    }

    /** @return list<string> */
    private static function dnsResolver(string $host): array
    {
        $ips = [];
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            $records = @dns_get_record($host, $type);
            foreach (is_array($records) ? $records : [] as $record) {
                if (isset($record[$field]) && is_string($record[$field])) {
                    $ips[] = $record[$field];
                }
            }
        }

        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            $ips = is_array($v4) ? $v4 : [];
        }

        return array_values(array_unique($ips));
    }
}
