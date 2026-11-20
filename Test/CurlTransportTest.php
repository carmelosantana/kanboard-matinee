<?php

use Kanboard\Plugin\Matinee\Http\CurlTransport;
use Kanboard\Plugin\Matinee\Http\PlexException;
use Kanboard\Plugin\Matinee\Http\UnsafeUrlException;
use Kanboard\Plugin\Matinee\Http\UrlGuard;
use PHPUnit\Framework\TestCase;

/**
 * The real transport (spec §3, §11 S8 and S9) against a fake Plex server run with `php -S` on 127.0.0.1.
 * `plex.example.test` is not in real DNS: it reaches the fixture only through the guard's resolver seam and the
 * CURLOPT_RESOLVE pin built from it.
 */
class CurlTransportTest extends TestCase
{
    private const TOKEN = 'test-token-NOT-REAL-0000';
    private const HOST  = 'plex.example.test';

    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $hitsFile = '';

    private int $resolverCalls = 0;

    public static function setUpBeforeClass(): void
    {
        if (! extension_loaded('curl') || ! function_exists('proc_open')) {
            return; // setUp skips each test with the reason
        }
        self::$port     = self::freePort();
        self::$hitsFile = (string) tempnam(sys_get_temp_dir(), 'matinee-fake-plex-hits-');
        file_put_contents(self::$hitsFile, '0');

        $env = ['FAKE_PLEX_HITS' => self::$hitsFile];
        foreach (['PATH', 'SYSTEMROOT', 'TMPDIR'] as $name) {
            if (getenv($name) !== false) {
                $env[$name] = (string) getenv($name);
            }
        }
        $null = ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'];
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, 'index.php'],
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => $null, 2 => $null],
            $pipes,
            __DIR__ . '/fixtures/fake-plex',
            $env
        ) ?: null;

        $deadline = microtime(true) + 10;
        while (self::$server !== null && microtime(true) < $deadline) {
            $probe = @stream_socket_client('tcp://127.0.0.1:' . self::$port, $errno, $errstr, 0.2);
            if ($probe !== false) {
                fclose($probe);
                return;
            }
            usleep(50_000);
        }
        self::tearDownAfterClass();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        if (self::$hitsFile !== '' && is_file(self::$hitsFile)) {
            unlink(self::$hitsFile);
        }
    }

    protected function setUp(): void
    {
        if (! extension_loaded('curl')) {
            $this->markTestSkipped('ext-curl is not loaded');
        }
        if (self::$server === null) {
            $this->markTestSkipped('could not start the php -S fake Plex fixture');
        }
        $this->resolverCalls = 0;
        UrlGuard::setResolverForTesting(function (string $host): array {
            $this->resolverCalls++;
            return $host === self::HOST ? ['127.0.0.1'] : [];
        });
    }

    protected function tearDown(): void
    {
        UrlGuard::setResolverForTesting(null);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name   = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function hits(): int
    {
        clearstatcache();
        return (int) file_get_contents(self::$hitsFile);
    }

    private function transport(?array $targets = null, int $maxBytes = 2097152): CurlTransport
    {
        $guard = new UrlGuard([], $targets ?? ['127.0.0.1:' . self::$port]);
        return new CurlTransport($guard, 2, 10, $maxBytes);
    }

    private function url(string $path, ?int $port = null): string
    {
        return 'http://' . self::HOST . ':' . ($port ?? self::$port) . $path;
    }

    private function headers(): array
    {
        return ['X-Plex-Token' => self::TOKEN, 'X-Plex-Client-Identifier' => 'matinee-test', 'Accept' => 'application/json'];
    }

    // ── reaching the fixture ─────────────────────────────────────────────────

    public function testTheHostnameReachesTheFixtureThroughThePin(): void
    {
        $r = $this->transport()->get($this->url('/identity?X-Plex-Container-Size=1'), $this->headers());

        $this->assertSame(200, $r->status);
        $mc = json_decode($r->body, true, 32, JSON_THROW_ON_ERROR)['MediaContainer'];
        $this->assertSame('fake-machine', $mc['machineIdentifier']);
        $this->assertSame(self::HOST . ':' . self::$port, $mc['host'], 'the Host header keeps the name');
        $this->assertSame('X-Plex-Container-Size=1', $mc['query'], 'path and query are passed through');
        $this->assertTrue($mc['tokenSeen']);
        $this->assertSame(hash('sha256', self::TOKEN), $mc['tokenSha256'], 'the header arrives intact');
        $this->assertSame('application/json', $r->headers['content-type'] ?? null);
        $this->assertArrayNotHasKey('set-cookie', $r->headers, 'PlexResponse filters the headers');
    }

    public function testSectionsJsonComesBack(): void
    {
        $r = $this->transport()->get($this->url('/library/sections'), $this->headers());
        $this->assertSame(200, $r->status);
        $this->assertSame('Movies', json_decode($r->body, true)['MediaContainer']['Directory'][0]['title']);
    }

    public function testARedirectIsReturnedNotFollowed(): void
    {
        $before = self::hits();
        $r = $this->transport()->get($this->url('/redirect'), $this->headers());
        $this->assertSame(302, $r->status);
        $this->assertSame('redirected', $r->body);
        $this->assertSame($before + 1, self::hits(), 'one request, no follow-up');
    }

    /** @dataProvider bigBodies */
    public function testABodyOverTheCapIsTooLarge(string $path): void
    {
        try {
            $this->transport()->get($this->url($path), $this->headers());
            $this->fail('expected plex.too_large');
        } catch (PlexException $e) {
            $this->assertSame(PlexException::TOO_LARGE, $e->reason);
        }
    }

    public static function bigBodies(): array
    {
        return [
            'with a content-length' => ['/big'],
            'chunked, no length'    => ['/big-chunked'],
        ];
    }

    public function testABodyAtTheCapPasses(): void
    {
        $r = $this->transport(null, 3 * 1024 * 1024)->get($this->url('/big'), $this->headers());
        $this->assertSame(3 * 1024 * 1024, strlen($r->body));
    }

    // ── the guard ────────────────────────────────────────────────────────────

    public function testWithoutAPrivateTargetNothingConnects(): void
    {
        $before = self::hits();
        try {
            $this->transport([])->get($this->url('/identity'), $this->headers());
            $this->fail('expected UnsafeUrlException');
        } catch (UnsafeUrlException $e) {
            $this->assertSame(UnsafeUrlException::PRIVATE_NETWORK, $e->reason);
        }
        $this->assertSame($before, self::hits());
    }

    public function testTheGuardRunsOnEveryGet(): void
    {
        $t = $this->transport();
        $t->get($this->url('/identity'), $this->headers());
        $this->assertSame(1, $this->resolverCalls);
        $t->get($this->url('/identity'), $this->headers());
        $this->assertSame(2, $this->resolverCalls);
    }

    /** @dataProvider malformedUrls */
    public function testAMalformedUrlIsRefusedBeforeAnyConnection(string $url): void
    {
        $before = self::hits();
        try {
            $this->transport()->get(sprintf($url, self::$port), $this->headers());
            $this->fail("expected $url to be refused");
        } catch (UnsafeUrlException $e) {
            $this->assertSame(UnsafeUrlException::INVALID_URL, $e->reason);
        }
        $this->assertSame($before, self::hits());
    }

    public static function malformedUrls(): array
    {
        return [
            'userinfo'       => ['http://user@plex.example.test:%d/identity'],
            'fragment'       => ['http://plex.example.test:%d/identity#x'],
            'backslash'      => ['http://plex.example.test:%d\\@evil.test/identity'],
            'space'          => ['http://plex.example.test:%d/iden tity'],
            'line break'     => ["http://plex.example.test:%d/identity\r\nX: y"],
            'other scheme'   => ['ftp://plex.example.test:%d/identity'],
            'no path prefix' => ['http://plex.example.test:%dfoo/identity'],
        ];
    }

    public function testAHeaderWithALineBreakIsRefused(): void
    {
        $before = self::hits();
        try {
            $this->transport()->get($this->url('/identity'), ['X-Plex-Token' => self::TOKEN . "\r\nX-Evil: 1"]);
            $this->fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
        $this->assertSame($before, self::hits());
    }

    public function testHeadersAreRedactedFromStackTraces(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0'); // On by default here, which would hide args anyway
        try {
            $this->transport()->get($this->url('/identity'), ['X-Plex-Token' => self::TOKEN . "\nX-Evil: 1"]);
            $this->fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            $frames = [];
            foreach ($e->getTrace() as $frame) {
                if (($frame['class'] ?? '') === CurlTransport::class) {
                    $frames[$frame['function']] = $frame['args'] ?? [];
                }
            }
            $this->assertArrayHasKey('get', $frames);
            $this->assertArrayHasKey('headerLines', $frames);
            $this->assertInstanceOf(\SensitiveParameterValue::class, $frames['get'][1] ?? null);
            $this->assertInstanceOf(\SensitiveParameterValue::class, $frames['headerLines'][0] ?? null);
            $this->assertStringNotContainsString(self::TOKEN, $e->getTraceAsString());
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    // ── failures ─────────────────────────────────────────────────────────────

    public function testConnectionRefusedIsUnreachableAndSaysNothingElse(): void
    {
        $dead = self::freePort();
        try {
            $this->transport(['127.0.0.1:' . $dead])->get($this->url('/identity?secret-query=1', $dead), $this->headers());
            $this->fail('expected plex.unreachable');
        } catch (PlexException $e) {
            $this->assertSame(PlexException::UNREACHABLE, $e->reason);
            $this->assertSame('plex.unreachable', $e->getMessage());
            $this->assertNull($e->getPrevious());
            foreach ([self::HOST, 'secret-query', '?', (string) $dead, self::TOKEN] as $leak) {
                $this->assertStringNotContainsString($leak, $e->getMessage());
            }
        }
    }

    public function testProxyEnvironmentVariablesAreIgnored(): void
    {
        $dead  = 'http://127.0.0.1:' . self::freePort();
        $names = ['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY'];
        $saved = [];
        foreach ($names as $name) {
            $saved[$name] = getenv($name);
            putenv("$name=$dead");
        }
        try {
            $r = $this->transport()->get($this->url('/identity'), $this->headers());
            $this->assertSame(200, $r->status);
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : "$name=$value");
            }
        }
    }

    // ── pure helpers ─────────────────────────────────────────────────────────

    /** @dataProvider resolveEntries */
    public function testResolveEntry(string $host, int $port, string $ip, string $expected): void
    {
        $this->assertSame($expected, CurlTransport::resolveEntry($host, $port, $ip));
    }

    public static function resolveEntries(): array
    {
        return [
            'ipv4'           => ['plex.example.test', 32400, '10.0.0.5', 'plex.example.test:32400:10.0.0.5'],
            'ipv6 bracketed' => ['plex.example.test', 443, 'fd12::5', 'plex.example.test:443:[fd12::5]'],
            'ipv6 literal host' => ['fd12::5', 32400, 'fd12::5', '[fd12::5]:32400:[fd12::5]'],
        ];
    }

    /** @dataProvider addressPairs */
    public function testSameAddress(string $a, string $b, bool $same): void
    {
        $this->assertSame($same, CurlTransport::sameAddress($a, $b));
    }

    public static function addressPairs(): array
    {
        return [
            'equal v4'              => ['127.0.0.1', '127.0.0.1', true],
            'other v4'              => ['127.0.0.1', '127.0.0.2', false],
            'v6 spellings'          => ['::1', '0:0:0:0:0:0:0:1', true],
            'v6 case'               => ['FD12::5', 'fd12:0::5', true],
            'v4 vs mapped'          => ['127.0.0.1', '::ffff:127.0.0.1', false],
            'empty (no connection)' => ['', '127.0.0.1', false],
            'junk'                  => ['not-an-ip', 'not-an-ip', false],
        ];
    }
}
