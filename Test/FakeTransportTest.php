<?php

require_once __DIR__ . '/support/FakeTransport.php';

use Kanboard\Plugin\Matinee\Http\PlexException;
use Kanboard\Plugin\Matinee\Http\PlexStreamSink;
use Kanboard\Plugin\Matinee\Test\FakeTransport;
use PHPUnit\Framework\TestCase;

/** The FakeTransport double plays scripted streams the way CurlTransport does (C3a): a sanity check. */
class FakeTransportTest extends TestCase
{
    private const OPTS = ['timeout' => 60, 'lowSpeedLimit' => 1024, 'lowSpeedTime' => 15, 'maxBytes' => 1000];

    private static function sink(bool $openAnswer = true, ?int $stopAfter = null): PlexStreamSink
    {
        return new class ($openAnswer, $stopAfter) implements PlexStreamSink {
            public array $opens = [];
            public array $writes = [];

            public function __construct(private bool $openAnswer, private ?int $stopAfter)
            {
            }

            public function open(int $status, array $headers): bool
            {
                $this->opens[] = [$status, $headers];
                return $this->openAnswer;
            }

            public function write(string $chunk): bool
            {
                $this->writes[] = $chunk;
                return $this->stopAfter === null || count($this->writes) < $this->stopAfter;
            }
        };
    }

    public function testAStreamIsRecordedAndPlayedThroughTheSink(): void
    {
        $t = (new FakeTransport())->streams(206, ['Content-Type' => 'audio/mpeg', 'Set-Cookie' => 'x=1'], ['ab', 'cd']);
        $sink = self::sink();
        $r = $t->stream('https://plex.example.test/x', ['X-Plex-Token' => 'fake'], self::OPTS, $sink);

        $this->assertSame(206, $r->status);
        $this->assertSame('', $r->body);
        $this->assertSame([[206, ['content-type' => 'audio/mpeg']]], $sink->opens);
        $this->assertSame(['ab', 'cd'], $sink->writes);
        $this->assertSame([['url' => 'https://plex.example.test/x', 'headers' => ['X-Plex-Token' => 'fake'], 'opts' => self::OPTS]], $t->requests);
    }

    public function testRefuseAndStopAreHonoured(): void
    {
        $t = (new FakeTransport())->streams(200, [], ['a', 'b'])->streams(200, [], ['a', 'b']);
        $refused = self::sink(false);
        $t->stream('https://p.test/x', [], self::OPTS, $refused);
        $this->assertSame([], $refused->writes);

        $stopped = self::sink(true, 1);
        $t->stream('https://p.test/x', [], self::OPTS, $stopped);
        $this->assertSame(['a'], $stopped->writes);
    }

    public function testAnEmptyStreamOpensOnce(): void
    {
        $sink = self::sink();
        (new FakeTransport())->streams(200, [], [])->stream('https://p.test/x', [], self::OPTS, $sink);
        $this->assertCount(1, $sink->opens);
        $this->assertSame([], $sink->writes);
    }

    public function testMaxBytesCutsTheExactPrefixThenThrows(): void
    {
        $t = (new FakeTransport())->streams(200, [], [str_repeat('a', 700), str_repeat('b', 700), 'c']);
        $sink = self::sink();
        try {
            $t->stream('https://p.test/x', [], self::OPTS, $sink);
            $this->fail('expected plex.too_large');
        } catch (PlexException $e) {
            $this->assertSame(PlexException::TOO_LARGE, $e->reason);
        }
        $this->assertSame(str_repeat('a', 700) . str_repeat('b', 300), implode('', $sink->writes));
    }

    public function testFailWorksForStreamsAndGetRecordsOpts(): void
    {
        $t = (new FakeTransport())->fail(PlexException::unreachable())->respond(200, 'ok');
        try {
            $t->stream('https://p.test/x', [], self::OPTS, self::sink());
            $this->fail('expected plex.unreachable');
        } catch (PlexException $e) {
            $this->assertSame(PlexException::UNREACHABLE, $e->reason);
        }
        $t->get('https://p.test/y', [], ['timeout' => 3]);
        $this->assertSame(['timeout' => 3], $t->requests[1]['opts']);
    }

    /** @dataProvider badOpts */
    public function testBadOptsAreRefusedAsCurlTransportRefusesThem(string $method, array $opts): void
    {
        $t = (new FakeTransport())->respond(200, 'ok')->streams(200, [], ['a']);
        try {
            $method === 'get'
                ? $t->get('https://p.test/x', [], $opts)
                : $t->stream('https://p.test/x', [], $opts, self::sink());
            $this->fail('expected the opts to be refused');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame([], $t->requests);
    }

    public static function badOpts(): array
    {
        $noMaxBytes = self::OPTS;
        unset($noMaxBytes['maxBytes']);
        return [
            'get: an unknown key'      => ['get', ['maxBytes' => 5]],
            'get: timeout 0'           => ['get', ['timeout' => 0]],
            'stream: missing maxBytes' => ['stream', $noMaxBytes],
        ];
    }

    public function testAQueuedStreamIsNotAGetAnswer(): void
    {
        $t = (new FakeTransport())->streams(200, [], ['a']);
        $this->expectException(\LogicException::class);
        $t->get('https://p.test/x', []);
    }
}
