<?php

namespace App\Support\Feeds;

/**
 * Decides whether Offing may fetch a URL a user gave it (SSRF protection,
 * spec §13/§21). A subscription URL is attacker-controlled input that our
 * server fetches on a timer, so:
 *
 * - only http/https (webcal:// is just https by another name);
 * - no user:password@ (we never send credentials anywhere);
 * - only the usual web ports;
 * - a hostname must be a real DNS name (no "2130706433" or "0x7f.1" tricks);
 * - every address the name resolves to must be public — not private,
 *   loopback, link-local, CGNAT, multicast, reserved or a cloud metadata
 *   address. The fetcher then connects to the vetted address itself, so a
 *   second DNS answer can't swap in an internal one (rebinding), and it
 *   runs these checks again on every redirect hop.
 *
 * `offing.feeds.allow_private_hosts` turns the address and port checks off
 * for local testing against a fixture server. It defaults off and must
 * never be on in production.
 */
final class UrlGuard
{
    public const PORTS = [80, 443, 8080, 8443];

    /** IPv4 ranges that are never fetched. */
    private const DENY_V4 = [
        '0.0.0.0/8',          // "this network"
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // carrier-grade NAT (and Alibaba's metadata 100.100.100.200)
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, incl. 169.254.169.254 metadata
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // documentation
        '192.88.99.0/24',     // 6to4 relay anycast
        '192.168.0.0/16',     // private
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // documentation
        '203.0.113.0/24',     // documentation
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, incl. broadcast
    ];

    /** IPv6: only global unicast (2000::/3) is allowed, minus these. */
    private const DENY_V6 = [
        '2001::/23',          // IETF protocol assignments, incl. Teredo
        '2001:db8::/32',      // documentation
        '2002::/16',          // 6to4 — embeds an IPv4 address we'd have to vet
        '3fff::/20',          // documentation
    ];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * webcal:// → https://, trimmed. Doesn't validate.
     */
    public static function normalise(string $url): string
    {
        $url = trim($url);

        return preg_replace('#^webcals?://#i', 'https://', $url) ?? $url;
    }

    /**
     * Checks a URL and resolves its host. `ip` is the vetted address to
     * connect to — null when the host is already an IP literal.
     *
     * @return array{url: string, scheme: string, host: string, port: int, ip: string|null}
     *
     * @throws FeedException when it may not be fetched
     */
    public function check(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new FeedException('That isn’t a web address.');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new FeedException('Only http, https and webcal addresses can be subscribed to.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new FeedException('Addresses with a username or password in them aren’t supported.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $allowPrivate = (bool) config('offing.feeds.allow_private_hosts');

        if (! $allowPrivate && ! in_array($port, self::PORTS, true)) {
            throw new FeedException('That address uses a port Offing doesn’t fetch from.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if ($literal) {
            if (! $allowPrivate && ! self::isPublic($host)) {
                throw new FeedException('That address points at a private network.');
            }

            return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => null];
        }

        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $host) !== 1) {
            throw new FeedException('That address’s server name isn’t valid.');
        }

        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            throw new FeedException('Couldn’t find that server — check the address.');
        }

        if (! $allowPrivate) {
            foreach ($ips as $ip) {
                if (! self::isPublic($ip)) {
                    throw new FeedException('That address points at a private network.');
                }
            }
        }

        return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /** True for an address on the public internet. */
    public static function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::a.b.c.d) carry an IPv4 address.
            if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff") || str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\0", 8))) {
                return self::isPublic((string) inet_ntop(substr($packed, 12)));
            }

            if (! self::inCidr($packed, '2000::/3')) {
                return false;
            }

            foreach (self::DENY_V6 as $cidr) {
                if (self::inCidr($packed, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        foreach (self::DENY_V4 as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $net = (string) inet_pton($network);

        if (strlen($net) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
