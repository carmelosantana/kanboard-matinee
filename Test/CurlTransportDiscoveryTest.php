<?php

use Kanboard\Plugin\Matinee\Http\CurlTransport;
use Kanboard\Plugin\Matinee\Http\PlexException;
use Kanboard\Plugin\Matinee\Http\UnsafeUrlException;
use Kanboard\Plugin\Matinee\Http\UrlGuard;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CurlTransport::discoverPlexDirect() against real TLS listeners on 127.0.0.1 (Test/fixtures/tls-plex/server.php).
 * A throwaway CA signs a `*.<hash>.plex.direct` leaf and a non-Plex leaf; a third listener serves a self-signed
 * leaf the CA did not sign. The transport is given the CA as its trust store (the constructor's CA-file seam).
 *
 * The discovery is a handshake only: the listener must see the handshake and not one byte after it, so no
 * request and no X-Plex-Token header ever reaches the server.
 */
class CurlTransportDiscoveryTest extends TestCase
{
    private const HASH = '0123456789abcdef0123456789abcdef';

    private static string $unavailable = '';
    private static string $dir = '';
    private static string $caFile = '';

    /** @var array<string, array{proc: resource, port: int, dir: string}> listeners by name */
    private static array $listeners = [];

    public static function setUpBeforeClass(): void
    {
        self::$unavailable = '';
        foreach (['curl', 'openssl'] as $ext) {
            if (! extension_loaded($ext)) {
                self::$unavailable = "ext-$ext is not loaded";
                return;
            }
        }
        if (! function_exists('proc_open')) {
            self::$unavailable = 'proc_open is not available to start the fixture';
            return;
        }
        self::$dir = sys_get_temp_dir() . '/matinee-tls-' . bin2hex(random_bytes(6));
        mkdir(self::$dir, 0700);

        $conf = self::$dir . '/openssl.cnf';
        file_put_contents($conf, implode("\n", [
            '[req]', 'distinguished_name = dn', '[dn]',
            '[v3_ca]', 'basicConstraints = critical,CA:true', 'keyUsage = critical,keyCertSign,cRLSign',
            'subjectKeyIdentifier = hash',
            '[v3_plex]', 'basicConstraints = CA:false', 'extendedKeyUsage = serverAuth',
            'subjectAltName = DNS:*.' . self::HASH . '.plex.direct',
            '[v3_other]', 'basicConstraints = CA:false', 'extendedKeyUsage = serverAuth',
            'subjectAltName = DNS:plex.example.com',
            '',
        ]));
        $keyArgs = ['config' => $conf, 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'digest_alg' => 'sha256'];

        $caKey = openssl_pkey_new($keyArgs);
        $caCsr = openssl_csr_new(['commonName' => 'Matinee Test CA'], $caKey, $keyArgs);
        $ca = openssl_csr_sign($caCsr, null, $caKey, 1, $keyArgs + ['x509_extensions' => 'v3_ca'], 1);
        openssl_x509_export_to_file($ca, self::$caFile = self::$dir . '/ca.pem');

        $leaves = [
            'plex'       => ['*.' . self::HASH . '.plex.direct', 'v3_plex', true],
            'other'      => ['plex.example.com', 'v3_other', true],
            'selfsigned' => ['*.' . self::HASH . '.plex.direct', 'v3_plex', false],
        ];
        foreach ($leaves as $name => [$cn, $ext, $signed]) {
            $key = openssl_pkey_new($keyArgs);
            $csr = openssl_csr_new(['commonName' => $cn], $key, $keyArgs);
            $cert = openssl_csr_sign($csr, $signed ? $ca : null, $signed ? $caKey : $key, 1, $keyArgs + ['x509_extensions' => $ext], random_int(2, PHP_INT_MAX));
            $dir = self::$dir . '/' . $name;
            mkdir($dir, 0700);
            openssl_x509_export_to_file($cert, $dir . '/cert.pem');
            openssl_pkey_export_to_file($key, $dir . '/key.pem', null, $keyArgs);

            $port = self::freePort();
            $proc = self::start($port, $dir);
            if ($proc === null) {
                self::$unavailable = 'could not start the TLS fixture';
                return;
            }
            self::$listeners[$name] = ['proc' => $proc, 'port' => $port, 'dir' => $dir];
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$listeners as $l) {
            proc_terminate($l['proc']);
            proc_close($l['proc']);
        }
        self::$listeners = [];
        if (self::$dir !== '' && is_dir(self::$dir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir(self::$dir);
        }
    }

    protected function setUp(): void
    {
        if (self::$unavailable !== '') {
            if ((bool) getenv('CI')) {
                self::fail('CI is set, so a missing prerequisite fails: ' . self::$unavailable);
            }
            self::markTestSkipped(self::$unavailable);
        }
    }

    /** @return resource|null */
    private static function start(int $port, string $dir)
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/tls-plex/server.php', (string) $port, $dir . '/cert.pem', $dir . '/key.pem', $dir],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes
        ) ?: null;
        $deadline = microtime(true) + 10;
        while ($proc !== null && microtime(true) < $deadline) {
            $conn = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.2);
            if ($conn !== false && is_file($dir . '/done')) {
                fclose($conn);
                return $proc;
            }
            if ($conn !== false) {
                fclose($conn);
            }
            usleep(50_000);
        }
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
        return null;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /** @return array{handshakes: int, done: int, bytes: string} */
    private static function record(string $listener): array
    {
        $dir = self::$listeners[$listener]['dir'];
        clearstatcache();
        return [
            'handshakes' => (int) file_get_contents($dir . '/handshakes'),
            'done'       => (int) file_get_contents($dir . '/done'),
            'bytes'      => (string) file_get_contents($dir . '/bytes'),
        ];
    }

    /** Waits until the listener has finished with $connections connections (or 5 s pass), then reads its record. */
    private static function settled(string $listener, int $connections): array
    {
        $deadline = microtime(true) + 5;
        while (self::record($listener)['done'] < $connections && microtime(true) < $deadline) {
            usleep(20_000);
        }
        return self::record($listener);
    }

    private function transport(string $listener, ?string $caFile = null): CurlTransport
    {
        $port = self::$listeners[$listener]['port'];
        return new CurlTransport(new UrlGuard([], ['127.0.0.1:' . $port]), 2, 5, 2097152, $caFile ?? self::$caFile);
    }

    private function base(string $listener): string
    {
        return 'https://127.0.0.1:' . self::$listeners[$listener]['port'];
    }

    public function testAPlexDirectWildcardGivesTheTypedIpsSecureName(): void
    {
        $before = self::record('plex');

        $secure = $this->transport('plex')->discoverPlexDirect($this->base('plex'));

        $port = self::$listeners['plex']['port'];
        $this->assertSame('https://127-0-0-1.' . self::HASH . '.plex.direct:' . $port, $secure);
        $after = self::settled('plex', $before['done'] + 1);
        $this->assertSame($before['handshakes'] + 1, $after['handshakes'], 'one handshake');
        $this->assertSame($before['bytes'], $after['bytes'], 'not one byte after the handshake: no request, no X-Plex-Token');
        $this->assertStringNotContainsStringIgnoringCase('x-plex-token', $after['bytes']);
    }

    public function testANonPlexCertificateGivesNothing(): void
    {
        $before = self::record('other');
        $this->assertNull($this->transport('other')->discoverPlexDirect($this->base('other')));
        $after = self::settled('other', $before['done'] + 1);
        $this->assertSame($before['bytes'], $after['bytes']);
    }

    public function testAnUntrustedChainIsUnreachable(): void
    {
        $before = self::record('selfsigned');
        try {
            $this->transport('selfsigned')->discoverPlexDirect($this->base('selfsigned'));
            $this->fail('expected plex.unreachable: the chain is verified even though the name is not');
        } catch (PlexException $e) {
            $this->assertSame(PlexException::UNREACHABLE, $e->reason);
            $this->assertSame(PlexException::UNREACHABLE, $e->getMessage());
        }
        usleep(200_000);
        $this->assertSame($before['handshakes'], self::record('selfsigned')['handshakes'], 'curl refused the handshake');
    }

    public function testTheGuardRunsBeforeAnyConnection(): void
    {
        $before = self::record('plex');
        try {
            (new CurlTransport(new UrlGuard(), 2, 5, 2097152, self::$caFile))->discoverPlexDirect($this->base('plex'));
            $this->fail('expected UnsafeUrlException');
        } catch (UnsafeUrlException $e) {
            $this->assertSame(UnsafeUrlException::PRIVATE_NETWORK, $e->reason);
        }
        usleep(200_000);
        $this->assertSame($before['handshakes'], self::record('plex')['handshakes']);
    }

    /** @dataProvider notDiscoverable */
    #[DataProvider('notDiscoverable')]
    public function testOnlyAnHttpsIpv4BaseIsProbed(string $base): void
    {
        $before = self::record('plex');
        $port = self::$listeners['plex']['port'];
        $this->assertNull($this->transport('plex')->discoverPlexDirect(sprintf($base, $port)));
        usleep(100_000);
        $this->assertSame($before['handshakes'], self::record('plex')['handshakes']);
    }

    public static function notDiscoverable(): array
    {
        return [
            'http'          => ['http://127.0.0.1:%d'],
            'a hostname'    => ['https://localhost:%d'],
            'IPv6'          => ['https://[::1]:%d'],
            'with a path'   => ['https://127.0.0.1:%d/identity'],
        ];
    }

    public function testTheTransportStillVerifiesHostNamesOnGet(): void
    {
        // The token-carrying get() keeps full verification: the plex leaf does not name 127.0.0.1.
        try {
            $this->transport('plex')->get($this->base('plex') . '/identity', ['X-Plex-Token' => 'test-token-NOT-REAL-0000']);
            $this->fail('expected plex.unreachable');
        } catch (PlexException $e) {
            $this->assertSame(PlexException::UNREACHABLE, $e->reason);
        }
    }
}
