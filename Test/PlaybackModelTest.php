<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/support/FakeTransport.php';
require_once __DIR__.'/support/RecordingSink.php';

use KanboardTests\units\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Matinee\Crypto\KeyStore;
use Kanboard\Plugin\Matinee\Crypto\RefSigner;
use Kanboard\Plugin\Matinee\Crypto\Subkeys;
use Kanboard\Plugin\Matinee\Http\ByteRange;
use Kanboard\Plugin\Matinee\Http\PlexClient;
use Kanboard\Plugin\Matinee\Http\PlexStreamSink;
use Kanboard\Plugin\Matinee\Model\MatineeBusyException;
use Kanboard\Plugin\Matinee\Model\MatineeConnectException;
use Kanboard\Plugin\Matinee\Model\MatineeForbiddenException;
use Kanboard\Plugin\Matinee\Model\MatineeRangeException;
use Kanboard\Plugin\Matinee\Model\PlaybackModel;
use Kanboard\Plugin\Matinee\Model\TranscodeModel;
use Kanboard\Plugin\Matinee\Plugin;
use Kanboard\Plugin\Matinee\Test\FakeTransport;
use Kanboard\Plugin\Matinee\Test\RecordingSink;

/** A sink that runs a callback on every write (after recording it), so a test can act mid-stream. */
class PlaybackCallbackSink extends RecordingSink
{
    /** @param \Closure(int): void $onWrite gets the write number, from 1 */
    public function __construct(private readonly \Closure $onWrite, ?int $stopAfter = null)
    {
        parent::__construct(false, $stopAfter);
    }

    public function write(string $chunk): bool
    {
        $go = parent::write($chunk);
        ($this->onWrite)($this->writes);
        return $go;
    }
}

/**
 * PlaybackModel, audio (plan P3 Task 9; spec §6, §7 Audio; C1, C3f, C3g, C4a, C5, C9). Plex is FakeTransport behind the
 * injectable client factory; the clock and the shutdown registrar are container seams.
 */
class PlaybackModelTest extends Base
{
    private const TOKEN = 'test-token-NOT-REAL-0000';
    private const BASE = 'https://plex.example.test:32400';
    private const CLIENT_ID = 'c0ffee00-1111-4222-8333-444455556666';
    private const T0 = 1_800_000_000;
    private const MP3_PART = '/library/parts/5001/1700000000/file.mp3';
    /** Live sections with the music library at key 3. */
    private const SECTIONS = '{"MediaContainer":{"Directory":[{"key":"1","type":"movie","title":"Movies"},{"key":"3","type":"artist","title":"Music"}]}}';

    private FakeTransport $t;
    private \Psr\Log\AbstractLogger $log;
    private string $keyDir;
    private int $now;

    private \Closure $productionClock;
    private \Closure $productionShutdown;

    /** @var list<callable> every closure handed to the shutdown registrar */
    private array $shutdowns = [];

    private int $alice;
    private int $bob;
    private int $serverId;

    protected function setUp(): void
    {
        parent::setUp();
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../Schema/$driver.php";
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\Matinee\Schema\version_1($pdo);
        \Kanboard\Plugin\Matinee\Schema\version_2($pdo);
        \Kanboard\Plugin\Matinee\Schema\version_3($pdo);

        (new Plugin($this->container))->initialize();

        $this->keyDir = sys_get_temp_dir().'/matinee-playback-'.bin2hex(random_bytes(6));
        $this->container['matineeKeyStore'] = new KeyStore($this->keyDir, null);
        $this->now = self::T0;
        $this->container->offsetUnset('matineeRefSigner');
        $this->container['matineeRefSigner'] = fn ($c): RefSigner => new RefSigner(
            Subkeys::refs($c['matineeKeyStore']->rootKey()),
            fn (): int => $this->now
        );
        $this->productionClock = $this->container['matineeClock'];
        $this->productionShutdown = $this->container['matineeShutdown'];
        $this->container->offsetUnset('matineeClock');
        $this->container['matineeClock'] = $this->container->protect(fn (): int => $this->now);
        $this->container->offsetUnset('matineeShutdown');
        $this->container['matineeShutdown'] = $this->container->protect(function (callable $fn): void {
            $this->shutdowns[] = $fn;
        });

        $this->t = new FakeTransport();
        $this->container['matineePlexClientFactory'] = $this->container->protect(
            fn (string $baseUrl, #[\SensitiveParameter] string $token, ?string $machineId, string $deviceName): PlexClient
                => new PlexClient($baseUrl, $token, self::CLIENT_ID, $this->t, $machineId, $deviceName)
        );

        $this->log = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, $message, array $context = []): void
            {
                $this->lines[] = $level.' '.$message;
            }
        };
        $this->container['logger'] = $this->log;

        $this->alice = $this->user('alice');
        $this->bob = $this->user('bob');
        $this->t->respond(200, $this->fixture('identity'));
        $this->t->respond(200, $this->fixture('sections'));
        $this->serverId = $this->container['matineeServerModel']->connect($this->alice, 'Living room', self::BASE, self::TOKEN);
        $this->reset();
    }

    protected function tearDown(): void
    {
        foreach (['secret.key', '.lock'] as $f) {
            @unlink($this->keyDir.'/'.$f);
        }
        @rmdir($this->keyDir);
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function model(): PlaybackModel
    {
        return $this->container['matineePlaybackModel'];
    }

    private function signer(): RefSigner
    {
        return $this->container['matineeRefSigner'];
    }

    private function user(string $name): int
    {
        return (int) (new UserModel($this->container))->create(['username' => $name, 'role' => Role::APP_USER]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/plex/'.$name.'.json');
    }

    private function reset(): void
    {
        $this->t = new FakeTransport();
        $this->log->lines = [];
        $this->shutdowns = [];
    }

    private function share(bool $on = true): void
    {
        $this->container['matineeServerModel']->setShared($this->serverId, $this->alice, $on);
    }

    private function ref(int $userId, string $kind = 'media', string $r = '/library/metadata/3001', string $section = '3'): string
    {
        $ttl = $kind === 'browse' || $kind === 'art' ? 3600 : 28800;
        return $this->signer()->sign($userId, $this->serverId, $section, $kind, $r, $ttl);
    }

    /** An item answer for ratingKey 7 in section 3 with the given Media[0]. */
    private static function item(array $media, string $type = 'track', mixed $section = 3): string
    {
        return (string) json_encode(['MediaContainer' => ['librarySectionID' => $section, 'Metadata' => [[
            'ratingKey' => '7', 'type' => $type, 'title' => 'Song', 'parentTitle' => 'Album', 'grandparentTitle' => 'Artist',
            'Media' => [$media],
        ]]]]);
    }

    /** Queues the live sections, then the item. */
    private function queuePage(string $itemJson): void
    {
        $this->t->respond(200, self::SECTIONS)->respond(200, $itemJson);
    }

    /** @return array{sv: int, s: string, k: string, r: string} */
    private function verify(string $ref, int $userId, string $kind = 'media'): array
    {
        return $this->signer()->verify($ref, $userId, $kind);
    }

    private function expectForbidden(callable $fn, bool $plexAsked = false): void
    {
        try {
            $fn();
            $this->fail('expected MatineeForbiddenException');
        } catch (MatineeForbiddenException $e) {
            $this->assertSame('matinee.forbidden', $e->getMessage());
        }
        if (! $plexAsked) {
            $this->assertSame([], $this->t->requests, 'Plex was asked before the refusal');
        }
    }

    private function logText(): string
    {
        return implode("\n", $this->log->lines);
    }

    private function assertCleanLog(): void
    {
        $log = $this->logText();
        $this->assertStringNotContainsString(self::TOKEN, $log);
        $this->assertStringNotContainsString('plex.example.test', $log);
        $this->assertStringNotContainsString('/library', $log);
    }

    /** @return list<array> the matinee_transcodes rows, oldest first */
    private function rows(): array
    {
        return $this->container['db']->table(TranscodeModel::TABLE)->asc('id')->findAll();
    }

    private function media(int $userId, string $ref, ?string $range, PlexStreamSink $sink, ?int &$authorized = null): void
    {
        $authorized = 0;
        $this->model()->media($userId, $ref, $range, $sink, function () use (&$authorized): void {
            $authorized++;
        });
    }

    private function queueTranscode(array $chunks = ['ID3', 'more']): void
    {
        $this->t->streams(200, ['Content-Type' => 'audio/mpeg'], $chunks);
    }

    // ── wiring ───────────────────────────────────────────────────────────────

    public function testPluginRegistersThePlaybackModelAndItsSeams(): void
    {
        $this->assertInstanceOf(PlaybackModel::class, $this->container['matineePlaybackModel']);
        $this->assertSame(30, PlaybackModel::HEARTBEAT_EVERY);
        $this->assertSame(['mp3' => ['mp3'], 'flac' => ['flac'], 'mp4' => ['aac'], 'm4a' => ['aac']], PlaybackModel::DIRECT_AUDIO);
    }

    public function testTheProductionSeamsAreTheRealClockAndShutdownRegistrar(): void
    {
        $this->assertEqualsWithDelta(time(), ($this->productionClock)(), 2);
        $this->assertSame('register_shutdown_function', (new \ReflectionFunction($this->productionShutdown))->getName());
    }

    // ── page: direct or transcode (C1a, C5b) ─────────────────────────────────

    /** @dataProvider tracks */
    public function testThePageChoosesDirectOrTranscode(string $itemJson, string $ratingKey, ?string $part): void
    {
        $this->queuePage($itemJson);
        $page = $this->model()->page($this->alice, $this->ref($this->alice, 'media', '/library/metadata/'.$ratingKey));
        $this->assertSame('audio', $page['kind']);
        $this->assertSame(
            ['sv' => $this->serverId, 's' => '3', 'k' => 'media', 'r' => $part ?? '/library/metadata/'.$ratingKey],
            $this->verify($page['mediaRef'], $this->alice)
        );
    }

    public function tracks(): array
    {
        $mp3 = (string) file_get_contents(__DIR__.'/fixtures/plex/item-track-mp3.json');
        $flac = (string) file_get_contents(__DIR__.'/fixtures/plex/item-track-flac.json');
        $h263 = (string) file_get_contents(__DIR__.'/fixtures/plex/item-track-h263.json');
        $part = fn (string $ext): array => ['Part' => [['key' => '/library/parts/9/9/file.'.$ext]]];
        return [
            'mp3 direct'             => [$mp3, '3001', self::MP3_PART],
            'flac direct'            => [$flac, '3002', '/library/parts/5002/1700000001/file.flac'],
            'h263 transcode'         => [$h263, '3003', null],
            'mp4 aac direct'         => [self::item(['container' => 'mp4', 'audioCodec' => 'aac'] + $part('mp4')), '7', '/library/parts/9/9/file.mp4'],
            'm4a aac in .mp4 direct' => [self::item(['container' => 'm4a', 'audioCodec' => 'aac'] + $part('mp4')), '7', '/library/parts/9/9/file.mp4'],
            'mp4 aac in .m4a direct' => [self::item(['container' => 'mp4', 'audioCodec' => 'aac'] + $part('m4a')), '7', '/library/parts/9/9/file.m4a'],
            'mp4 alac transcode'     => [self::item(['container' => 'mp4', 'audioCodec' => 'alac'] + $part('m4a')), '7', null],
            'mp3 with mjpeg video'   => [self::item(['container' => 'mp3', 'audioCodec' => 'mp3', 'videoCodec' => 'mjpeg'] + $part('mp3')), '7', null],
            'ogg vorbis transcode'   => [self::item(['container' => 'ogg', 'audioCodec' => 'vorbis'] + $part('ogg')), '7', null],
            'mp3 in a .flac part'    => [self::item(['container' => 'mp3', 'audioCodec' => 'mp3'] + $part('flac')), '7', null],
            'flac codec in mp3'      => [self::item(['container' => 'mp3', 'audioCodec' => 'flac'] + $part('mp3')), '7', null],
            'no part'                => [self::item(['container' => 'mp3', 'audioCodec' => 'mp3']), '7', null],
        ];
    }

    public function testThePageCarriesTheServerTitlesAndAFreshRef(): void
    {
        $this->queuePage($this->fixture('item-track-h263'));
        $ref = $this->ref($this->alice, 'media', '/library/metadata/3003');
        $this->now += 3600;
        $page = $this->model()->page($this->alice, $ref);

        $this->assertSame(['kind', 'server', 'title', 'subtitle', 'mediaRef'], array_keys($page));
        $this->assertSame(['id' => $this->serverId, 'name' => 'Living room'], $page['server']);
        $this->assertSame('Fake Video Track', $page['title']);
        $this->assertSame('Fake Artist · Fake Album', $page['subtitle']);
        $this->assertNotSame($ref, $page['mediaRef']);
        // Re-minted with a full 8 h from now.
        $this->now += 28799;
        $this->assertSame('/library/metadata/3003', $this->verify($page['mediaRef'], $this->alice)['r']);
        $this->assertSame(
            [self::BASE.'/library/sections', self::BASE.'/library/metadata/3003'],
            array_column($this->t->requests, 'url')
        );
        $this->assertStringNotContainsString(self::TOKEN, json_encode($page));
    }

    public function testASubtitleSkipsEmptyTitles(): void
    {
        $this->queuePage((string) json_encode(['MediaContainer' => ['librarySectionID' => 3, 'Metadata' => [[
            'ratingKey' => '7', 'type' => 'track', 'title' => 'Song', 'grandparentTitle' => 'Artist',
        ]]]]));
        $this->assertSame('Artist', $this->model()->page($this->alice, $this->ref($this->alice, 'media', '/library/metadata/7'))['subtitle']);
    }

    // ── page refusals ────────────────────────────────────────────────────────

    public function testThePageRefusesOtherKindsOfRefWithNoPlexCall(): void
    {
        $this->expectForbidden(fn () => $this->model()->page($this->alice, $this->ref($this->alice, 'browse', '/library/sections/3/all')));
        $this->expectForbidden(fn () => $this->model()->page($this->alice, $this->ref($this->alice, 'art', '/library/metadata/7/thumb/1')));
        $this->expectForbidden(fn () => $this->model()->page($this->alice, 'junk'));
    }

    public function testThePageRefusesAPartPathRef(): void
    {
        $this->expectForbidden(fn () => $this->model()->page($this->alice, $this->ref($this->alice, 'media', self::MP3_PART)));
    }

    public function testThePageRefusesAMediaRefOnAMovie(): void
    {
        $this->queuePage(self::item(['container' => 'avi'], 'movie'));
        $this->expectForbidden(fn () => $this->model()->page($this->alice, $this->ref($this->alice, 'media', '/library/metadata/7')), true);
    }

    public function testThePageRefusesAnotherUsersRefAndAPrivateServer(): void
    {
        $this->expectForbidden(fn () => $this->model()->page($this->alice, $this->ref($this->bob)));
        $this->expectForbidden(fn () => $this->model()->page($this->bob, $this->ref($this->bob)));
    }

    public function testThePageRefusesASectionThatIsNotLive(): void
    {
        $this->t->respond(200, self::SECTIONS);
        $this->expectForbidden(fn () => $this->model()->page($this->alice, $this->ref($this->alice, 'media', '/library/metadata/7', '4')), true);
        $this->assertCount(1, $this->t->requests);
    }

    /** @dataProvider otherSections */
    public function testThePageRefusesAnItemFromAnotherSectionOrNone(mixed $section): void
    {
        $this->queuePage(self::item(['container' => 'mp3', 'audioCodec' => 'mp3'], 'track', $section));
        $this->expectForbidden(fn () => $this->model()->page($this->alice, $this->ref($this->alice, 'media', '/library/metadata/7')), true);
    }

    public function otherSections(): array
    {
        return ['another section' => [1], 'none' => [null], 'not a key' => ['3a']];
    }

    // ── media: direct parts (C1b) ────────────────────────────────────────────

    public function testADirectPartStreamsTheClampedWindow(): void
    {
        $sink = new RecordingSink();
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-9/10'], ['0123456789']);
        $this->media($this->alice, $this->ref($this->alice, 'media', self::MP3_PART), 'bytes=0-', $sink, $authorized);

        $this->assertSame(1, $authorized);
        [$req] = $this->t->requests;
        $this->assertSame(self::BASE.self::MP3_PART, $req['url']);
        $this->assertSame('bytes=0-'.(ByteRange::WINDOW - 1), $req['headers']['Range']);
        $this->assertSame(ByteRange::WINDOW, $req['opts']['maxBytes']);
        $this->assertSame(206, $sink->status);
        $this->assertSame(['content-type' => 'audio/mpeg', 'content-range' => 'bytes 0-9/10', 'content-length' => '10', 'accept-ranges' => 'bytes'], $sink->headers);
        $this->assertSame('0123456789', $sink->body);
        $this->assertSame([], $this->rows(), 'a direct window is not a long stream');
        $this->assertSame([], $this->shutdowns);
    }

    public function testAnUnsatisfiableRangeIsARangeException(): void
    {
        $sink = new RecordingSink();
        $this->t->streams(416, ['Content-Range' => 'bytes */50'], []);
        try {
            $this->media($this->alice, $this->ref($this->alice, 'media', self::MP3_PART), 'bytes=100-', $sink);
            $this->fail('expected MatineeRangeException');
        } catch (MatineeRangeException $e) {
            $this->assertSame(50, $e->total);
        }
        $this->assertSame('bytes=100-'.(100 + ByteRange::WINDOW - 1), $this->t->requests[0]['headers']['Range']);
        $this->assertSame(0, $sink->opens);
    }

    public function testOnAuthorizedRunsBeforeAnyPlexCall(): void
    {
        $seen = null;
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-9/10'], ['0123456789']);
        $this->model()->media($this->alice, $this->ref($this->alice, 'media', self::MP3_PART), null, new RecordingSink(), function () use (&$seen): void {
            $seen = count($this->t->requests);
        });
        $this->assertSame(0, $seen);
    }

    // ── media: transcodes (C1c, C3f, C3g, C4a) ───────────────────────────────

    public function testATranscodeClaimsAnMp3RowAndStopsItAfterwards(): void
    {
        $sink = new RecordingSink();
        $this->queueTranscode();
        $this->media($this->alice, $this->ref($this->alice, 'media', '/library/metadata/3003'), 'bytes=0-', $sink, $authorized);

        $this->assertSame(1, $authorized);
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(['mp3', $this->alice, $this->serverId, 1], [$rows[0]['kind'], (int) $rows[0]['user_id'], (int) $rows[0]['server_id'], (int) $rows[0]['stopped']]);
        [$req] = $this->t->requests;
        $this->assertStringContainsString('/music/:/transcode/universal/start.mp3?path=%2Flibrary%2Fmetadata%2F3003&', $req['url']);
        $this->assertStringEndsWith('&session='.$rows[0]['session'], $req['url']);
        $this->assertArrayNotHasKey('Range', $req['headers']);
        $this->assertSame(['content-type' => 'audio/mpeg', 'accept-ranges' => 'none'], $sink->headers);
        $this->assertSame('ID3more', $sink->body);
    }

    public function testATranscodeThatFailsStillStopsItsRow(): void
    {
        $this->t->streams(500, ['Content-Type' => 'text/html'], ['UPSTREAM-BODY']);
        try {
            $this->media($this->alice, $this->ref($this->alice, 'media', '/library/metadata/3003'), null, new RecordingSink());
            $this->fail('expected MatineeConnectException');
        } catch (MatineeConnectException) {
        }
        $this->assertSame(1, (int) $this->rows()[0]['stopped']);
    }

    public function testATranscodeRegistersOneShutdownNetThatStopsOnlyAnActiveRow(): void
    {
        $stoppedWhenCalled = null;
        $sink = new PlaybackCallbackSink(function (int $n) use (&$stoppedWhenCalled): void {
            if ($n === 1) {
                $this->assertCount(1, $this->shutdowns);
                $this->assertSame(0, (int) $this->rows()[0]['stopped']);
                ($this->shutdowns[0])(); // as if the request died here: the net stops the active row
                $stoppedWhenCalled = (int) $this->rows()[0]['stopped'];
            }
        });
        $this->queueTranscode(['a']);
        $this->media($this->alice, $this->ref($this->alice, 'media', '/library/metadata/3003'), null, $sink);

        $this->assertSame(1, $stoppedWhenCalled);
        $this->assertCount(1, $this->shutdowns);
        // After the request stopped the row, the net is a no-op.
        ($this->shutdowns[0])();
        $this->assertSame(1, (int) $this->rows()[0]['stopped']);
        $this->assertCount(1, $this->rows());
    }

    public function testAClientThatGoesAwayEndsTheStreamAndStopsTheRow(): void
    {
        $sink = new RecordingSink(false, 1);
        $this->queueTranscode(['first', 'second', 'third']);
        $this->media($this->alice, $this->ref($this->alice, 'media', '/library/metadata/3003'), null, $sink);
        $this->assertSame('first', $sink->body);
        $this->assertSame(1, (int) $this->rows()[0]['stopped']);
    }

    public function testATranscodeAtTheCapIsBusyWithNoPlexRequest(): void
    {
        foreach (['00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002'] as $session) {
            $this->container['db']->table(TranscodeModel::TABLE)->insert([
                'server_id' => $this->serverId, 'user_id' => $this->alice, 'session' => $session, 'kind' => 'hls',
                'created_at' => self::T0, 'last_seen_at' => self::T0, 'stopped' => 0,
            ]);
        }
        $authorized = 0;
        try {
            $this->media($this->alice, $this->ref($this->alice, 'media', '/library/metadata/3003'), null, new RecordingSink(), $authorized);
            $this->fail('expected MatineeBusyException');
        } catch (MatineeBusyException) {
        }
        $this->assertSame(1, $authorized);
        $this->assertSame([], $this->t->requests);
        $this->assertCount(2, $this->rows());
        $this->assertSame([], $this->shutdowns);
    }

    public function testHeartbeatsRunEveryThirtySecondsOfStreaming(): void
    {
        $times = [self::T0 + 10, self::T0 + 31, self::T0 + 65];
        $seen = [];
        $sink = new PlaybackCallbackSink(function () use (&$times, &$seen): void {
            $seen[] = (int) $this->rows()[0]['last_seen_at'] - self::T0;
            if ($times !== []) {
                $this->now = array_shift($times);
            }
        });
        $this->queueTranscode(['a', 'b', 'c', 'd']);
        $this->media($this->alice, $this->ref($this->alice, 'media', '/library/metadata/3003'), null, $sink);

        // Writes at t = 0, 10, 31 and 65: beats at 31 and 65 only.
        $this->assertSame([0, 0, 31, 65], $seen);
        $this->assertSame('abcd', $sink->body);
    }

    public function testAHeartbeatThatFailsIsLoggedAndTheStreamGoesOn(): void
    {
        $db = $this->container['db'];
        $sink = new PlaybackCallbackSink(function (int $n) use ($db): void {
            if ($n === 1) {
                // The heartbeat at t = 31 hits a missing table.
                $db->getConnection()->exec('ALTER TABLE '.TranscodeModel::TABLE.' RENAME TO matinee_transcodes_gone');
                $this->now = self::T0 + 31;
            } elseif ($n === 2) {
                $db->getConnection()->exec('ALTER TABLE matinee_transcodes_gone RENAME TO '.TranscodeModel::TABLE);
            }
        });
        $this->queueTranscode(['first', 'second', 'third']);
        $this->media($this->alice, $this->ref($this->alice, 'media', '/library/metadata/3003'), null, $sink);

        $this->assertSame('firstsecondthird', $sink->body);
        $this->assertStringContainsString('Matinee: heartbeat failed (server '.$this->serverId.')', $this->logText());
        $this->assertStringNotContainsString('stream ended early', $this->logText());
        $this->assertSame(1, (int) $this->rows()[0]['stopped']);
    }

    /** Addendum: a heartbeat that returns false (the row was stopped or went stale) ends the stream. */
    public function testAHeartbeatThatFindsTheRowStoppedEndsTheStream(): void
    {
        $sink = new PlaybackCallbackSink(function (int $n): void {
            if ($n === 1) {
                $this->container['matineeTranscodeModel']->stop((int) $this->rows()[0]['id']);
                $this->now = self::T0 + 31;
            }
        });
        $this->queueTranscode(['first', 'second', 'third']);
        $this->media($this->alice, $this->ref($this->alice, 'media', '/library/metadata/3003'), null, $sink);
        $this->assertSame('first', $sink->body);
    }

    // ── media: refusals ──────────────────────────────────────────────────────

    public function testMediaRefusesOtherKindsWithNoPlexCallAndNoAuthorization(): void
    {
        foreach ([
            $this->ref($this->alice, 'hls', '/library/metadata/101', '1'),
            $this->ref($this->alice, 'browse', '/library/sections/3/all'),
            $this->ref($this->alice, 'art', '/library/metadata/7/thumb/1'),
            $this->ref($this->bob),
        ] as $ref) {
            $authorized = 0;
            $this->expectForbidden(fn () => $this->media($this->alice, $ref, null, new RecordingSink(), $authorized));
            $this->assertSame(0, $authorized);
        }
    }

    public function testAMediaRefStopsWorkingOnceTheServerIsUnshared(): void
    {
        $this->share();
        $ref = $this->ref($this->bob, 'media', self::MP3_PART);
        $this->share(false);
        $authorized = 0;
        $this->expectForbidden(fn () => $this->media($this->bob, $ref, null, new RecordingSink(), $authorized));
        $this->assertSame(0, $authorized);
    }

    public function testAnExpiredMediaRefIsRefused(): void
    {
        $ref = $this->ref($this->alice, 'media', self::MP3_PART);
        $this->now += 28800;
        $this->expectForbidden(fn () => $this->media($this->alice, $ref, null, new RecordingSink()));
    }

    // ── failures (C3g) ───────────────────────────────────────────────────────

    public function testAPlexFailureBeforeTheSinkOpensIsTheConnectFailureLoggedOnce(): void
    {
        $sink = new RecordingSink();
        $this->t->streams(401, ['Content-Type' => 'text/plain'], [self::TOKEN]);
        try {
            $this->media($this->alice, $this->ref($this->alice, 'media', self::MP3_PART), null, $sink);
            $this->fail('expected MatineeConnectException');
        } catch (MatineeConnectException $e) {
            $this->assertSame('matinee.connect_failed', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame(0, $sink->opens);
        $this->assertCount(1, $this->log->lines);
        $this->assertMatchesRegularExpression(
            '/\A\w+ Matinee: playback failed at part \(server '.$this->serverId.', plex\.token_rejected\)\z/',
            $this->log->lines[0]
        );
        $this->assertCleanLog();
    }

    public function testATranscodeFailureIsLoggedAtTheTranscodeStage(): void
    {
        $this->t->streams(200, ['Content-Type' => 'audio/ogg'], ['x']);
        try {
            $this->media($this->alice, $this->ref($this->alice, 'media', '/library/metadata/3003'), null, new RecordingSink());
            $this->fail('expected MatineeConnectException');
        } catch (MatineeConnectException) {
        }
        $this->assertSame(['info Matinee: playback failed at transcode (server '.$this->serverId.', plex.bad_type)'], $this->log->lines);
    }

    public function testAPageFailureIsLoggedAtItsStage(): void
    {
        $this->t->respond(200, self::SECTIONS)->respond(500, 'UPSTREAM-BODY');
        try {
            $this->model()->page($this->alice, $this->ref($this->alice));
            $this->fail('expected MatineeConnectException');
        } catch (MatineeConnectException) {
        }
        $this->assertSame(['info Matinee: playback failed at item (server '.$this->serverId.', plex.http_error)'], $this->log->lines);

        $this->reset();
        $this->t->respond(401, 'x');
        try {
            $this->model()->page($this->alice, $this->ref($this->alice));
            $this->fail('expected MatineeConnectException');
        } catch (MatineeConnectException) {
        }
        $this->assertSame(['info Matinee: playback failed at sections (server '.$this->serverId.', plex.token_rejected)'], $this->log->lines);
    }

    public function testATokenThatWillNotOpenFailsAtTheClientStage(): void
    {
        $this->container['db']->table('matinee_servers')->eq('id', $this->serverId)->update(['token_ct' => base64_encode(random_bytes(40))]);
        $authorized = 0;
        try {
            $this->media($this->alice, $this->ref($this->alice, 'media', self::MP3_PART), null, new RecordingSink(), $authorized);
            $this->fail('expected MatineeConnectException');
        } catch (MatineeConnectException) {
        }
        // Authorized (C3d: the session is dropped once the ref and the server pass), then the client could not be built.
        $this->assertSame(1, $authorized);
        $this->assertSame(['info Matinee: playback failed at client (server '.$this->serverId.', token unopenable)'], $this->log->lines);
        $this->assertSame([], $this->t->requests);
    }

    public function testAFailureAfterTheSinkOpenedIsLoggedAndAppendsNothing(): void
    {
        $sink = new RecordingSink();
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-999/5000'], [str_repeat('a', 600), str_repeat('b', 900)]);
        $this->media($this->alice, $this->ref($this->alice, 'media', self::MP3_PART), 'bytes=0-999', $sink);

        $this->assertSame(1, $sink->opens);
        $this->assertSame(1000, strlen($sink->body));
        $this->assertSame(['warning Matinee: stream ended early (server '.$this->serverId.', plex.too_large)'], $this->log->lines);
    }

    public function testATranscodeThatDiesMidStreamIsLoggedAndStopsItsRow(): void
    {
        $sink = new PlaybackCallbackSink(function (): void {
            throw new \RuntimeException('client sink broke '.self::TOKEN);
        });
        $this->queueTranscode(['a', 'b']);
        $this->media($this->alice, $this->ref($this->alice, 'media', '/library/metadata/3003'), null, $sink);
        $this->assertSame('a', $sink->body);
        // PlexClient reports a non-Plex failure from inside the transfer as plex.unreachable, with nothing chained.
        $this->assertSame(['warning Matinee: stream ended early (server '.$this->serverId.', plex.unreachable)'], $this->log->lines);
        $this->assertCleanLog();
        $this->assertSame(1, (int) $this->rows()[0]['stopped']);
    }

    // ── identity (C9) ────────────────────────────────────────────────────────

    public function testPlexSeesTheViewerNotTheOwner(): void
    {
        $this->share();
        $this->queuePage($this->fixture('item-track-mp3'));
        $page = $this->model()->page($this->bob, $this->ref($this->bob, 'media', '/library/metadata/3001'));
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-9/10'], ['0123456789']);
        $this->queueTranscode();
        $this->media($this->bob, $page['mediaRef'], null, new RecordingSink());
        $this->media($this->bob, $this->ref($this->bob, 'media', '/library/metadata/3003'), null, new RecordingSink());

        $this->assertCount(4, $this->t->requests);
        foreach ($this->t->requests as $req) {
            $this->assertSame('Matinee (bob)', $req['headers']['X-Plex-Device-Name']);
        }
    }
}
