<?php

use Kanboard\Plugin\Matinee\Http\PlexDirect;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PlexDirect: which bases get a secure-name discovery, which certificate names count as Plex's, and the one
 * rewritten URL a typed `https://<IPv4>:port` may become. The hash comes from the certificate, the IP and port from
 * what the owner typed.
 */
class PlexDirectTest extends TestCase
{
    private const HASH = '0123456789abcdef0123456789abcdef';
    private const IP_BASE = 'https://192.168.1.10:32400';
    private const SECURE = 'https://192-168-1-10.0123456789abcdef0123456789abcdef.plex.direct:32400';

    /** @dataProvider endpoints */
    #[DataProvider('endpoints')]
    public function testOnlyHttpsIpv4BasesAreDiscovered(string $base, ?array $endpoint): void
    {
        $this->assertSame($endpoint, PlexDirect::ipv4Endpoint($base));
    }

    public static function endpoints(): array
    {
        return [
            'https IPv4 with port'     => [self::IP_BASE, ['ip' => '192.168.1.10', 'port' => 32400]],
            'https IPv4, default port' => ['https://192.168.1.10', ['ip' => '192.168.1.10', 'port' => 443]],
            'uppercase scheme'         => ['HTTPS://192.168.1.10:32400', ['ip' => '192.168.1.10', 'port' => 32400]],
            'http IPv4'                => ['http://192.168.1.10:32400', null],
            'IPv6 literal'             => ['https://[fd00::5]:32400', null],
            'hostname'                 => ['https://plex.example.test:32400', null],
            'plex.direct name'         => [self::SECURE, null],
            'not a base URL'           => ['https://192.168.1.10:32400/identity', null],
            'numeric shorthand'        => ['https://3232235862:32400', null],
        ];
    }

    /** @dataProvider certificates */
    #[DataProvider('certificates')]
    public function testOnlyAPlexDirectWildcardGivesASecureName(array $names, ?string $expected): void
    {
        $this->assertSame($expected, PlexDirect::fromCertificate(self::IP_BASE, $names));
    }

    public static function certificates(): array
    {
        $wild = '*.' . self::HASH . '.plex.direct';
        return [
            'the wildcard'                       => [[$wild], self::SECURE],
            'the wildcard twice (CN and SAN)'    => [[$wild, $wild], self::SECURE],
            'among other names'                  => [['plex.example.com', $wild], self::SECURE],
            'no names'                           => [[], null],
            'a non-Plex name'                    => [['plex.example.com'], null],
            'a non-Plex wildcard'                => [['*.example.com'], null],
            'a specific IP label (not wildcard)' => [['10-0-0-5.' . self::HASH . '.plex.direct'], null],
            'the typed IP label (not wildcard)'  => [['192-168-1-10.' . self::HASH . '.plex.direct'], null],
            'uppercase hash'                     => [['*.' . strtoupper(self::HASH) . '.plex.direct'], null],
            'a 31-character hash'                => [['*.' . substr(self::HASH, 1) . '.plex.direct'], null],
            'a 33-character hash'                => [['*.' . self::HASH . 'a.plex.direct'], null],
            'a non-hex hash'                     => [['*.' . substr(self::HASH, 1) . 'g.plex.direct'], null],
            'plex.direct as a prefix'            => [[$wild . '.evil.test'], null],
            'a lookalike suffix'                 => [['*.' . self::HASH . '.plex.directx'], null],
            'two different hashes'               => [[$wild, '*.ffffffffffffffffffffffffffffffff.plex.direct'], null],
        ];
    }

    public function testTheRewriteKeepsTheTypedPort(): void
    {
        $this->assertSame(
            'https://10-0-0-5.' . self::HASH . '.plex.direct:443',
            PlexDirect::fromCertificate('https://10.0.0.5', ['*.' . self::HASH . '.plex.direct'])
        );
    }

    public function testANonDiscoverableBaseHasNoSecureName(): void
    {
        $this->assertNull(PlexDirect::fromCertificate('http://192.168.1.10:32400', ['*.' . self::HASH . '.plex.direct']));
    }

    /** @dataProvider candidates */
    #[DataProvider('candidates')]
    public function testOnlyTheTypedIpAndPortAreASecureNameFor(string $candidate, bool $ok): void
    {
        $this->assertSame($ok, PlexDirect::isSecureNameFor(self::IP_BASE, $candidate));
    }

    public static function candidates(): array
    {
        return [
            'the rewrite'           => [self::SECURE, true],
            'another IP label'      => ['https://10-0-0-5.' . self::HASH . '.plex.direct:32400', false],
            'another port'          => ['https://192-168-1-10.' . self::HASH . '.plex.direct:443', false],
            'no port'               => ['https://192-168-1-10.' . self::HASH . '.plex.direct', false],
            'http'                  => ['http://192-168-1-10.' . self::HASH . '.plex.direct:32400', false],
            'a non-Plex host'       => ['https://plex.example.com:32400', false],
            'the typed IP itself'   => [self::IP_BASE, false],
            'uppercase hash'        => ['https://192-168-1-10.' . strtoupper(self::HASH) . '.plex.direct:32400', false],
            'a short hash'          => ['https://192-168-1-10.' . substr(self::HASH, 1) . '.plex.direct:32400', false],
            'a suffix after'        => ['https://192-168-1-10.' . self::HASH . '.plex.direct.evil.test:32400', false],
            'a path'                => [self::SECURE . '/', false],
        ];
    }

    public function testNothingIsASecureNameForAHostnameBase(): void
    {
        $this->assertFalse(PlexDirect::isSecureNameFor('https://plex.example.test:32400', self::SECURE));
    }

    /** CURLINFO_CERTINFO spells the subject either way, and lists SANs as `DNS:` entries. */
    public function testCertificateNamesReadsTheSubjectCnAndDnsSans(): void
    {
        $this->assertSame(
            ['*.' . self::HASH . '.plex.direct', '*.' . self::HASH . '.plex.direct', 'alt.example.com'],
            PlexDirect::certificateNames([
                'Subject' => 'CN = *.' . self::HASH . '.plex.direct',
                'X509v3 Subject Alternative Name' => 'DNS:*.' . self::HASH . '.plex.direct, IP Address:10.0.0.1, DNS:alt.example.com',
            ])
        );
        $this->assertSame(['plex.example.com'], PlexDirect::certificateNames(['Subject' => 'C=US, O=Example, CN=plex.example.com']));
        $this->assertSame([], PlexDirect::certificateNames([]));
    }
}
