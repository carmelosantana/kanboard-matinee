<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/support/ReleasesContainer.php';
require_once __DIR__.'/support/TempInflight.php';
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
use PHPUnit\Framework\Attributes\DataProvider;

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
    use MatineeReleasesContainer;
    use MatineeTempInflight;

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
        $this->useTempInflightGate();

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
        $this->cleanInflight();
        parent::tearDown();
        $this->releaseContainer();
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

    /** How often the last media(), hlsMaster() or hlsChild() call ran $onAuthorized (a property, so arrow functions see it). */
    private int $authorized = 0;

    private function media(int $userId, string $ref, ?string $range, PlexStreamSink $sink): void
    {
        $this->authorized = 0;
        $this->model()->media($userId, $ref, $range, $sink, function (): void {
            $this->authorized++;
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
    #[DataProvider('tracks')]
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

    public static function tracks(): array
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
            'mp4 aac with a malformed video codec' => [self::item(['container' => 'mp4', 'audioCodec' => 'aac', 'videoCodec' => 'h264-main'] + $part('mp4')), '7', null],
            'mp4 aac with an empty video codec'    => [self::item(['container' => 'mp4', 'audioCodec' => 'aac', 'videoCodec' => ''] + $part('mp4')), '7', null],
            'mp3 with a null video codec direct'   => [self::item(['container' => 'mp3', 'audioCodec' => 'mp3', 'videoCodec' => null] + $part('mp3')), '7', '/library/parts/9/9/file.mp3'],
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
    #[DataProvider('otherSections')]
    public function testThePageRefusesAnItemFromAnotherSectionOrNone(mixed $section): void
    {
        $this->queuePage(self::item(['container' => 'mp3', 'audioCodec' => 'mp3'], 'track', $section));
        $this->expectForbidden(fn () => $this->model()->page($this->alice, $this->ref($this->alice, 'media', '/library/metadata/7')), true);
    }

    public static function otherSections(): array
    {
        return ['another section' => [1], 'none' => [null], 'not a key' => ['3a']];
    }

    // ── media: direct parts (C1b) ────────────────────────────────────────────

    public function testADirectPartStreamsTheClampedWindow(): void
    {
        $sink = new RecordingSink();
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-9/10'], ['0123456789']);
        $this->media($this->alice, $this->ref($this->alice, 'media', self::MP3_PART), 'bytes=0-', $sink);

        $this->assertSame(1, $this->authorized);
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
        $this->media($this->alice, $this->ref($this->alice, 'media', '/library/metadata/3003'), 'bytes=0-', $sink);

        $this->assertSame(1, $this->authorized);
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

    /** m4: the stop and the shutdown net use the id claim() returned; nothing looks the row up by its session again. */
    public function testATranscodeUsesTheClaimedRowIdWithNoSecondLookup(): void
    {
        $db = $this->container['db'];
        $db->getStatementHandler()->withLogging();
        $db->setLogMessages([]);
        $this->queueTranscode();
        $this->media($this->alice, $this->ref($this->alice, 'media', '/library/metadata/3003'), null, new RecordingSink());

        $sql = $db->getLogMessages();
        $this->assertNotSame([], $sql, 'queries were logged');
        $this->assertSame([], array_values(preg_grep('/\bWHERE\b.*\bsession\b/i', $sql)), 'a lookup by session');
        $this->assertSame(1, (int) $this->rows()[0]['stopped']);
        $this->assertCount(1, $this->shutdowns);
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
        try {
            $this->media($this->alice, $this->ref($this->alice, 'media', '/library/metadata/3003'), null, new RecordingSink());
            $this->fail('expected MatineeBusyException');
        } catch (MatineeBusyException) {
        }
        $this->assertSame(1, $this->authorized);
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
            $this->expectForbidden(fn () => $this->media($this->alice, $ref, null, new RecordingSink()));
            $this->assertSame(0, $this->authorized);
        }
    }

    public function testAMediaRefStopsWorkingOnceTheServerIsUnshared(): void
    {
        $this->share();
        $ref = $this->ref($this->bob, 'media', self::MP3_PART);
        $this->share(false);
        $this->expectForbidden(fn () => $this->media($this->bob, $ref, null, new RecordingSink()));
        $this->assertSame(0, $this->authorized);
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
        try {
            $this->media($this->alice, $this->ref($this->alice, 'media', self::MP3_PART), null, new RecordingSink());
            $this->fail('expected MatineeConnectException');
        } catch (MatineeConnectException) {
        }
        // Authorized (C3d: the session is dropped once the ref and the server pass), then the client could not be built.
        $this->assertSame(1, $this->authorized);
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

    // ── HLS: stop, ping and sweep (Task 13; C2g, C4d, C5d) ───────────────────

    /** Claims a row at the current clock, then sets its last_seen_at directly; returns the row. */
    private function claimRow(int $userId, string $kind = 'hls', ?int $lastSeen = null): array
    {
        ['id' => $id] = $this->container['matineeTranscodeModel']->claim($userId, $this->serverId, $kind, $this->now);
        if ($lastSeen !== null) {
            $this->container['db']->table(TranscodeModel::TABLE)->eq('id', $id)->update(['last_seen_at' => $lastSeen]);
        }
        return $this->row($id);
    }

    private function row(int $id): array
    {
        return $this->container['db']->table(TranscodeModel::TABLE)->eq('id', $id)->findOne();
    }

    private function stopUrl(string $session): string
    {
        return self::BASE.'/video/:/transcode/universal/stop?session='.$session;
    }

    public function testStopCallsPlexOnceThenIsANoOp(): void
    {
        $row = $this->claimRow($this->alice);
        $this->t->respond(200, '');
        $this->model()->stop($this->alice, $row['session']);

        $this->assertSame(1, (int) $this->row((int) $row['id'])['stopped']);
        $this->assertSame([$this->stopUrl($row['session'])], array_column($this->t->requests, 'url'));
        $this->assertSame(['timeout' => 3], $this->t->requests[0]['opts']);
        $this->assertSame('Matinee (alice)', $this->t->requests[0]['headers']['X-Plex-Device-Name']);

        $this->model()->stop($this->alice, $row['session']);
        $this->assertCount(1, $this->t->requests, 'a second stop asked Plex again');
    }

    public function testStopRefusesAnotherUsersOrAnUnknownSession(): void
    {
        $row = $this->claimRow($this->alice);
        $this->expectForbidden(fn () => $this->model()->stop($this->bob, $row['session']));
        $this->expectForbidden(fn () => $this->model()->stop($this->alice, '00000000-0000-4000-8000-000000000009'));
        $this->expectForbidden(fn () => $this->model()->stop($this->alice, 'junk'));
        $this->assertSame(0, (int) $this->row((int) $row['id'])['stopped']);
    }

    public function testStoppingAnMp3RowNeverAsksPlex(): void
    {
        $row = $this->claimRow($this->alice, 'mp3');
        $this->model()->stop($this->alice, $row['session']);
        $this->assertSame(1, (int) $this->row((int) $row['id'])['stopped']);
        $this->assertSame([], $this->t->requests);
    }

    public function testAPlexStopFailureIsSwallowedAndLoggedOnce(): void
    {
        $row = $this->claimRow($this->alice);
        $this->t->respond(500, 'UPSTREAM-BODY '.self::TOKEN);
        $this->model()->stop($this->alice, $row['session']);

        $this->assertSame(1, (int) $this->row((int) $row['id'])['stopped']);
        $this->assertSame(['warning Matinee: transcode stop failed (server '.$this->serverId.', plex.http_error)'], $this->log->lines);
        $this->assertCleanLog();
        $this->assertSame('Matinee: transcode stop failed (server %d, %s)', PlaybackModel::STOP_FAILED_LOG);
    }

    /** Anything without a reason of its own (here a LogicException while the client is built) logs as "error". */
    public function testAnUnexpectedStopFailureIsLoggedAsError(): void
    {
        $row = $this->claimRow($this->alice);
        $this->container['matineePlexClientFactory'] = $this->container->protect(static function (): PlexClient {
            throw new \LogicException('UNEXPECTED '.self::TOKEN);
        });
        $this->model()->stop($this->alice, $row['session']);

        $this->assertSame(1, (int) $this->row((int) $row['id'])['stopped']);
        $this->assertSame(['warning Matinee: transcode stop failed (server '.$this->serverId.', error)'], $this->log->lines);
        $this->assertCleanLog();
    }

    public function testStopNeedsNoBrowseRightAndSkipsPlexOnADisabledServer(): void
    {
        $this->share();
        $row = $this->claimRow($this->bob);
        $this->share(false);
        $this->t->respond(200, '');
        $this->model()->stop($this->bob, $row['session']);
        $this->assertSame(1, (int) $this->row((int) $row['id'])['stopped']);
        $this->assertCount(1, $this->t->requests);

        $this->reset();
        $other = $this->claimRow($this->alice);
        $this->container['matineeServerModel']->setEnabled($this->serverId, false, 1);
        $this->model()->stop($this->alice, $other['session']);
        $this->assertSame(1, (int) $this->row((int) $other['id'])['stopped']);
        $this->assertSame([], $this->t->requests);
        $this->assertSame([], $this->log->lines);
    }

    public function testPingMovesLastSeenToNow(): void
    {
        $row = $this->claimRow($this->alice, 'hls', self::T0 - 100);
        $this->model()->ping($this->alice, $row['session']);
        $this->assertSame(self::T0, (int) $this->row((int) $row['id'])['last_seen_at']);
        $this->assertSame([], $this->t->requests);
    }

    public function testPingRefusesAStoppedAnotherUsersAnMp3OrAStaleRow(): void
    {
        $stopped = $this->claimRow($this->alice);
        $this->container['matineeTranscodeModel']->stop((int) $stopped['id']);
        // A stopped row is refused on sight: no write reaches the table (the heartbeat would refuse it too).
        $db = $this->container['db'];
        $db->getStatementHandler()->withLogging();
        $before = count($db->getLogMessages());
        $this->expectForbidden(fn () => $this->model()->ping($this->alice, $stopped['session']));
        $sql = array_slice($db->getLogMessages(), $before);
        $this->assertNotSame([], $sql, 'queries were logged');
        $this->assertSame([], array_values(preg_grep('/\bUPDATE\b/i', $sql)), 'a stopped row was written');
        $this->assertSame(self::T0, (int) $this->row((int) $stopped['id'])['last_seen_at']);

        $mine = $this->claimRow($this->alice, 'hls', self::T0 - 50);
        $this->expectForbidden(fn () => $this->model()->ping($this->bob, $mine['session']));
        $this->assertSame(self::T0 - 50, (int) $this->row((int) $mine['id'])['last_seen_at']);

        $mp3 = $this->claimRow($this->alice, 'mp3', self::T0 - 50);
        $this->expectForbidden(fn () => $this->model()->ping($this->alice, $mp3['session']));
        $this->assertSame(self::T0 - 50, (int) $this->row((int) $mp3['id'])['last_seen_at']);

        // Addendum: a heartbeat that returns false (the row went stale) is Forbidden; the row is never revived.
        $this->container['db']->table(TranscodeModel::TABLE)->eq('id', (int) $mine['id'])->update(['last_seen_at' => self::T0 - 121]);
        $this->expectForbidden(fn () => $this->model()->ping($this->alice, $mine['session']));
        $this->assertSame(self::T0 - 121, (int) $this->row((int) $mine['id'])['last_seen_at']);
    }

    public function testPingAfterTheServerIsUnsharedStopsTheRowAndIsForbidden(): void
    {
        $this->share();
        $row = $this->claimRow($this->bob, 'hls', self::T0 - 50);
        $this->share(false);
        $this->t->respond(200, '');
        $this->expectForbidden(fn () => $this->model()->ping($this->bob, $row['session']), true);
        $after = $this->row((int) $row['id']);
        $this->assertSame(1, (int) $after['stopped']);
        $this->assertSame(self::T0 - 50, (int) $after['last_seen_at']);
        $this->assertSame([$this->stopUrl($row['session'])], array_column($this->t->requests, 'url'));
    }

    public function testSweepStopsTheTwoOldestStaleRows(): void
    {
        $old = $this->claimRow($this->alice, 'hls', self::T0 - 500);
        $older = $this->claimRow($this->alice, 'mp3', self::T0 - 600);
        $newest = $this->claimRow($this->alice, 'hls', self::T0 - 300);
        $active = $this->claimRow($this->alice, 'hls');
        $this->t->respond(200, '');

        $this->assertSame(2, $this->model()->sweep());
        $this->assertSame([1, 1, 0, 0], array_map(fn (array $r): int => (int) $this->row((int) $r['id'])['stopped'], [$old, $older, $newest, $active]));
        // The hls row got a Plex stop; the mp3 row did not.
        $this->assertSame([$this->stopUrl($old['session'])], array_column($this->t->requests, 'url'));

        $this->t->respond(200, '');
        $this->assertSame(1, $this->model()->sweep());
        $this->assertSame(1, (int) $this->row((int) $newest['id'])['stopped']);
        $this->assertSame(0, (int) $this->row((int) $active['id'])['stopped']);
        $this->assertSame(0, $this->model()->sweep());
    }

    public function testSweepMarksARowOnADisabledServerStoppedWithNoPlexCall(): void
    {
        $row = $this->claimRow($this->alice, 'hls', self::T0 - 500);
        $this->container['matineeServerModel']->setEnabled($this->serverId, false, 1);
        $this->assertSame(1, $this->model()->sweep());
        $this->assertSame(1, (int) $this->row((int) $row['id'])['stopped']);
        $this->assertSame([], $this->t->requests);
    }

    public function testAStopTimeoutDoesNotEndTheSweep(): void
    {
        $first = $this->claimRow($this->alice, 'hls', self::T0 - 600);
        $second = $this->claimRow($this->alice, 'hls', self::T0 - 500);
        $this->t->fail(new \RuntimeException('timed out'))->respond(200, '');

        $this->assertSame(2, $this->model()->sweep());
        $this->assertSame(1, (int) $this->row((int) $first['id'])['stopped']);
        $this->assertSame(1, (int) $this->row((int) $second['id'])['stopped']);
        $this->assertSame([$this->stopUrl($first['session']), $this->stopUrl($second['session'])], array_column($this->t->requests, 'url'));
        $this->assertSame(['warning Matinee: transcode stop failed (server '.$this->serverId.', plex.unreachable)'], $this->log->lines);
    }

    public function testMaybeSweepRollsTheDice(): void
    {
        $row = $this->claimRow($this->alice, 'mp3', self::T0 - 500);
        $rolls = 0;
        $this->container->offsetUnset('matineeSweepDice');
        $this->container['matineeSweepDice'] = $this->container->protect(function () use (&$rolls): bool {
            $rolls++;
            return false;
        });
        $this->model()->maybeSweep();
        $this->assertSame(1, $rolls);
        $this->assertSame(0, (int) $this->row((int) $row['id'])['stopped']);

        $this->container->offsetUnset('matineeSweepDice');
        $this->container['matineeSweepDice'] = $this->container->protect(fn (): bool => true);
        $this->model()->maybeSweep();
        $this->assertSame(1, (int) $this->row((int) $row['id'])['stopped']);
    }

    public function testTheProductionDiceIsOneInTen(): void
    {
        $dice = $this->container['matineeSweepDice'];
        $hits = 0;
        for ($i = 0; $i < 2000; $i++) {
            $hits += $dice() === true ? 1 : 0;
        }
        $this->assertGreaterThan(100, $hits);
        $this->assertLessThan(320, $hits);
    }

    // ── HLS: the page claim, the master and children (Task 14; C2c, C5c, C5d) ─

    private const MOVIE = '/library/metadata/7';
    private const PLAYLIST_TYPE = ['Content-Type' => 'application/vnd.apple.mpegurl'];

    private function hlsRef(int $userId, string $r = self::MOVIE, string $section = '1', ?int $serverId = null): string
    {
        return $this->signer()->sign($userId, $serverId ?? $this->serverId, $section, 'hls', $r, 28800);
    }

    /** Queues the live sections, then a movie (or $type) item in section 1. */
    private function queueHlsPage(string $type = 'movie'): void
    {
        $this->queuePage(self::item(['container' => 'mkv', 'videoCodec' => 'h264'], $type, 1));
    }

    private static function child(string $session, string $rest = 'base/index.m3u8'): string
    {
        return '/video/:/transcode/universal/session/'.$session.'/'.$rest;
    }

    private static function master(string $session): string
    {
        return "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-STREAM-INF:PROGRAM-ID=1,BANDWIDTH=8000000,RESOLUTION=1920x1080,CODECS=\"avc1.640028,mp4a.40.2\"\n"
            .'session/'.$session."/base/index.m3u8\n";
    }

    private static function proxyUrl(): \Closure
    {
        return static fn (string $u): string => 'P('.$u.')';
    }

    private function hlsMaster(int $userId, string $ref, string $session): string
    {
        $this->authorized = 0;
        return $this->model()->hlsMaster($userId, $ref, $session, self::proxyUrl(), function (): void {
            $this->authorized++;
        });
    }

    private function hlsChild(int $userId, string $ref, string $u, PlexStreamSink $sink): ?string
    {
        $this->authorized = 0;
        return $this->model()->hlsChild($userId, $ref, $u, $sink, self::proxyUrl(), function (): void {
            $this->authorized++;
        });
    }

    /** A second server of Alice's, connected through the fake. */
    private function secondServer(): int
    {
        $this->t->respond(200, $this->fixture('identity'));
        $this->t->respond(200, $this->fixture('sections'));
        $id = $this->container['matineeServerModel']->connect($this->alice, 'Den', 'https://den.example.test:32400', self::TOKEN);
        $this->reset();
        return $id;
    }

    public function testAnHlsPageClaimsOneRowAndReturnsItsSession(): void
    {
        $this->queueHlsPage();
        $ref = $this->hlsRef($this->alice);
        $this->now += 3600;
        $page = $this->model()->page($this->alice, $ref);

        $this->assertSame(['kind', 'server', 'title', 'subtitle', 'session', 'hlsRef'], array_keys($page));
        $this->assertSame('hls', $page['kind']);
        $this->assertSame(['id' => $this->serverId, 'name' => 'Living room'], $page['server']);
        $this->assertSame('Song', $page['title']);
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(
            [$page['session'], 'hls', $this->alice, $this->serverId, 0, self::T0 + 3600],
            [$rows[0]['session'], $rows[0]['kind'], (int) $rows[0]['user_id'], (int) $rows[0]['server_id'], (int) $rows[0]['stopped'], (int) $rows[0]['last_seen_at']]
        );
        // The session outlives this request: no shutdown net stops it.
        $this->assertSame([], $this->shutdowns);
        // hlsRef is the item-path hls ref, re-minted for a full 8 h.
        $this->assertNotSame($ref, $page['hlsRef']);
        $this->now += 28799;
        $this->assertSame(['sv' => $this->serverId, 's' => '1', 'k' => 'hls', 'r' => self::MOVIE], $this->verify($page['hlsRef'], $this->alice, 'hls'));
        $this->assertSame([self::BASE.'/library/sections', self::BASE.self::MOVIE], array_column($this->t->requests, 'url'));
    }

    public function testAnEpisodePlaysAndATrackIsRefusedOnAnHlsPage(): void
    {
        $this->queueHlsPage('episode');
        $this->assertSame('hls', $this->model()->page($this->alice, $this->hlsRef($this->alice))['kind']);

        $this->reset();
        $this->queueHlsPage('track');
        $this->expectForbidden(fn () => $this->model()->page($this->alice, $this->hlsRef($this->alice)), true);
        $this->assertCount(1, $this->rows(), 'a refused page claimed a row');
    }

    public function testAnHlsPageAtTheCapIsBusyAndClaimsNothing(): void
    {
        $this->claimRow($this->alice);
        $this->claimRow($this->alice);
        $this->queueHlsPage();
        try {
            $this->model()->page($this->alice, $this->hlsRef($this->alice));
            $this->fail('expected MatineeBusyException');
        } catch (MatineeBusyException) {
        }
        $this->assertCount(2, $this->rows());
    }

    public function testThePageSweepsStaleRowsBeforeItClaims(): void
    {
        // Two stale rows on the per-user cap: the page succeeds, and the sweep stopped both through Plex.
        $a = $this->claimRow($this->alice, 'hls', self::T0 - 600);
        $b = $this->claimRow($this->alice, 'hls', self::T0 - 500);
        $this->queueHlsPage();
        $this->t->respond(200, '')->respond(200, '');
        $page = $this->model()->page($this->alice, $this->hlsRef($this->alice));
        $this->assertSame([1, 1], [(int) $this->row((int) $a['id'])['stopped'], (int) $this->row((int) $b['id'])['stopped']]);
        $this->assertSame(
            [self::BASE.'/library/sections', self::BASE.self::MOVIE, $this->stopUrl($a['session']), $this->stopUrl($b['session'])],
            array_column($this->t->requests, 'url')
        );
        $this->assertSame($page['session'], $this->rows()[2]['session']);
    }

    public function testABusyPageHasStillSweptFirst(): void
    {
        // Alice is at her cap with live rows; Bob's stale row is swept even though her claim then fails.
        $this->share();
        $stale = $this->claimRow($this->bob, 'hls', self::T0 - 500);
        $this->claimRow($this->alice);
        $this->claimRow($this->alice);
        $this->queueHlsPage();
        $this->t->respond(200, '');
        try {
            $this->model()->page($this->alice, $this->hlsRef($this->alice));
            $this->fail('expected MatineeBusyException');
        } catch (MatineeBusyException) {
        }
        $this->assertSame(1, (int) $this->row((int) $stale['id'])['stopped']);
        $this->assertSame('Matinee (bob)', $this->t->requests[2]['headers']['X-Plex-Device-Name']);
    }

    public function testHlsMasterHeartbeatsAndRewritesEveryUri(): void
    {
        $row = $this->claimRow($this->alice, 'hls', self::T0 - 50);
        $session = $row['session'];
        $seen = null;
        $this->t->respond(200, self::master($session), self::PLAYLIST_TYPE);
        $body = $this->model()->hlsMaster($this->alice, $this->hlsRef($this->alice), $session, self::proxyUrl(), function () use (&$seen): void {
            $seen = [count($this->t->requests), (int) $this->rows()[0]['last_seen_at']];
        });

        // Authorized before the heartbeat and before any Plex call (C3d).
        $this->assertSame([0, self::T0 - 50], $seen);
        $this->assertSame(self::T0, (int) $this->row((int) $row['id'])['last_seen_at']);
        $uris = array_values(array_filter(explode("\n", $body), static fn (string $l): bool => $l !== '' && $l[0] !== '#'));
        $this->assertSame(['P('.self::child($session).')'], $uris);
        $this->assertStringNotContainsString('/library', $body);

        [$req] = $this->t->requests;
        $this->assertStringStartsWith(self::BASE.'/video/:/transcode/universal/start.m3u8?', $req['url']);
        $this->assertStringContainsString('&path=%2Flibrary%2Fmetadata%2F7&', $req['url']);
        $this->assertStringContainsString('&session='.$session.'&', $req['url']);
        $this->assertSame('Matinee (alice)', $req['headers']['X-Plex-Device-Name']);
    }

    /** @return array<string, array{string, string}> each refusal, through hlsMaster and hlsChild */
    public static function hlsRefusals(): array
    {
        $cases = [];
        foreach (['hlsMaster', 'hlsChild'] as $method) {
            foreach ([
                'another user\'s session', 'a session on another server', 'a stopped session', 'an mp3 row',
                'an unknown session', 'a media ref', 'another user\'s ref', 'a server unshared since the page', 'a stale session',
            ] as $case) {
                $cases[$method.': '.$case] = [$method, $case];
            }
        }
        return $cases;
    }

    /** @dataProvider hlsRefusals */
    #[DataProvider('hlsRefusals')]
    public function testHlsMasterAndChildRefuseBeforePlex(string $method, string $case): void
    {
        $this->share();
        $mine = $this->claimRow($this->alice);
        $bobs = $this->claimRow($this->bob)['session'];
        $mp3 = $this->claimRow($this->alice, 'mp3')['session'];
        $stopped = $this->claimRow($this->bob, 'hls');
        $this->container['matineeTranscodeModel']->stop((int) $stopped['id']);
        $den = $this->secondServer();
        ['session' => $onDen] = $this->container['matineeTranscodeModel']->claim($this->bob, $den, 'hls', $this->now);

        [$user, $ref, $session] = match ($case) {
            'another user\'s session' => [$this->alice, $this->hlsRef($this->alice), $bobs],
            'a session on another server' => [$this->bob, $this->hlsRef($this->bob), $onDen],
            'a stopped session' => [$this->bob, $this->hlsRef($this->bob), $stopped['session']],
            'an mp3 row' => [$this->alice, $this->hlsRef($this->alice), $mp3],
            'an unknown session' => [$this->alice, $this->hlsRef($this->alice), '00000000-0000-4000-8000-000000000009'],
            'a media ref' => [$this->alice, $this->ref($this->alice, 'media', self::MOVIE, '1'), $mine['session']],
            'another user\'s ref' => [$this->alice, $this->hlsRef($this->bob), $mine['session']],
            'a server unshared since the page' => [$this->bob, $this->hlsRef($this->bob), $bobs],
            'a stale session' => [$this->alice, $this->hlsRef($this->alice), $mine['session']],
        };
        if ($case === 'a server unshared since the page') {
            $this->share(false);
        }
        if ($case === 'a stale session') {
            $this->container['db']->table(TranscodeModel::TABLE)->eq('id', $mine['id'])->update(['last_seen_at' => self::T0 - 121]);
        }

        $this->expectForbidden(fn () => $method === 'hlsMaster'
            ? $this->hlsMaster($user, $ref, $session)
            : $this->hlsChild($user, $ref, self::child($session, 'base/00000.ts'), new RecordingSink()));
        // A stale row is found only by the heartbeat, which runs after $onAuthorized (C3d); the rest refuse before it.
        $this->assertSame($case === 'a stale session' ? 1 : 0, $this->authorized);
        if ($case === 'a stale session') {
            $this->assertSame(self::T0 - 121, (int) $this->row((int) $mine['id'])['last_seen_at']);
        }
    }

    public function testAFailingHeartbeatIsLoggedAndTheMasterStillPlays(): void
    {
        $session = $this->claimRow($this->alice)['session'];
        $db = $this->container['db']->getConnection();
        $this->t->respond(200, self::master($session), self::PLAYLIST_TYPE);
        $body = $this->model()->hlsMaster($this->alice, $this->hlsRef($this->alice), $session, self::proxyUrl(), function () use ($db): void {
            $db->exec('ALTER TABLE '.TranscodeModel::TABLE.' RENAME TO matinee_transcodes_gone');
        });
        $db->exec('ALTER TABLE matinee_transcodes_gone RENAME TO '.TranscodeModel::TABLE);

        $this->assertStringContainsString('P('.self::child($session).')', $body);
        $this->assertSame(['warning Matinee: heartbeat failed (server '.$this->serverId.')'], $this->log->lines);
    }

    public function testABadMasterIsTheConnectFailureLoggedAtStart(): void
    {
        $session = $this->claimRow($this->alice)['session'];
        $this->t->respond(200, self::master('0f1e2d3c-4b5a-4987-8a6b-5c4d3e2f1a0b'), self::PLAYLIST_TYPE);
        try {
            $this->hlsMaster($this->alice, $this->hlsRef($this->alice), $session);
            $this->fail('expected MatineeConnectException');
        } catch (MatineeConnectException) {
        }
        $this->assertSame(['info Matinee: playback failed at start (server '.$this->serverId.', plex.bad_playlist)'], $this->log->lines);
    }

    public function testAnM3u8ChildReturnsARewrittenPlaylist(): void
    {
        $row = $this->claimRow($this->alice, 'hls', self::T0 - 50);
        $session = $row['session'];
        $sink = new RecordingSink();
        $this->t->respond(200, (string) file_get_contents(__DIR__.'/fixtures/plex/hls-index.m3u8'), self::PLAYLIST_TYPE);
        $body = $this->hlsChild($this->alice, $this->hlsRef($this->alice), self::child($session).'?x=1', $sink);

        $this->assertSame(1, $this->authorized);
        $this->assertSame(self::T0, (int) $this->row((int) $row['id'])['last_seen_at']);
        $this->assertSame([self::BASE.self::child($session).'?x=1'], array_column($this->t->requests, 'url'));
        $uris = array_values(array_filter(explode("\n", (string) $body), static fn (string $l): bool => $l !== '' && $l[0] !== '#'));
        $this->assertSame([
            'P('.self::child($session, 'base/00000.ts').')',
            'P('.self::child($session, 'base/00001.ts').')',
            'P('.self::child($session, 'base/00002.ts?x=1').')',
        ], $uris);
        $this->assertSame(0, $sink->opens);
    }

    public function testATsChildStreamsIntoTheSink(): void
    {
        $session = $this->claimRow($this->alice)['session'];
        $sink = new RecordingSink();
        $this->t->streams(200, ['Content-Type' => 'video/mp2t', 'Content-Length' => '7'], ['seg', 'ment']);
        $this->assertNull($this->hlsChild($this->alice, $this->hlsRef($this->alice), self::child($session, 'base/00000.ts'), $sink));

        $this->assertSame(1, $this->authorized);
        $this->assertSame(200, $sink->status);
        $this->assertSame(['content-type' => 'video/mp2t', 'content-length' => '7'], $sink->headers);
        $this->assertSame('segment', $sink->body);
        $this->assertSame(PlexClient::MAX_SEGMENT_BYTES, $this->t->requests[0]['opts']['maxBytes']);
    }

    public function testASegmentThatDiesAfterOpeningIsLoggedAndAppendsNothing(): void
    {
        $session = $this->claimRow($this->alice)['session'];
        $sink = new PlaybackCallbackSink(function (): void {
            throw new \RuntimeException('client sink broke');
        });
        $this->t->streams(200, ['Content-Type' => 'video/mp2t'], ['a', 'b']);
        $this->assertNull($this->hlsChild($this->alice, $this->hlsRef($this->alice), self::child($session, 'base/00000.ts'), $sink));
        $this->assertSame('a', $sink->body);
        $this->assertSame(['warning Matinee: stream ended early (server '.$this->serverId.', plex.unreachable)'], $this->log->lines);
        $this->assertSame(0, (int) $this->rows()[0]['stopped'], 'a segment failure leaves the session alone');
    }

    public function testAChildOfASessionNotInTheTableIsForbidden(): void
    {
        $this->claimRow($this->alice);
        $this->share();
        $bobs = $this->claimRow($this->bob)['session'];
        foreach (['00000000-0000-4000-8000-000000000009', $bobs] as $session) {
            $this->expectForbidden(fn () => $this->hlsChild($this->alice, $this->hlsRef($this->alice), self::child($session, 'base/00000.ts'), new RecordingSink()));
            $this->assertSame(0, $this->authorized);
        }
    }

    /** @dataProvider badChildren */
    #[DataProvider('badChildren')]
    public function testAChildFailingS2IsForbiddenWithNoPlexRequest(string $rest): void
    {
        $session = $this->claimRow($this->alice)['session'];
        $u = str_replace('{s}', $session, $rest);
        $this->expectForbidden(fn () => $this->hlsChild($this->alice, $this->hlsRef($this->alice), $u, new RecordingSink()));
        $this->assertSame(0, $this->authorized);
        $this->assertSame(self::T0, (int) $this->rows()[0]['last_seen_at']);
    }

    public static function badChildren(): array
    {
        $base = '/video/:/transcode/universal/session/{s}/';
        return [
            'an item path'     => ['/library/metadata/7'],
            'dot segments'     => [$base.'base/../../library/x.ts'],
            'percent'          => [$base.'base/a%2e.ts'],
            'a token'          => [$base.'base/x.ts?X-Plex-Token=t'],
            'an X-Plex key'    => [$base.'base/x.ts?X-Plex-Device-Name=me'],
            'an absolute url'  => ['https://evil.test'.$base.'base/x.ts'],
            'a double slash'   => [$base.'base//x.ts'],
            'a bad extension'  => [$base.'base/x.mp4'],
            'empty'            => [''],
        ];
    }

    public function testPlexSeesTheViewerOnHls(): void
    {
        $this->share();
        $this->queueHlsPage();
        $page = $this->model()->page($this->bob, $this->hlsRef($this->bob));
        $this->t->respond(200, self::master($page['session']), self::PLAYLIST_TYPE);
        $this->t->streams(200, ['Content-Type' => 'video/mp2t'], ['x']);
        $this->hlsMaster($this->bob, $page['hlsRef'], $page['session']);
        $this->hlsChild($this->bob, $page['hlsRef'], self::child($page['session'], 'base/00000.ts'), new RecordingSink());

        $this->assertCount(4, $this->t->requests);
        foreach ($this->t->requests as $req) {
            $this->assertSame('Matinee (bob)', $req['headers']['X-Plex-Device-Name']);
        }
    }
}
