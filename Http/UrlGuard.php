<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * SSRF guard for Plex server URLs. Ported from AiConnector's UrlGuard (same CIDR tables, numeric-shorthand
 * refusal, allow-list syntax and resolver seam), with the spec §11 amendments:
 *
 *  - S1: there is no blanket private opt-in. A private address passes only when an admin "private target"
 *    matches it: a hostname (matched by name, before resolution), an IP literal or a CIDR, each with an optional
 *    `:port` (IPv6 as `[addr]:port` or `[cidr]:port`).
 *  - A hostname target trusts that name's DNS: whatever private address it resolves to is admitted, except
 *    loopback (127.0.0.0/8, ::1, and those carried in a mapped, SIIT, NAT64 or 6to4 address). Loopback needs an
 *    IP or CIDR target that covers the resolved address.
 *  - A CIDR target is /8 or longer for IPv4 and /16 or longer for IPv6; a shorter prefix is invalid and matches
 *    nothing. So the whole ULA range fc00::/7 cannot be one target: name each /16 (or narrower) instead.
 *  - Ports: 32400, 443 and 80 only, unless the matching target names another port; then that port only.
 *    Public addresses get the three default ports and nothing else.
 *  - S8: an IPv4 address embedded in ::ffff:0:0/96, ::ffff:0:0:0/96, 64:ff9b::/96 or 64:ff9b:1::/48 (and
 *    6to4) is judged as that IPv4. fec0::/10 is blocked. In 64:ff9b:1::/48 the IPv4 is the last 4 bytes only
 *    when bytes 6–11 are zero; any other address in that prefix is blocked. IPv4 targets cover the mapped,
 *    SIIT and NAT64 forms of their addresses, not the 6to4 form (a 6to4 address routes through a relay, not
 *    to the IPv4 host): a 6to4 address needs an IPv6 target such as 2002::/16. An IPv6 CIDR target over
 *    mapped, SIIT or NAT64 space must be /104 or longer, so it cannot admit all of IPv4 in that form.
 *
 * Every resolved address must pass. check() returns the address to pin the connection to; the transport runs
 * it on every request, so a DNS answer that changes between requests is judged afresh.
 *
 * @package Kanboard\Plugin\Matinee\Http
 */
final class UrlGuard
{
    public const ALLOWED_HOSTS_KEY   = 'matinee_allowed_hosts';
    public const PRIVATE_TARGETS_KEY = 'matinee_private_targets';

    /** Ports allowed without a target that names another one. */
    public const DEFAULT_PORTS = [32400, 443, 80];

    /** Private networks: allowed only through a matching private target. */
    private const PRIVATE_CIDRS = [
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '100.64.0.0/10',
        '198.18.0.0/15', '::1/128', 'fc00::/7',
    ];

    /**
     * Always refused: link-local, cloud metadata, unspecified, multicast, reserved, deprecated site-local, and
     * the 64:ff9b:1::/48 addresses that are not in its embedded-IPv4 form (that form is judged as the IPv4).
     */
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8', '169.254.0.0/16', '100.100.100.200/32', '192.0.0.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', 'fe80::/10', 'fec0::/10', 'fd00:ec2::254/128', 'ff00::/8', '2001::/32', '::/96', '64:ff9b:1::/48',
    ];

    /**
     * IPv6 /96 prefixes whose last 32 bits are an IPv4 address: mapped, SIIT, NAT64 and the embedded form of
     * local NAT64. An IPv6 CIDR target overlapping one must be /104 or longer (an IPv4 /8 at most), or it would
     * be a blanket opt-in for every private IPv4 in that form.
     */
    private const EMBEDDED_IPV4_PREFIXES = ['::ffff:0:0', '::ffff:0:0:0', '64:ff9b::', '64:ff9b:1::'];

    /** Shortest CIDR prefix a private target may use, by address length in bytes. */
    private const MIN_TARGET_BITS = [4 => 8, 16 => 16];

    /** @var (callable(string): string[])|null  Test seam replacing DNS. */
    private static $resolver = null;

    /** @var string[] */
    private array $allowedHosts;

    /** @var list<array{host:?string, net:?string, bits:int, port:?int}> */
    private array $targets = [];

    /**
     * @param string[] $allowedHosts   exact hosts / IPs, or "*.domain"; empty = any host
     * @param string[] $privateTargets host, IP or CIDR entries with an optional port; invalid entries match nothing
     */
    public function __construct(array $allowedHosts = [], array $privateTargets = [])
    {
        $this->allowedHosts = self::parseHostList(implode("\n", $allowedHosts));
        foreach (self::parseTargetList(implode("\n", $privateTargets)) as $entry) {
            $target = self::parseTarget($entry);
            if ($target !== null) {
                $this->targets[] = $target;
            }
        }
    }

    public static function fromConfig(object $configModel): self
    {
        return new self(
            self::parseHostList((string) $configModel->get(self::ALLOWED_HOSTS_KEY, '')),
            self::parseTargetList((string) $configModel->get(self::PRIVATE_TARGETS_KEY, ''))
        );
    }

    /** Replace DNS resolution in tests; null restores the real resolver. */
    public static function setResolverForTesting(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Check $url and return the host, the effective port and the address to pin the connection to.
     *
     * @return array{host:string, port:int, ip:string}
     * @throws UnsafeUrlException
     */
    public function check(string $url): array
    {
        if (! self::isValidBaseUrl($url)) {
            throw new UnsafeUrlException(UnsafeUrlException::INVALID_URL);
        }
        $parts = (array) parse_url($url);
        $host  = self::normaliseHost((string) $parts['host']);
        $port  = isset($parts['port'])
            ? (int) $parts['port']
            : (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80);

        if ($this->allowedHosts !== [] && ! $this->hostAllowed($host)) {
            throw new UnsafeUrlException(UnsafeUrlException::HOST_NOT_ALLOWED, $host);
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            throw new UnsafeUrlException(UnsafeUrlException::UNRESOLVABLE, $host);
        }
        foreach ($ips as $ip) {
            $bin   = @inet_pton($ip);
            $class = $bin === false ? 'blocked' : self::classify($bin);
            if ($class === 'blocked') {
                throw new UnsafeUrlException(UnsafeUrlException::BLOCKED_ADDRESS, $host);
            }
            if ($class === 'public') {
                if (! in_array($port, self::DEFAULT_PORTS, true)) {
                    throw new UnsafeUrlException(UnsafeUrlException::PORT_NOT_ALLOWED, $host, $port);
                }
                continue;
            }
            $matching = $this->matchingTargets($host, $bin);
            if ($matching === []) {
                throw new UnsafeUrlException(UnsafeUrlException::PRIVATE_NETWORK, $host);
            }
            if (! self::anyTargetAllowsPort($matching, $port)) {
                throw new UnsafeUrlException(UnsafeUrlException::PORT_NOT_ALLOWED, $host, $port);
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /**
     * A Plex base URL: http or https, a host (not numeric shorthand), an optional port 1–65535, and an empty or
     * "/" path. No userinfo, query or fragment. The port is plain decimal digits with no sign and no leading zero,
     * so each port has one spelling (":080" and ":+80" are refused, not read as 80).
     */
    public static function isValidBaseUrl(string $url): bool
    {
        // '?', '#' and '@' are refused outright, even empty: parse_url is not the only parser that will see this.
        if ($url === '' || preg_match('/[\s\x00-\x1f\x7f\\\\@?#]/', $url) === 1) {
            return false;
        }
        // The whole shape, so nothing parse_url tolerates (a signed or zero-padded port) slips through.
        if (preg_match('#^https?://(\[[^\]]+\]|[^:/\[\]]+)(:[1-9][0-9]{0,4})?/?$#i', $url) !== 1) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))
        ) {
            return false;
        }
        $raw = (string) $parts['host'];
        if (str_starts_with($raw, '[')) {
            return str_ends_with($raw, ']')
                && filter_var(substr($raw, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        $host = self::normaliseHost($raw);
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }
        return ! self::isNumericShorthand($host)
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /**
     * The stored form of a base URL: `scheme://host[:port]`, lowercase, no trailing dot or slash, IPv6 in its
     * canonical bracketed form. An explicit port is kept even when it is the scheme's default, so the stored URL
     * says what the owner typed (it is bound into the token's AD, spec §11 S7). Idempotent.
     *
     * @throws UnsafeUrlException for an invalid URL
     */
    public static function normaliseBaseUrl(string $url): string
    {
        if (! self::isValidBaseUrl($url)) {
            throw new UnsafeUrlException(UnsafeUrlException::INVALID_URL);
        }
        $parts = (array) parse_url($url);
        $host  = self::normaliseHost((string) $parts['host']);
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . inet_ntop((string) inet_pton($host)) . ']';
        }
        return strtolower((string) $parts['scheme']) . '://' . $host
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    /**
     * Split a textarea / comma list into normalised, de-duplicated host allow-list entries.
     *
     * @return string[]
     */
    public static function parseHostList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $entry) {
            $entry = self::normaliseHost($entry);
            if ($entry !== '' && ! in_array($entry, $out, true)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /** A host allow-list entry: hostname, IP literal, or "*.domain". */
    public static function isValidHostEntry(string $entry): bool
    {
        $entry = self::normaliseHost($entry);
        if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (str_starts_with($entry, '*.')) {
            $entry = substr($entry, 2);
        }
        return $entry !== ''
            && ! str_contains($entry, '*')
            && filter_var($entry, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /**
     * Split a textarea / comma list into lowercased, de-duplicated private-target entries. Entries are not
     * validated here (a settings form reports bad ones with isValidTargetEntry); the guard ignores invalid ones.
     *
     * @return string[]
     */
    public static function parseTargetList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry !== '' && ! in_array($entry, $out, true)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /** A private target: hostname, IP literal or CIDR, with an optional ":port" ("[v6]:port" for IPv6). */
    public static function isValidTargetEntry(string $entry): bool
    {
        return self::parseTarget(strtolower(trim($entry))) !== null;
    }

    // ── internals ────────────────────────────────────────────────────────────────

    /** @return array{host:?string, net:?string, bits:int, port:?int}|null */
    private static function parseTarget(string $entry): ?array
    {
        $port = null;
        if (preg_match('/^\[([^\]]+)\](?::([^:]*))?$/', $entry, $m) === 1) {
            if (! str_contains($m[1], ':')) {
                return null; // brackets are for IPv6 only
            }
            $addr = $m[1];
            $port = $m[2] ?? null;
        } elseif (substr_count($entry, ':') === 1) {
            [$addr, $port] = explode(':', $entry);
        } else {
            $addr = $entry; // a name, an IPv4 entry, or a bare IPv6 entry (no port)
        }

        if ($port !== null) {
            if (preg_match('/^[0-9]{1,5}$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
                return null;
            }
            $port = (int) $port;
        }

        if (str_contains($addr, '/')) {
            [$net, $bits] = explode('/', $addr, 2);
            $netBin = filter_var($net, FILTER_VALIDATE_IP) !== false ? inet_pton($net) : false;
            if ($netBin === false || preg_match('/^[0-9]{1,3}$/', $bits) !== 1 || (int) $bits > 8 * strlen($netBin)
                || (int) $bits < self::MIN_TARGET_BITS[strlen($netBin)]
                || (strlen($netBin) === 16 && (int) $bits < 104 && self::overlapsEmbeddedIpv4($netBin, (int) $bits))
            ) {
                return null;
            }
            return ['host' => null, 'net' => $netBin, 'bits' => (int) $bits, 'port' => $port];
        }
        if (filter_var($addr, FILTER_VALIDATE_IP) !== false) {
            $bin = (string) inet_pton($addr);
            return ['host' => null, 'net' => $bin, 'bits' => 8 * strlen($bin), 'port' => $port];
        }
        $host = self::normaliseHost($addr);
        if ($host === '' || str_contains($host, '*') || self::isNumericShorthand($host)
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            return null;
        }
        return ['host' => $host, 'net' => null, 'bits' => 0, 'port' => $port];
    }

    /**
     * Targets that match $host by name or the address by IP / CIDR. The embedded IPv4 of a mapped, SIIT or NAT64
     * address counts too, so "10.0.0.0/8" covers "::ffff:10.0.0.1"; a 6to4 address is matched only as itself.
     * A name target never matches a loopback address.
     *
     * @return list<array{host:?string, net:?string, bits:int, port:?int}>
     */
    private function matchingTargets(string $host, string $bin): array
    {
        $forms = [$bin];
        $translated = self::translatedIpv4($bin);
        if ($translated !== null) {
            $forms[] = $translated;
        }
        $loopback = self::isLoopback($bin);
        $out = [];
        foreach ($this->targets as $target) {
            if ($target['host'] !== null) {
                if ($target['host'] === $host && ! $loopback) {
                    $out[] = $target;
                }
                continue;
            }
            foreach ($forms as $form) {
                if (self::inCidr($form, (string) $target['net'], $target['bits'])) {
                    $out[] = $target;
                    break;
                }
            }
        }
        return $out;
    }

    /** True when the IPv6 CIDR $netBin/$bits contains, or lies inside, one of the embedded-IPv4 /96 prefixes. */
    private static function overlapsEmbeddedIpv4(string $netBin, int $bits): bool
    {
        foreach (self::EMBEDDED_IPV4_PREFIXES as $prefix) {
            if (self::inCidr((string) inet_pton($prefix), $netBin, min($bits, 96))) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array{host:?string, net:?string, bits:int, port:?int}> $targets */
    private static function anyTargetAllowsPort(array $targets, int $port): bool
    {
        foreach ($targets as $target) {
            if ($target['port'] !== null ? $target['port'] === $port : in_array($port, self::DEFAULT_PORTS, true)) {
                return true;
            }
        }
        return false;
    }

    private static function normaliseHost(string $host): string
    {
        return rtrim(strtolower(trim(trim($host), '[]')), '.');
    }

    /** 2130706433, 0x7f.1, 0177.0.0.1, 127.1 — numeric but not a canonical IP. */
    private static function isNumericShorthand(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        foreach (explode('.', $host) as $label) {
            if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/i', $label) !== 1) {
                return false;
            }
        }
        return true;
    }

    private function hostAllowed(string $host): bool
    {
        foreach ($this->allowedHosts as $entry) {
            if ($entry === $host) {
                return true;
            }
            if (str_starts_with($entry, '*.') && str_ends_with($host, substr($entry, 1))) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        if (self::$resolver !== null) {
            return array_values(array_map('strval', (self::$resolver)($host)));
        }
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        return array_values(array_unique($ips));
    }

    /** The 4 bytes of the IPv4 address an IPv6 one translates to (mapped, SIIT, NAT64), or null. */
    private static function translatedIpv4(string $bin): ?string
    {
        if (strlen($bin) !== 16) {
            return null;
        }
        return match (true) {
            str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")                       => substr($bin, 12, 4), // ::ffff:0:0/96 mapped
            str_starts_with($bin, str_repeat("\0", 8) . "\xff\xff\0\0")                    => substr($bin, 12, 4), // ::ffff:0:0:0/96 SIIT
            str_starts_with($bin, "\x00\x64\xff\x9b" . str_repeat("\0", 8))                => substr($bin, 12, 4), // 64:ff9b::/96 NAT64
            str_starts_with($bin, "\x00\x64\xff\x9b\x00\x01" . str_repeat("\0", 6))      => substr($bin, 12, 4), // 64:ff9b:1::/48 local NAT64
            default                                                                        => null,
        };
    }

    /** The 4 bytes of an IPv4 address carried inside an IPv6 one (translated, or 6to4 2002::/16), or null. */
    private static function embeddedIpv4(string $bin): ?string
    {
        if (strlen($bin) === 16 && str_starts_with($bin, "\x20\x02")) {
            return substr($bin, 2, 4);
        }
        return self::translatedIpv4($bin);
    }

    /** 127.0.0.0/8 or ::1, directly or carried in an IPv6 address. */
    private static function isLoopback(string $bin): bool
    {
        $addr = self::embeddedIpv4($bin) ?? $bin;
        return $addr === inet_pton('::1') || self::inCidr($addr, (string) inet_pton('127.0.0.0'), 8);
    }

    /** 'public' | 'private' | 'blocked' for a packed address. */
    private static function classify(string $bin): string
    {
        $embedded = self::embeddedIpv4($bin);
        if ($embedded !== null) {
            return self::classify($embedded);
        }
        if ($bin === inet_pton('::1')) {
            return 'private'; // loopback; the rest of ::/96 is deprecated and blocked
        }
        if (self::inAny($bin, self::BLOCKED_CIDRS)) {
            return 'blocked';
        }
        return self::inAny($bin, self::PRIVATE_CIDRS) ? 'private' : 'public';
    }

    /** @param string[] $cidrs */
    private static function inAny(string $bin, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            if (self::inCidr($bin, (string) inet_pton($net), (int) $bits)) {
                return true;
            }
        }
        return false;
    }

    private static function inCidr(string $bin, string $netBin, int $bits): bool
    {
        if (strlen($netBin) !== strlen($bin)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rem)) & 0xff;
        return (ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}
