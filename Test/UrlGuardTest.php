<?php

use Kanboard\Plugin\Matinee\Http\UnsafeUrlException;
use Kanboard\Plugin\Matinee\Http\UrlGuard;
use PHPUnit\Framework\TestCase;

/**
 * SSRF guard for Plex server URLs (spec §3, §11 S1 and S8, §12). No network: DNS goes through the resolver
 * seam, or the IP-literal fast path.
 */
class UrlGuardTest extends TestCase
{
    private const PUBLIC_IP = '93.184.216.34';

    protected function tearDown(): void
    {
        UrlGuard::setResolverForTesting(null);
    }

    /** @param array<string, string[]> $map */
    private function dns(array $map): void
    {
        UrlGuard::setResolverForTesting(fn (string $host): array => $map[$host] ?? []);
    }

    private function assertRefused(UrlGuard $guard, string $url, string $reason): UnsafeUrlException
    {
        try {
            $guard->check($url);
        } catch (UnsafeUrlException $e) {
            $this->assertSame($reason, $e->reason, $url);
            return $e;
        }
        $this->fail("expected $url to be refused ($reason)");
    }

    // ── public hosts and ports ───────────────────────────────────────────────

    /** @dataProvider publicPasses */
    public function testPublicHostPasses(string $url, array $expected): void
    {
        $this->dns(['plex.example.test' => [self::PUBLIC_IP]]);
        $this->assertSame($expected, (new UrlGuard())->check($url));
    }

    public static function publicPasses(): array
    {
        $ip = self::PUBLIC_IP;
        return [
            'plex port'          => ['https://plex.example.test:32400', ['host' => 'plex.example.test', 'port' => 32400, 'ip' => $ip]],
            'https default port' => ['https://plex.example.test/', ['host' => 'plex.example.test', 'port' => 443, 'ip' => $ip]],
            'http default port'  => ['http://plex.example.test', ['host' => 'plex.example.test', 'port' => 80, 'ip' => $ip]],
            'explicit 443'       => ['https://plex.example.test:443', ['host' => 'plex.example.test', 'port' => 443, 'ip' => $ip]],
            'host case and dot'  => ['https://PLEX.Example.Test.:32400', ['host' => 'plex.example.test', 'port' => 32400, 'ip' => $ip]],
            'ipv4 literal'       => ['http://8.8.8.8:32400', ['host' => '8.8.8.8', 'port' => 32400, 'ip' => '8.8.8.8']],
            'ipv6 literal'       => ['https://[2606:4700::1]:32400', ['host' => '2606:4700::1', 'port' => 32400, 'ip' => '2606:4700::1']],
        ];
    }

    /** @dataProvider publicBadPorts */
    public function testPublicHostOnAnotherPortIsRefused(string $url): void
    {
        $this->dns(['plex.example.test' => [self::PUBLIC_IP]]);
        $this->assertRefused(new UrlGuard(), $url, UnsafeUrlException::PORT_NOT_ALLOWED);
        // A private target naming the port does not open it on a public address.
        $this->assertRefused(new UrlGuard([], ['plex.example.test:22', 'plex.example.test:8080']), $url, UnsafeUrlException::PORT_NOT_ALLOWED);
    }

    public static function publicBadPorts(): array
    {
        return [
            'ssh'  => ['https://plex.example.test:22'],
            '8080' => ['http://plex.example.test:8080'],
            '1'    => ['http://plex.example.test:1'],
        ];
    }

    // ── private networks need a matching target (S1) ─────────────────────────

    /** @dataProvider privateAddresses */
    public function testPrivateAddressIsRefusedWithoutATarget(string $ip, string $cidr, bool $loopback = false): void
    {
        $this->dns(['plex.lan' => [$ip]]);
        $this->assertRefused(new UrlGuard(), 'http://plex.lan:32400', UnsafeUrlException::PRIVATE_NETWORK);
        // A target for some other network does not help.
        $this->assertRefused(new UrlGuard([], ['other.lan', '203.0.113.0/24', '198.51.100.7']), 'http://plex.lan:32400', UnsafeUrlException::PRIVATE_NETWORK);
    }

    /** @dataProvider privateAddresses */
    public function testPrivateAddressPassesWithAMatchingTarget(string $ip, string $cidr, bool $loopback = false): void
    {
        $this->dns(['plex.lan' => [$ip]]);
        foreach (self::matchingTargets($ip, $cidr, $loopback) as $kind => $target) {
            $expected = ['host' => 'plex.lan', 'port' => 32400, 'ip' => $ip];
            $this->assertSame($expected, (new UrlGuard([], [$target]))->check('http://plex.lan:32400'), "$kind target $target");
            $this->assertSame(443, (new UrlGuard([], [$target]))->check('https://plex.lan')['port'], "$kind target $target");
        }
    }

    /** @dataProvider privateAddresses */
    public function testPrivateAddressIsRefusedOnAPortTheTargetDoesNotName(string $ip, string $cidr, bool $loopback = false): void
    {
        $this->dns(['plex.lan' => [$ip]]);
        foreach (self::matchingTargets($ip, $cidr, $loopback) as $kind => $target) {
            // No port in the target: only 32400, 443 and 80.
            $this->assertRefused(new UrlGuard([], [$target]), 'http://plex.lan:22', UnsafeUrlException::PORT_NOT_ALLOWED);
            // A port in the target: that port only.
            $withPort = str_contains($target, ':') && ! str_starts_with($target, '[') ? '[' . $target . ']:8443' : $target . ':8443';
            $guard = new UrlGuard([], [$withPort]);
            $this->assertSame(8443, $guard->check('https://plex.lan:8443')['port'], "$kind target $withPort");
            $this->assertRefused($guard, 'http://plex.lan:32400', UnsafeUrlException::PORT_NOT_ALLOWED);
            $this->assertRefused($guard, 'https://plex.lan', UnsafeUrlException::PORT_NOT_ALLOWED);
        }
    }

    /**
     * The targets that authorise $ip for plex.lan: the name, the IP literal and the CIDR. A name does not
     * authorise loopback, so a loopback address gets only the IP and CIDR targets.
     *
     * @return array<string, string>
     */
    private static function matchingTargets(string $ip, string $cidr, bool $loopback): array
    {
        $literal = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $targets = ['host' => 'plex.lan', 'ip' => $literal, 'cidr' => $cidr];
        if ($loopback) {
            unset($targets['host']);
        }
        return $targets;
    }

    /** [resolved address, a CIDR target containing it, is loopback] */
    public static function privateAddresses(): array
    {
        return [
            'loopback v4'           => ['127.0.0.1', '127.0.0.0/8', true],
            'loopback v4 range'     => ['127.1.2.3', '127.0.0.0/8', true],
            '10/8'                  => ['10.0.0.5', '10.0.0.0/8'],
            '172.16/12'             => ['172.20.1.1', '172.16.0.0/12'],
            '192.168/16'            => ['192.168.1.20', '192.168.1.0/24'],
            'CGNAT'                 => ['100.64.0.10', '100.64.0.0/10'],
            'loopback v6'           => ['::1', '::1/128', true],
            'ULA v6'                => ['fd12:3456::1', 'fd12:3456::/32'],
            'ULA v6 by /16'         => ['fd12:3456::1', 'fd12::/16'],
            'v4-mapped private'     => ['::ffff:10.0.0.1', '10.0.0.0/8'],
            'v4-mapped hex form'    => ['::ffff:a00:1', '10.0.0.0/8'],
            'v4-mapped long form'   => ['0:0:0:0:0:FFFF:10.0.0.1', '10.0.0.0/8'],
            'v4-translated (SIIT)'  => ['::ffff:0:10.0.0.1', '10.0.0.0/8'],
            'NAT64 private'         => ['64:ff9b::a00:1', '10.0.0.0/8'],
            'NAT64 local-use'       => ['64:ff9b:1::a00:1', '10.0.0.0/8'],
        ];
    }

    /** @dataProvider loopbackAddresses */
    public function testANameTargetDoesNotAuthoriseLoopback(string $ip, string $ipTarget): void
    {
        $this->dns(['plex.lan' => [$ip]]);
        $this->assertRefused(new UrlGuard([], ['plex.lan']), 'http://plex.lan:32400', UnsafeUrlException::PRIVATE_NETWORK);
        $this->assertRefused(new UrlGuard([], ['plex.lan:32400']), 'http://plex.lan:32400', UnsafeUrlException::PRIVATE_NETWORK);
        // An IP or CIDR target that covers the address does.
        $this->assertSame($ip, (new UrlGuard([], ['plex.lan', $ipTarget]))->check('http://plex.lan:32400')['ip']);
    }

    public static function loopbackAddresses(): array
    {
        return [
            'v4'                => ['127.0.0.1', '127.0.0.1'],
            'v4 range'          => ['127.1.2.3', '127.0.0.0/8'],
            'v6'                => ['::1', '[::1]'],
            'v4-mapped'         => ['::ffff:127.0.0.1', '127.0.0.0/8'],
            'v4-translated'     => ['::ffff:0:127.0.0.1', '127.0.0.0/8'],
            'NAT64'             => ['64:ff9b::7f00:1', '127.0.0.0/8'],
            'NAT64 local-use'   => ['64:ff9b:1::7f00:1', '127.0.0.0/8'],
            '6to4'              => ['2002:7f00:1::1', '2002::/16'],
        ];
    }

    public function testANameTargetStillAuthorisesOtherPrivateAddresses(): void
    {
        $this->dns(['plex.lan' => ['10.0.0.5']]);
        $this->assertSame('10.0.0.5', (new UrlGuard([], ['plex.lan']))->check('http://plex.lan:32400')['ip']);
    }

    public function testInvalidTargetEntriesMatchNothing(): void
    {
        $this->dns(['plex.lan' => ['10.0.0.5'], 'plex6.lan' => ['fd12::5']]);
        $guard = new UrlGuard([], [
            '10.0.0.0/33', '10.0.0.5:0', '10.0.0.5:70000', '*.lan', 'http://10.0.0.5',
            '0.0.0.0/0', '::/0', '10.0.0.0/7', '::/15', 'fc00::/7',
        ]);
        $this->assertRefused($guard, 'http://plex.lan:32400', UnsafeUrlException::PRIVATE_NETWORK);
        $this->assertRefused($guard, 'http://plex6.lan:32400', UnsafeUrlException::PRIVATE_NETWORK);
    }

    public function testA6to4AddressIsNotMatchedByItsEmbeddedIpv4(): void
    {
        // 2002:a00:1:: carries 10.0.0.1: judged private by it, but a 10/8 target does not cover the 6to4 address.
        $this->assertRefused(new UrlGuard([], ['10.0.0.0/8']), 'http://[2002:a00:1::]', UnsafeUrlException::PRIVATE_NETWORK);
        $this->assertSame('2002:a00:1::', (new UrlGuard([], ['2002::/16']))->check('http://[2002:a00:1::]')['ip']);
    }

    // ── always blocked, targets or not (S8) ───────────────────────────────────

    /**
     * @dataProvider blockedAddresses
     * @param string[] $covering valid targets whose networks contain the address (or its embedded IPv4)
     */
    public function testBlockedAddressIsRefusedEvenWithAMatchingTarget(string $ip, array $covering): void
    {
        $this->dns(['evil.example' => [$ip]]);
        $literal = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $everything = array_merge(['evil.example', $literal], $covering);
        foreach ($covering as $target) {
            $this->assertTrue(UrlGuard::isValidTargetEntry($target), $target);
        }
        $this->assertRefused(new UrlGuard(), 'http://evil.example:32400', UnsafeUrlException::BLOCKED_ADDRESS);
        $this->assertRefused(new UrlGuard([], $everything), 'http://evil.example:32400', UnsafeUrlException::BLOCKED_ADDRESS);
    }

    public static function blockedAddresses(): array
    {
        return [
            'cloud metadata v4'        => ['169.254.169.254', ['169.254.0.0/16']],
            'link-local v4'            => ['169.254.10.1', ['169.254.0.0/16']],
            'Alibaba metadata'         => ['100.100.100.200', ['100.64.0.0/10']],
            'this network'             => ['0.0.0.0', ['0.0.0.0/8']],
            'multicast v4'             => ['224.0.0.1', ['224.0.0.0/8']],
            'broadcast'                => ['255.255.255.255', ['255.0.0.0/8']],
            'unspecified v6'           => ['::', ['::/16']],
            'link-local v6'            => ['fe80::1', ['fe80::/16']],
            'site-local v6 (fec0::/10)' => ['fec0::1', ['fec0::/16']],
            'multicast v6'             => ['ff02::1', ['ff02::/16']],
            'AWS metadata v6'          => ['fd00:ec2::254', ['fd00::/16']],
            'v4-mapped metadata'       => ['::ffff:169.254.169.254', ['169.254.0.0/16', '::ffff:0:0/96']],
            'v4-translated metadata'   => ['::ffff:0:169.254.169.254', ['169.254.0.0/16', '::ffff:0:0:0/96']],
            'NAT64 metadata'           => ['64:ff9b::a9fe:a9fe', ['169.254.0.0/16', '64:ff9b::/96']],
            'NAT64 local-use metadata' => ['64:ff9b:1::a9fe:a9fe', ['169.254.0.0/16', '64:ff9b:1::/48']],
            'NAT64 local-use, non-zero middle' => ['64:ff9b:1:a00:0:100:808:808', ['10.0.0.0/8', '8.0.0.0/8', '64:ff9b:1::/48']],
            '6to4 metadata'            => ['2002:a9fe:a9fe::1', ['169.254.0.0/16', '2002::/16']],
            'unparseable answer'       => ['not-an-ip', ['10.0.0.0/8']],
        ];
    }

    // ── every address, every request ─────────────────────────────────────────

    public function testEveryResolvedAddressMustPass(): void
    {
        $this->dns(['mixed.example' => [self::PUBLIC_IP, '10.0.0.1']]);
        $this->assertRefused(new UrlGuard(), 'https://mixed.example', UnsafeUrlException::PRIVATE_NETWORK);
        $this->assertSame(self::PUBLIC_IP, (new UrlGuard([], ['10.0.0.0/8']))->check('https://mixed.example')['ip']);

        $this->dns(['mixed.example' => ['10.0.0.1', '169.254.169.254']]);
        $this->assertRefused(new UrlGuard([], ['mixed.example']), 'https://mixed.example', UnsafeUrlException::BLOCKED_ADDRESS);
    }

    public function testResolverRebindingToPrivateIsCaughtOnTheNextCheck(): void
    {
        $answers = [[self::PUBLIC_IP], ['10.0.0.1']];
        UrlGuard::setResolverForTesting(function (string $host) use (&$answers): array {
            return array_shift($answers) ?? [];
        });
        $guard = new UrlGuard();
        $this->assertSame(self::PUBLIC_IP, $guard->check('https://plex.example.test:32400')['ip']);
        $this->assertRefused($guard, 'https://plex.example.test:32400', UnsafeUrlException::PRIVATE_NETWORK);
    }

    public function testUnresolvableHostIsRefused(): void
    {
        $this->dns([]);
        $this->assertRefused(new UrlGuard(), 'https://nowhere.invalid', UnsafeUrlException::UNRESOLVABLE);
    }

    public function testLiteralsSkipDns(): void
    {
        UrlGuard::setResolverForTesting(function (): array {
            throw new \LogicException('DNS must not be asked about a literal');
        });
        $this->assertSame('10.0.0.5', (new UrlGuard([], ['10.0.0.5']))->check('http://10.0.0.5:32400')['ip']);
        $this->assertSame('::1', (new UrlGuard([], ['[::1]:32400']))->check('http://[::1]:32400')['ip']);
    }

    // ── base URL shape ──────────────────────────────────────────────────────────

    /** @dataProvider invalidUrls */
    public function testInvalidUrlIsRefused(string $url): void
    {
        $this->dns(['plex.example.test' => [self::PUBLIC_IP], 'cafe.be' => [self::PUBLIC_IP]]);
        $this->assertFalse(UrlGuard::isValidBaseUrl($url), $url);
        // Targets covering every host these URLs name do not make an invalid URL valid.
        $targets = ['plex.example.test', 'cafe.be', '127.0.0.0/8', '10.0.0.0/8', '192.168.0.0/16', '[::1]', 'fd12::/16'];
        $this->assertRefused(new UrlGuard([], $targets), $url, UnsafeUrlException::INVALID_URL);
    }

    public static function invalidUrls(): array
    {
        return [
            'empty'             => [''],
            'no scheme'         => ['plex.example.test:32400'],
            'ftp'               => ['ftp://plex.example.test/'],
            'file'              => ['file:///etc/passwd'],
            'gopher'            => ['gopher://plex.example.test:32400/'],
            'userinfo'          => ['https://user:pass@plex.example.test:32400'],
            'user only'         => ['https://user@plex.example.test:32400'],
            'query'             => ['https://plex.example.test:32400/?X-Plex-Token=x'],
            'empty query'       => ['https://plex.example.test:32400/?'],
            'fragment'          => ['https://plex.example.test:32400/#x'],
            'empty fragment'    => ['https://plex.example.test:32400#'],
            'path'              => ['https://plex.example.test:32400/web'],
            'double slash path' => ['https://plex.example.test:32400//'],
            'port zero'         => ['https://plex.example.test:0'],
            'port too big'      => ['https://plex.example.test:65536'],
            'no host'           => ['https://:32400'],
            'decimal shorthand' => ['http://2130706433/'],
            'hex shorthand'     => ['http://0x7f.1/'],
            'octal shorthand'   => ['http://0177.0.0.1/'],
            'short dotted'      => ['http://127.1/'],
            'backslash'         => ['https://plex.example.test\\@evil.example/'],
            'space'             => ['https://plex example.test/'],
            'signed port'       => ['http://plex.example.test:+80'],
            'space before port' => ['http://plex.example.test: 80'],
            'leading-zero port' => ['http://plex.example.test:080'],
            'zero-padded port'  => ['https://plex.example.test:00443'],
            'empty port'        => ['http://plex.example.test:'],
        ];
    }

    /** @dataProvider validUrls */
    public function testValidBaseUrl(string $url): void
    {
        $this->assertTrue(UrlGuard::isValidBaseUrl($url), $url);
    }

    public static function validUrls(): array
    {
        return [
            'https with port'  => ['https://plex.example.test:32400'],
            'trailing slash'   => ['https://plex.example.test:32400/'],
            'http no port'     => ['http://plex.example.test'],
            'upper scheme'     => ['HTTPS://Plex.Example.Test'],
            'ipv4'             => ['http://192.168.1.20:32400'],
            'ipv6'             => ['http://[fd12::1]:32400/'],
            'plex.direct'      => ['https://192-168-1-20.0123456789abcdef.plex.direct:32400'],
            'max port'         => ['https://plex.example.test:65535'],
            'numeric-ish name' => ['https://cafe.be'],
        ];
    }

    /** @dataProvider normalisedUrls */
    public function testNormaliseBaseUrl(string $in, string $out): void
    {
        $this->assertSame($out, UrlGuard::normaliseBaseUrl($in));
        $this->assertSame($out, UrlGuard::normaliseBaseUrl($out), 'idempotent');
    }

    public static function normalisedUrls(): array
    {
        return [
            'trailing slash'        => ['https://plex.example.test:32400/', 'https://plex.example.test:32400'],
            'case'                  => ['HTTPS://PLEX.Example.TEST:32400', 'https://plex.example.test:32400'],
            'trailing dot'          => ['https://plex.example.test.:32400', 'https://plex.example.test:32400'],
            'no port kept absent'   => ['http://plex.example.test/', 'http://plex.example.test'],
            'explicit default kept' => ['https://plex.example.test:443', 'https://plex.example.test:443'],
            'ipv6 canonical'        => ['http://[FD12:0:0::1]:32400/', 'http://[fd12::1]:32400'],
        ];
    }

    public function testNormaliseRefusesAnInvalidUrl(): void
    {
        $this->expectException(UnsafeUrlException::class);
        UrlGuard::normaliseBaseUrl('https://plex.example.test:32400/web?X-Plex-Token=x');
    }

    // ── host allow-list ───────────────────────────────────────────────────────

    public function testAllowListMatchesExactlyAndBySuffix(): void
    {
        $this->dns([
            'plex.example.test'     => [self::PUBLIC_IP],
            'a.b.plex.direct'       => [self::PUBLIC_IP],
            'plex.direct'           => [self::PUBLIC_IP],
            'attacker.net'          => [self::PUBLIC_IP],
            'plex.example.test.attacker.net' => [self::PUBLIC_IP],
            'evilplex.direct'       => [self::PUBLIC_IP],
        ]);
        $guard = new UrlGuard(['Plex.Example.Test', '*.plex.direct']);

        $this->assertSame('plex.example.test', $guard->check('https://PLEX.example.test.:32400')['host']);
        $this->assertSame('a.b.plex.direct', $guard->check('https://a.b.plex.direct:32400')['host']);
        foreach (['plex.direct', 'attacker.net', 'plex.example.test.attacker.net', 'evilplex.direct'] as $host) {
            $this->assertRefused($guard, "https://$host:32400", UnsafeUrlException::HOST_NOT_ALLOWED);
        }
    }

    public function testAllowListDoesNotBypassThePrivateNetworkRule(): void
    {
        $this->dns(['plex.lan' => ['192.168.1.20']]);
        $this->assertRefused(new UrlGuard(['plex.lan']), 'http://plex.lan:32400', UnsafeUrlException::PRIVATE_NETWORK);
        $this->assertSame('192.168.1.20', (new UrlGuard(['plex.lan'], ['plex.lan']))->check('http://plex.lan:32400')['ip']);
    }

    // ── target list syntax ────────────────────────────────────────────────────

    public function testParseTargetList(): void
    {
        $this->assertSame(
            ['plex.lan', '192.168.1.0/24:32400', '[fd12::1]:8443', 'fd12::/16', '10.0.0.5'],
            UrlGuard::parseTargetList("Plex.LAN\n\n  192.168.1.0/24:32400 , [FD12::1]:8443\r\nFD12::/16\n10.0.0.5\nplex.lan")
        );
    }

    /** @dataProvider validTargets */
    public function testValidTargetEntry(string $entry): void
    {
        $this->assertTrue(UrlGuard::isValidTargetEntry($entry), $entry);
    }

    public static function validTargets(): array
    {
        return [
            'host'             => ['plex.lan'],
            'single label'     => ['plex'],
            'host port'        => ['plex.lan:32400'],
            'ipv4'             => ['192.168.1.20'],
            'ipv4 port'        => ['192.168.1.20:8443'],
            'ipv4 cidr'        => ['192.168.1.0/24'],
            'ipv4 cidr port'   => ['192.168.1.0/24:32400'],
            'ipv6'             => ['fd12::1'],
            'ipv6 bracketed'   => ['[fd12::1]'],
            'ipv6 port'        => ['[fd12::1]:32400'],
            'ipv6 cidr'        => ['fd12::/16'],
            'ipv6 cidr port'   => ['[fd12::/16]:32400'],
            'ipv4 /8'          => ['10.0.0.0/8'],
        ];
    }

    /** @dataProvider invalidTargets */
    public function testInvalidTargetEntry(string $entry): void
    {
        $this->assertFalse(UrlGuard::isValidTargetEntry($entry), $entry);
    }

    public static function invalidTargets(): array
    {
        return [
            'empty'            => [''],
            'url'              => ['http://plex.lan'],
            'path'             => ['plex.lan/web'],
            'wildcard'         => ['*.lan'],
            'port zero'        => ['plex.lan:0'],
            'port too big'     => ['plex.lan:65536'],
            'port not numeric' => ['plex.lan:http'],
            'v4 prefix too big' => ['10.0.0.0/33'],
            'v6 prefix too big' => ['fd00::/129'],
            'v4 everything'    => ['0.0.0.0/0'],
            'v6 everything'    => ['::/0'],
            'v4 shorter than /8' => ['10.0.0.0/7'],
            'v6 shorter than /16' => ['::/15'],
            'ULA /7 (use /16 or longer)' => ['fc00::/7'],
            'v4 short cidr port' => ['0.0.0.0/0:32400'],
            'bad cidr net'     => ['plex.lan/24'],
            'numeric shorthand' => ['127.1'],
            'v6 port no brackets' => ['fd12::1:32400:'],
            'space'            => ['plex lan'],
        ];
    }

    // ── config and messages ───────────────────────────────────────────────────

    public function testFromConfigReadsBothKeys(): void
    {
        $config = new class {
            public function get(string $key, string $default = ''): string
            {
                return [
                    UrlGuard::ALLOWED_HOSTS_KEY   => "plex.lan\nother.lan",
                    UrlGuard::PRIVATE_TARGETS_KEY => "plex.lan:32400",
                ][$key] ?? $default;
            }
        };
        $this->assertSame('matinee_allowed_hosts', UrlGuard::ALLOWED_HOSTS_KEY);
        $this->assertSame('matinee_private_targets', UrlGuard::PRIVATE_TARGETS_KEY);
        $this->dns(['plex.lan' => ['192.168.1.20'], 'other.lan' => ['192.168.1.21'], 'third.lan' => [self::PUBLIC_IP]]);

        $guard = UrlGuard::fromConfig($config);
        $this->assertSame('192.168.1.20', $guard->check('http://plex.lan:32400')['ip']);
        $this->assertRefused($guard, 'http://other.lan:32400', UnsafeUrlException::PRIVATE_NETWORK);
        $this->assertRefused($guard, 'http://third.lan:32400', UnsafeUrlException::HOST_NOT_ALLOWED);
    }

    public function testFromConfigDefaultsToAnyPublicHostAndNoPrivateTargets(): void
    {
        $config = new class {
            public function get(string $key, string $default = ''): string
            {
                return $default;
            }
        };
        $this->dns(['plex.lan' => ['192.168.1.20'], 'plex.example.test' => [self::PUBLIC_IP]]);
        $guard = UrlGuard::fromConfig($config);
        $this->assertSame(self::PUBLIC_IP, $guard->check('https://plex.example.test:32400')['ip']);
        $this->assertRefused($guard, 'http://plex.lan:32400', UnsafeUrlException::PRIVATE_NETWORK);
    }

    /** @dataProvider leakyUrls */
    public function testMessageNeverCarriesQueryOrUserinfo(string $url, string $reason): void
    {
        $this->dns(['evil.example' => ['169.254.169.254'], 'plex.lan' => ['10.0.0.5']]);
        $e = $this->assertRefused(new UrlGuard(), $url, $reason);
        foreach (['test-token-NOT-REAL-0000', 'secretpw', 'alice', '/web'] as $needle) {
            $this->assertStringNotContainsString($needle, $e->getMessage());
        }
    }

    public static function leakyUrls(): array
    {
        return [
            'query'    => ['https://plex.example.test:32400/web?X-Plex-Token=test-token-NOT-REAL-0000', UnsafeUrlException::INVALID_URL],
            'userinfo' => ['https://alice:secretpw@plex.example.test:32400', UnsafeUrlException::INVALID_URL],
            'blocked'  => ['http://evil.example:32400', UnsafeUrlException::BLOCKED_ADDRESS],
            'private'  => ['http://plex.lan:32400', UnsafeUrlException::PRIVATE_NETWORK],
        ];
    }

    public function testMessageNamesTheHostAndReason(): void
    {
        $this->dns(['plex.example.test' => [self::PUBLIC_IP]]);
        $e = $this->assertRefused(new UrlGuard(), 'https://plex.example.test:22', UnsafeUrlException::PORT_NOT_ALLOWED);
        $this->assertStringContainsString('plex.example.test', $e->getMessage());
        $this->assertStringContainsString('22', $e->getMessage());
    }
}
