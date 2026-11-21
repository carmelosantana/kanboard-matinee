<?php

require_once __DIR__ . '/support/RecordingSink.php';

use Kanboard\Plugin\Matinee\Http\ResponseSink;
use Kanboard\Plugin\Matinee\Test\RecordingSink;
use PHPUnit\Framework\TestCase;

/**
 * ResponseSink (C3e, C3f; S9, S11): the one place a proxied stream opens the response. First with recorders in place
 * of PHP's header functions, then live through `php -S`.
 */
class ResponseSinkTest extends TestCase
{
    private const FIXED = [
        'X-Content-Type-Options'       => 'nosniff',
        'Content-Security-Policy'      => "default-src 'none'; sandbox",
        'Content-Disposition'          => 'inline',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'Referrer-Policy'              => 'same-origin',
        'Cache-Control'                => 'private, no-store',
    ];

    /** @var list<string> every call, in order, as "kind:value" */
    private array $calls = [];
    private bool $gone = false;

    private function sink(): ResponseSink
    {
        return new ResponseSink(
            status: function (int $code): void { $this->calls[] = 'status:' . $code; },
            header: function (string $line): void { $this->calls[] = 'header:' . $line; },
            removeHeader: function (string $name): void { $this->calls[] = 'remove:' . $name; },
            output: function (string $chunk): void { $this->calls[] = 'output:' . $chunk; },
            aborted: fn (): bool => $this->gone,
            endBuffers: function (): void { $this->calls[] = 'endBuffers'; },
        );
    }

    /** @return list<string> the header lines sent, in order */
    private function headerLines(): array
    {
        $lines = [];
        foreach ($this->calls as $call) {
            if (str_starts_with($call, 'header:')) {
                $lines[] = substr($call, 7);
            }
        }
        return $lines;
    }

    public function testTheFixedSetIsExactlyC3e(): void
    {
        $this->assertSame(self::FIXED, ResponseSink::FIXED_HEADERS);
    }

    public function testOpenRunsC3eInOrderAndForwardsOnlyTheAllowedHeaders(): void
    {
        $sink = $this->sink();
        $this->assertFalse($sink->opened());
        $this->assertTrue($sink->open(206, [
            'content-type'  => 'audio/mpeg',
            'content-range' => 'bytes 0-2/3',
            'set-cookie'    => 'x=1',
        ]));
        $this->assertTrue($sink->opened());

        $expected = ['endBuffers', 'remove:Pragma', 'remove:Expires', 'remove:X-Powered-By', 'status:206',
            'header:Content-Type: audio/mpeg', 'header:Content-Range: bytes 0-2/3'];
        foreach (self::FIXED as $name => $value) {
            $expected[] = 'header:' . $name . ': ' . $value;
        }
        $this->assertSame($expected, $this->calls);

        foreach ($this->headerLines() as $line) {
            $this->assertStringStartsNotWith('set-cookie', strtolower($line));
            $this->assertStringStartsNotWith('x-accel-buffering', strtolower($line));
        }
    }

    public function testEveryForwardableHeaderGetsItsCanonicalName(): void
    {
        $this->sink()->open(200, ['content-type' => 'audio/flac', 'content-length' => '3', 'content-range' => 'bytes 0-2/3', 'accept-ranges' => 'bytes']);
        $this->assertSame(['Content-Type: audio/flac', 'Content-Length: 3', 'Content-Range: bytes 0-2/3', 'Accept-Ranges: bytes'],
            array_slice($this->headerLines(), 0, 4));
    }

    /** @dataProvider refusedHeaders */
    public function testHeadersOutsideTheForwardableSetOrWithBreaksAreNeverSent(array $headers): void
    {
        $this->sink()->open(200, $headers);
        $this->assertSame(array_map(fn ($n, $v) => "$n: $v", array_keys(self::FIXED), self::FIXED), $this->headerLines());
    }

    public static function refusedHeaders(): array
    {
        return [
            'set-cookie'          => [['set-cookie' => 'x=1']],
            'location'            => [['location' => 'https://evil.test/']],
            'x-accel-buffering'   => [['x-accel-buffering' => 'no']],
            'a fixed name'        => [['cache-control' => 'public']],
            'canonical spelling'  => [['Content-Type' => 'text/html']],
            'a line break'        => [['content-type' => "audio/mpeg\r\nSet-Cookie: x=1"]],
            'a NUL'               => [['content-type' => "audio/mpeg\0"]],
        ];
    }

    public function testWriteOutputsThenReportsAnAbort(): void
    {
        $sink = $this->sink();
        $sink->open(200, []);
        $this->assertTrue($sink->write('a'));
        $this->gone = true;
        $this->assertFalse($sink->write('b'));
        $this->assertContains('output:a', $this->calls);
        $this->assertContains('output:b', $this->calls);
    }

    public function testOpenIsOnceAndWriteNeedsOpen(): void
    {
        $sink = $this->sink();
        try {
            $sink->write('a');
            $this->fail('expected a LogicException');
        } catch (\LogicException) {
        }
        $sink->open(200, []);
        $this->expectException(\LogicException::class);
        $sink->open(200, []);
    }

    // ── live, through php -S ─────────────────────────────────────────────────

    public function testLiveTheResponseCarriesOnlyC3eAndStreams(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available to start the fixture');
        }
        $port = self::freePort();
        $devNull = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proc = proc_open(
            [PHP_BINARY, '-d', 'expose_php=1', '-d', 'output_buffering=4096', '-S', '127.0.0.1:' . $port, 'stream.php'],
            [0 => ['file', $devNull, 'r'], 1 => ['file', $devNull, 'w'], 2 => ['file', $devNull, 'w']],
            $pipes,
            __DIR__ . '/fixtures/sink'
        );
        $this->assertIsResource($proc);
        try {
            $conn = false;
            for ($deadline = microtime(true) + 10; $conn === false && microtime(true) < $deadline; usleep(50_000)) {
                $conn = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.2);
            }
            $this->assertNotFalse($conn, 'the fixture did not start');
            fwrite($conn, "GET /stream HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");

            $raw = '';
            $firstBodyAt = null;
            while (! feof($conn)) {
                $piece = fread($conn, 8192);
                if ($piece === false) {
                    break;
                }
                $raw .= $piece;
                if ($firstBodyAt === null && ($at = strpos($raw, "\r\n\r\n")) !== false && strlen($raw) > $at + 4) {
                    $firstBodyAt = microtime(true);
                }
            }
            $endAt = microtime(true);
            fclose($conn);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }

        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        $lines = explode("\r\n", $head);
        $this->assertMatchesRegularExpression('#^HTTP/1\.[01] 206#', $lines[0]);
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)][] = trim($value);
        }
        foreach (self::FIXED as $name => $value) {
            $this->assertSame([$value], $headers[strtolower($name)] ?? null, $name);
        }
        $this->assertSame(['audio/mpeg'], $headers['content-type'] ?? null);
        $this->assertSame(['bytes 0-2/3'], $headers['content-range'] ?? null);
        foreach (['set-cookie', 'pragma', 'expires', 'x-powered-by', 'x-accel-buffering'] as $absent) {
            $this->assertArrayNotHasKey($absent, $headers, $absent);
        }
        $this->assertSame('abc', $body, 'the junk buffered before open never reaches the client');
        $this->assertNotNull($firstBodyAt);
        $this->assertGreaterThan(1.5, $endAt - $firstBodyAt, 'the first chunk arrives before the last is produced');
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name   = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    // ── RecordingSink (a test double: a sanity check) ────────────────────────

    public function testRecordingSinkRecordsRefusesAndStops(): void
    {
        $r = new RecordingSink();
        $this->assertTrue($r->open(206, ['content-type' => 'audio/mpeg']));
        $this->assertTrue($r->write('a'));
        $this->assertSame([206, ['content-type' => 'audio/mpeg'], 'a'], [$r->status, $r->headers, $r->body]);

        $this->assertFalse((new RecordingSink(true))->open(200, []));

        $stop = new RecordingSink(false, 2);
        $this->assertTrue($stop->write('a'));
        $this->assertFalse($stop->write('b'));
        $this->assertSame('ab', $stop->body);
    }
}
