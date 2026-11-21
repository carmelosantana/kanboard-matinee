<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/support/FakeTransport.php';

use KanboardTests\units\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Matinee\Crypto\KeyStore;
use Kanboard\Plugin\Matinee\Crypto\RefSigner;
use Kanboard\Plugin\Matinee\Crypto\Subkeys;
use Kanboard\Plugin\Matinee\Http\PlexClient;
use Kanboard\Plugin\Matinee\Http\PlexPaths;
use Kanboard\Plugin\Matinee\Http\UnsafeUrlException;
use Kanboard\Plugin\Matinee\Model\BrowseModel;
use Kanboard\Plugin\Matinee\Model\MatineeConnectException;
use Kanboard\Plugin\Matinee\Model\MatineeForbiddenException;
use Kanboard\Plugin\Matinee\Plugin;
use Kanboard\Plugin\Matinee\Test\FakeTransport;

/** Collects every log line so tests can check the logged stage and that no secret is logged. */
class BrowseTestLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = $level.' '.$message;
    }
}

/**
 * BrowseModel (plan P2 Task 3; spec §6 as adapted, rulings B2, B3, B4; §11 S13). The Plex side is FakeTransport behind
 * the injectable client factory; the key store lives in a temp dir; refs are signed by the container's signer.
 */
class BrowseModelTest extends Base
{
    private const TOKEN = 'test-token-NOT-REAL-0000';
    private const BASE = 'https://plex.example.test:32400';
    private const CLIENT_ID = 'c0ffee00-1111-4222-8333-444455556666';
    private const FAILED = 'matinee.connect_failed';
    private const JPEG = "\xFF\xD8\xFF\xE0fake-jpeg-bytes";

    private FakeTransport $t;
    private BrowseTestLogger $log;
    private string $keyDir;
    private int $now;

    /** @var list<array{baseUrl: string, machineId: ?string}> */
    private array $factoryCalls = [];

    private int $alice;
    private int $bob;
    private int $serverId;

    /** The production matineeRefSigner closure, kept so a test can put it back over a broken key store. */
    private \Closure $productionSigner;

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

        $this->keyDir = sys_get_temp_dir().'/matinee-browsemodel-'.bin2hex(random_bytes(6));
        $this->container['matineeKeyStore'] = new KeyStore($this->keyDir, null);

        // A signer on a test clock, so a ref can be aged past its TTL.
        $this->now = 1_800_000_000;
        $this->productionSigner = $this->container->raw('matineeRefSigner');
        $this->container->offsetUnset('matineeRefSigner');
        $this->container['matineeRefSigner'] = fn ($c): RefSigner => new RefSigner(
            Subkeys::refs($c['matineeKeyStore']->rootKey()),
            fn (): int => $this->now
        );

        $this->t = new FakeTransport();
        $this->container['matineePlexClientFactory'] = $this->container->protect(
            function (string $baseUrl, #[\SensitiveParameter] string $token, ?string $machineId, string $deviceName): PlexClient {
                $this->factoryCalls[] = ['baseUrl' => $baseUrl, 'machineId' => $machineId];
                return new PlexClient($baseUrl, $token, self::CLIENT_ID, $this->t, $machineId, $deviceName);
            }
        );

        $this->log = new BrowseTestLogger();
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

    private function model(): BrowseModel
    {
        return $this->container['matineeBrowseModel'];
    }

    private function signer(): RefSigner
    {
        return $this->container['matineeRefSigner'];
    }

    private function user(string $name, string $role = Role::APP_USER): int
    {
        return (int) (new UserModel($this->container))->create(['username' => $name, 'role' => $role]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/plex/'.$name.'.json');
    }

    /** Forget the requests, factory calls and log lines so far. */
    private function reset(): void
    {
        $this->t = new FakeTransport();
        $this->factoryCalls = [];
        $this->log->lines = [];
    }

    private function share(bool $on = true): void
    {
        $this->container['matineeServerModel']->setShared($this->serverId, $this->alice, $on);
    }

    private function browseRef(int $userId, string $section = '1', ?string $path = null, ?int $serverId = null): string
    {
        return $this->signer()->sign($userId, $serverId ?? $this->serverId, $section, 'browse', $path ?? PlexPaths::sectionAll($section), 3600);
    }

    private function artRef(int $userId, string $path = '/library/metadata/101/thumb/1700000000', string $section = '1'): string
    {
        return $this->signer()->sign($userId, $this->serverId, $section, 'art', $path, 3600);
    }

    /** Queue a live sections answer, then one children page. */
    private function queueListing(string $children = 'children-section'): void
    {
        $this->t->respond(200, $this->fixture('sections'));
        $this->t->respond(200, $this->fixture($children));
    }

    private function queueJpeg(): void
    {
        $this->t->respond(200, self::JPEG, ['content-type' => 'image/jpeg']);
    }

    /** Runs $fn and expects the one Forbidden; nothing reached Plex and no client was built. */
    private function expectForbidden(callable $fn, bool $clientBuilt = false): void
    {
        try {
            $fn();
            $this->fail('expected MatineeForbiddenException');
        } catch (MatineeForbiddenException $e) {
            $this->assertSame('matinee.forbidden', $e->getMessage());
        }
        if (! $clientBuilt) {
            $this->assertSame([], $this->factoryCalls, 'a client was built before the refusal');
            $this->assertSame([], $this->t->requests, 'Plex was asked before the refusal');
        }
    }

    /** Runs $fn, expects the generic connect failure, and returns the log. */
    private function expectConnectFailure(callable $fn): string
    {
        try {
            $fn();
            $this->fail('expected MatineeConnectException');
        } catch (MatineeConnectException $e) {
            $this->assertSame(self::FAILED, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $log = implode("\n", $this->log->lines);
        $this->assertStringNotContainsString(self::TOKEN, $log);
        $this->assertStringNotContainsString('plex.example.test', $log);
        $this->assertStringNotContainsString('/library', $log);
        return $log;
    }

    /** A malformed MATINEE_KEY: every rootKey()/allKeys() call throws KeyStoreException. */
    private function breakKeyStore(bool $productionSigner): void
    {
        $this->container->offsetUnset('matineeKeyStore');
        $this->container['matineeKeyStore'] = new KeyStore($this->keyDir, 'not-a-valid-key');
        if ($productionSigner) {
            $this->container->offsetUnset('matineeRefSigner');
            $this->container['matineeRefSigner'] = $this->productionSigner;
        }
    }

    private function assertNoToken(mixed $result): void
    {
        $this->assertStringNotContainsString(self::TOKEN, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertStringNotContainsString(self::TOKEN, serialize($result));
    }

    /** @return array{sv: int, s: string, k: string, r: string} */
    private function verify(string $ref, int $userId): array
    {
        return $this->signer()->verify($ref, $userId);
    }

    // ── wiring ───────────────────────────────────────────────────────────────

    public function testPluginRegistersTheBrowseModel(): void
    {
        $this->assertInstanceOf(BrowseModel::class, $this->container['matineeBrowseModel']);
        $this->assertSame(60, BrowseModel::PAGE_SIZE);
        $this->assertSame(1000000, BrowseModel::MAX_START);
    }

    // ── sections ─────────────────────────────────────────────────────────────

    public function testTheOwnerGetsTheLiveSectionsEachWithABrowseRef(): void
    {
        $this->t->respond(200, $this->fixture('sections'));
        $rows = $this->model()->sections($this->alice, $this->serverId);

        $this->assertSame(['1', '2', '5', '9', '12', '99999999999999999999'], array_column($rows, 'key'));
        $this->assertSame(['Movies', 'TV Shows', 'Music', 'Family Photos', '42', 'Twenty digits'], array_column($rows, 'title'));
        $this->assertSame(['movie', 'show', 'artist', 'photo', 'movie', 'show'], array_column($rows, 'type'));
        foreach ($rows as $row) {
            $this->assertSame(['key', 'title', 'type', 'ref'], array_keys($row));
            $this->assertSame(
                ['sv' => $this->serverId, 's' => $row['key'], 'k' => 'browse', 'r' => '/library/sections/'.$row['key'].'/all'],
                $this->verify($row['ref'], $this->alice)
            );
        }
        $this->assertSame([self::BASE.'/library/sections'], array_column($this->t->requests, 'url'));
        $this->assertNoToken($rows);
    }

    public function testSectionRefsLastTheFullHourAndNoLonger(): void
    {
        $this->t->respond(200, $this->fixture('sections'));
        $ref = $this->model()->sections($this->alice, $this->serverId)[0]['ref'];
        $this->now += 3599;
        $this->assertSame('1', $this->verify($ref, $this->alice)['s']);
        $this->now += 1;
        $this->expectForbidden(fn () => $this->verify($ref, $this->alice), true);
    }

    public function testASharedServerListsItsSectionsForEveryActiveUser(): void
    {
        $this->share();
        $this->t->respond(200, $this->fixture('sections'));
        $rows = $this->model()->sections($this->bob, $this->serverId);
        $this->assertCount(6, $rows);
        $this->assertSame($this->serverId, $this->verify($rows[0]['ref'], $this->bob)['sv']);
        $this->expectForbidden(fn () => $this->verify($rows[0]['ref'], $this->alice), true);
    }

    public function testSectionsAreRefusedToAnyoneWhoMayNotBrowse(): void
    {
        $this->expectForbidden(fn () => $this->model()->sections($this->bob, $this->serverId)); // private
        $this->expectForbidden(fn () => $this->model()->sections($this->alice, 9999)); // missing reads the same
        $this->expectForbidden(fn () => $this->model()->sections(9999, $this->serverId)); // no such user

        $this->share();
        $this->container['db']->table('users')->eq('id', $this->bob)->update(['is_active' => 0]);
        $this->expectForbidden(fn () => $this->model()->sections($this->bob, $this->serverId));

        $this->container['db']->table('matinee_servers')->eq('id', $this->serverId)->update(['is_enabled' => 0]);
        $this->expectForbidden(fn () => $this->model()->sections($this->alice, $this->serverId));
    }

    public function testSectionFailuresAreTheOneConnectFailureLoggedByStageAndServer(): void
    {
        $this->t->fail(new \RuntimeException('boom '.self::TOKEN.' '.self::BASE));
        $log = $this->expectConnectFailure(fn () => $this->model()->sections($this->alice, $this->serverId));
        $this->assertStringContainsString('at sections (server '.$this->serverId.', plex.unreachable)', $log);

        $this->reset();
        $this->t->fail(new UnsafeUrlException(UnsafeUrlException::BLOCKED_ADDRESS, 'plex.example.test', 32400));
        $log = $this->expectConnectFailure(fn () => $this->model()->sections($this->alice, $this->serverId));
        $this->assertStringContainsString('at sections (server '.$this->serverId.', blocked_address)', $log);
    }

    public function testATokenThatWillNotOpenIsTheConnectFailureAtTheClientStage(): void
    {
        $this->container['db']->table('matinee_servers')->eq('id', $this->serverId)->update(['token_ct' => base64_encode(random_bytes(40))]);
        $log = $this->expectConnectFailure(fn () => $this->model()->sections($this->alice, $this->serverId));
        $this->assertStringContainsString('at client (server '.$this->serverId.', token unopenable)', $log);
        $this->assertSame([], $this->t->requests);
    }

    // ── listing ──────────────────────────────────────────────────────────────

    public function testTheOwnerGetsAPageWithChildAndArtRefs(): void
    {
        $this->queueListing();
        $page = $this->model()->listing($this->alice, $this->browseRef($this->alice), 0);

        $this->assertSame(['id' => $this->serverId, 'name' => 'Living room'], $page['server']);
        $this->assertSame('1', $page['section']);
        // The live section's title, from the sections fetch B3 already makes (the request list below has no third).
        $this->assertSame('Movies', $page['sectionTitle']);
        $this->assertSame(250, $page['total']);
        $this->assertSame(0, $page['start']);
        $this->assertSame(60, $page['pageSize']);
        $this->assertSame(['server', 'section', 'sectionTitle', 'total', 'start', 'pageSize', 'items'], array_keys($page));

        $items = $page['items'];
        $this->assertSame(
            ['Fake Movie One', 'Fake Show', 'Season 1', 'Pilot', '42', 'Fake Album', 'Fake Track', 'Fake Photo'],
            array_column($items, 'title')
        );
        $this->assertSame(['', '', 'Fake Show', 'Fake Show · Season 1', '', 'Fake Artist', '', ''], array_column($items, 'subtitle'));
        $this->assertSame(['movie', 'show', 'season', 'episode', 'artist', 'album', 'track', 'photo'], array_column($items, 'type'));
        $this->assertSame([1999, 2004, null, null, null, null, null, null], array_column($items, 'year'));
        $this->assertSame([null, null, 1, 1, null, null, null, null], array_column($items, 'index'));
        $this->assertSame([null, 22, 10, null, null, null, null, null], array_column($items, 'leafCount'));
        foreach ($items as $item) {
            $this->assertSame(['title', 'subtitle', 'year', 'type', 'index', 'leafCount', 'browseRef', 'artRef'], array_keys($item));
        }

        // Browse refs only for browse keys (the show and the season), art refs only for art thumbs; same s and server.
        $this->assertSame([false, true, true, false, false, false, false, false], array_map(fn ($i) => $i['browseRef'] !== null, $items));
        $this->assertSame([true, true, false, false, false, false, false, false], array_map(fn ($i) => $i['artRef'] !== null, $items));
        $this->assertSame(
            ['sv' => $this->serverId, 's' => '1', 'k' => 'browse', 'r' => '/library/metadata/102/children'],
            $this->verify($items[1]['browseRef'], $this->alice)
        );
        $this->assertSame(
            ['sv' => $this->serverId, 's' => '1', 'k' => 'browse', 'r' => '/library/metadata/103/children'],
            $this->verify($items[2]['browseRef'], $this->alice)
        );
        $this->assertSame(
            ['sv' => $this->serverId, 's' => '1', 'k' => 'art', 'r' => '/library/metadata/101/thumb/1700000000'],
            $this->verify($items[0]['artRef'], $this->alice)
        );
        $this->assertSame('/library/metadata/102/thumb/1700000001', $this->verify($items[1]['artRef'], $this->alice)['r']);

        // Child and art refs last the full hour and no longer.
        $this->now += 3599;
        $this->assertSame('1', $this->verify($items[1]['browseRef'], $this->alice)['s']);
        $this->assertSame('1', $this->verify($items[0]['artRef'], $this->alice)['s']);
        $this->now += 1;
        $this->expectForbidden(fn () => $this->verify($items[1]['browseRef'], $this->alice), true);
        $this->expectForbidden(fn () => $this->verify($items[0]['artRef'], $this->alice), true);

        $this->assertSame(
            [self::BASE.'/library/sections', self::BASE.'/library/sections/1/all?X-Plex-Container-Start=0&X-Plex-Container-Size=60'],
            array_column($this->t->requests, 'url')
        );
        $this->assertNoToken($page);
    }

    public function testChildRefsVerifyOnlyForTheSameUser(): void
    {
        $this->share();
        $this->queueListing();
        $items = $this->model()->listing($this->bob, $this->browseRef($this->bob), 0)['items'];
        $this->assertSame('1', $this->verify($items[1]['browseRef'], $this->bob)['s']);
        $this->assertSame('1', $this->verify($items[0]['artRef'], $this->bob)['s']);
        foreach ([$items[1]['browseRef'], $items[0]['artRef']] as $ref) {
            $this->expectForbidden(fn () => $this->verify($ref, $this->alice), true);
        }
    }

    public function testAChildRefOpensTheNextLevelUnderTheSameSection(): void
    {
        $this->queueListing();
        $show = $this->model()->listing($this->alice, $this->browseRef($this->alice), 0)['items'][1]['browseRef'];

        $this->t = new FakeTransport();
        $this->t->respond(200, $this->fixture('sections'));
        $this->t->respondJson(['MediaContainer' => ['librarySectionID' => '1', 'totalSize' => 1, 'Metadata' => [
            ['ratingKey' => '201', 'type' => 'season', 'title' => 'Season 1', 'parentTitle' => 'Fake Show', 'index' => 1, 'key' => '/library/metadata/201/children'],
        ]]]);
        $page = $this->model()->listing($this->alice, $show, 0);
        $this->assertSame('1', $page['section']);
        $this->assertSame(['Season 1'], array_column($page['items'], 'title'));
        $this->assertSame(self::BASE.'/library/metadata/102/children?X-Plex-Container-Start=0&X-Plex-Container-Size=60', $this->t->requests[1]['url']);
    }

    public function testPagingIsPassedThroughAndStartIsBounded(): void
    {
        $this->queueListing();
        $page = $this->model()->listing($this->alice, $this->browseRef($this->alice), 120);
        $this->assertSame(120, $page['start']);
        $this->assertSame(self::BASE.'/library/sections/1/all?X-Plex-Container-Start=120&X-Plex-Container-Size=60', $this->t->requests[1]['url']);

        $this->reset();
        $this->queueListing();
        $this->model()->listing($this->alice, $this->browseRef($this->alice), BrowseModel::MAX_START);
        $this->assertStringContainsString('X-Plex-Container-Start=1000000&', $this->t->requests[1]['url']);

        $this->reset();
        foreach ([-1, BrowseModel::MAX_START + 1, PHP_INT_MAX, PHP_INT_MIN] as $start) {
            $this->expectForbidden(fn () => $this->model()->listing($this->alice, $this->browseRef($this->alice), $start));
        }
    }

    public function testASharedUserGetsListings(): void
    {
        $this->share();
        $this->queueListing();
        $page = $this->model()->listing($this->bob, $this->browseRef($this->bob), 0);
        $this->assertCount(8, $page['items']);
        $this->assertNoToken($page);
    }

    // C9: on a shared server Plex sees the viewer, never the owner, on every call a listing makes.
    public function testASharedListingByANonOwnerSendsTheViewersName(): void
    {
        $this->share();
        $this->t->respond(200, $this->fixture('sections'));
        $this->model()->sections($this->bob, $this->serverId);
        $this->queueListing();
        $this->model()->listing($this->bob, $this->browseRef($this->bob), 0);
        $this->queueJpeg();
        $this->model()->art($this->bob, $this->artRef($this->bob), 144);
        $names = array_map(fn (array $r): string => $r['headers']['X-Plex-Device-Name'], $this->t->requests);
        $this->assertSame(array_fill(0, 4, 'Matinee (bob)'), $names);

        $this->reset();
        $this->queueListing();
        $this->model()->listing($this->alice, $this->browseRef($this->alice), 0);
        $names = array_map(fn (array $r): string => $r['headers']['X-Plex-Device-Name'], $this->t->requests);
        $this->assertSame(['Matinee (alice)', 'Matinee (alice)'], $names);
    }

    public function testARefMintedWhileSharedFailsOnceUnshared(): void
    {
        $this->share();
        $browse = $this->browseRef($this->bob);
        $art = $this->artRef($this->bob);
        $this->share(false);
        // Queued answers, so only the re-check can stop it.
        $this->queueListing();
        $this->expectForbidden(fn () => $this->model()->listing($this->bob, $browse, 0));
        $this->reset();
        $this->queueJpeg();
        $this->expectForbidden(fn () => $this->model()->art($this->bob, $art, 144));
    }

    public function testAnotherUsersRefIsRefusedEvenOnASharedServer(): void
    {
        $this->share();
        $this->queueListing();
        $this->expectForbidden(fn () => $this->model()->listing($this->bob, $this->browseRef($this->alice), 0));
        $this->expectForbidden(fn () => $this->model()->art($this->bob, $this->artRef($this->alice), 144));
    }

    public function testListingIsRefusedToAnInactiveUserADisabledServerOrAnInactiveOwner(): void
    {
        $this->share();
        $ref = $this->browseRef($this->bob);
        $art = $this->artRef($this->bob);
        $db = $this->container['db'];

        $db->table('users')->eq('id', $this->bob)->update(['is_active' => 0]);
        $this->expectForbidden(fn () => $this->model()->listing($this->bob, $ref, 0));
        $this->expectForbidden(fn () => $this->model()->art($this->bob, $art, 144));
        $db->table('users')->eq('id', $this->bob)->update(['is_active' => 1]);

        $db->table('matinee_servers')->eq('id', $this->serverId)->update(['is_enabled' => 0]);
        $this->expectForbidden(fn () => $this->model()->listing($this->bob, $ref, 0));
        $this->expectForbidden(fn () => $this->model()->art($this->bob, $art, 144));
        $db->table('matinee_servers')->eq('id', $this->serverId)->update(['is_enabled' => 1]);

        $db->table('users')->eq('id', $this->alice)->update(['is_active' => 0]);
        $this->expectForbidden(fn () => $this->model()->listing($this->bob, $ref, 0));
        $this->expectForbidden(fn () => $this->model()->art($this->bob, $art, 144));
    }

    public function testARefForARemovedServerIsRefused(): void
    {
        $ref = $this->browseRef($this->alice, '1', null, 9999);
        $this->expectForbidden(fn () => $this->model()->listing($this->alice, $ref, 0));
    }

    public function testAnArtRefIsNotABrowseRef(): void
    {
        $this->queueListing();
        $this->expectForbidden(fn () => $this->model()->listing($this->alice, $this->artRef($this->alice), 0));
    }

    public function testATamperedOrGarbageRefIsRefused(): void
    {
        $ref = $this->browseRef($this->alice);
        [$body, $sig] = explode('.', $ref);
        $sig[0] = $sig[0] === 'A' ? 'B' : 'A';
        foreach ([$body.'.'.$sig, '', 'x', '/library/sections/1/all'] as $bad) {
            $this->expectForbidden(fn () => $this->model()->listing($this->alice, $bad, 0));
        }
    }

    public function testAnExpiredRefIsRefused(): void
    {
        $ref = $this->browseRef($this->alice);
        $art = $this->artRef($this->alice);
        $this->now += 3600;
        $this->expectForbidden(fn () => $this->model()->listing($this->alice, $ref, 0));
        $this->expectForbidden(fn () => $this->model()->art($this->alice, $art, 144));
    }

    public function testASectionNoLongerLiveIsRefused(): void
    {
        $this->t->respondJson(['MediaContainer' => ['Directory' => [['key' => '2', 'type' => 'show', 'title' => 'TV Shows']]]]);
        $this->t->respond(200, $this->fixture('children-section'));
        $this->expectForbidden(fn () => $this->model()->listing($this->alice, $this->browseRef($this->alice), 0), true);
        $this->assertCount(1, $this->t->requests, 'the children page was fetched for a dead section');
    }

    public function testAContainerFromAnotherSectionIsRefused(): void
    {
        // The ref says section 2 (live), but the path's container reports librarySectionID 1.
        $this->queueListing();
        $this->expectForbidden(fn () => $this->model()->listing($this->alice, $this->browseRef($this->alice, '2', '/library/sections/1/all'), 0), true);
    }

    public function testAContainerWithNoSectionIsRefused(): void
    {
        $this->queueListing('children-no-section');
        $this->expectForbidden(fn () => $this->model()->listing($this->alice, $this->browseRef($this->alice, '1', '/library/metadata/102/children'), 0), true);
    }

    public function testListingFailuresAreTheOneConnectFailure(): void
    {
        $this->t->respond(500, 'upstream '.self::TOKEN);
        $log = $this->expectConnectFailure(fn () => $this->model()->listing($this->alice, $this->browseRef($this->alice), 0));
        $this->assertStringContainsString('at sections (server '.$this->serverId.', plex.http_error)', $log);

        $this->reset();
        $this->t->respond(200, $this->fixture('sections'));
        $this->t->respond(200, '{not json');
        $log = $this->expectConnectFailure(fn () => $this->model()->listing($this->alice, $this->browseRef($this->alice), 0));
        $this->assertStringContainsString('at children (server '.$this->serverId.', plex.bad_json)', $log);

        $this->reset();
        $this->t->respond(200, $this->fixture('sections'));
        $this->t->respond(401, '');
        $log = $this->expectConnectFailure(fn () => $this->model()->listing($this->alice, $this->browseRef($this->alice), 0));
        $this->assertStringContainsString('at children (server '.$this->serverId.', plex.token_rejected)', $log);
    }

    // ── art ──────────────────────────────────────────────────────────────────

    public function testTheOwnerAndASharedUserGetArt(): void
    {
        $this->queueJpeg();
        $this->assertSame(['type' => 'image/jpeg', 'body' => self::JPEG], $this->model()->art($this->alice, $this->artRef($this->alice), 144));
        $url = $this->t->requests[0]['url'];
        $this->assertStringStartsWith(self::BASE.'/photo/:/transcode?width=144&height=144&', $url);
        $this->assertStringNotContainsString(self::TOKEN, $url);

        $this->share();
        $this->reset();
        $this->t->respond(200, self::JPEG, ['content-type' => 'image/webp']);
        $art = $this->model()->art($this->bob, $this->artRef($this->bob), 512);
        $this->assertSame('image/webp', $art['type']);
        $this->assertStringStartsWith(self::BASE.'/photo/:/transcode?width=512&height=512&', $this->t->requests[0]['url']);
        $this->assertNoToken($art);
        // Ruling B2: art does not re-fetch the live sections.
        $this->assertCount(1, $this->t->requests);
    }

    public function testABrowseRefIsNotAnArtRef(): void
    {
        $this->queueJpeg();
        $this->expectForbidden(fn () => $this->model()->art($this->alice, $this->browseRef($this->alice), 144));
    }

    public function testArtSizesOutsideTheAllowListAreRefused(): void
    {
        foreach ([0, 143, 145, 1080, -144, PHP_INT_MAX] as $size) {
            $this->expectForbidden(fn () => $this->model()->art($this->alice, $this->artRef($this->alice), $size));
        }
    }

    public function testArtFailuresAreTheOneConnectFailure(): void
    {
        $this->t->respond(200, '<svg/>', ['content-type' => 'image/svg+xml']);
        $log = $this->expectConnectFailure(fn () => $this->model()->art($this->alice, $this->artRef($this->alice), 144));
        $this->assertStringContainsString('at art (server '.$this->serverId.', plex.bad_type)', $log);

        $this->reset();
        $this->t->respond(404, 'not found '.self::TOKEN);
        $log = $this->expectConnectFailure(fn () => $this->model()->art($this->alice, $this->artRef($this->alice), 144));
        $this->assertStringContainsString('at art (server '.$this->serverId.', plex.http_error)', $log);
    }

    // ── key store and client construction ───────────────────────────────────

    public function testABrokenKeyStoreAtTheRefsStageIsTheConnectFailureBeforeAnyClient(): void
    {
        // Refs minted while the key store worked; then MATINEE_KEY goes bad and the production signer is back.
        $browse = $this->browseRef($this->alice);
        $art = $this->artRef($this->alice);
        $this->breakKeyStore(true);
        $this->queueListing();
        $this->queueJpeg();

        foreach ([
            fn () => $this->model()->listing($this->alice, $browse, 0),
            fn () => $this->model()->art($this->alice, $art, 144),
            fn () => $this->model()->sections($this->alice, $this->serverId),
        ] as $call) {
            $this->log->lines = [];
            $log = $this->expectConnectFailure($call);
            $this->assertStringContainsString('at refs', $log);
            $this->assertStringContainsString('key store', $log);
            $this->assertSame('info Matinee: browse failed at refs (key store)', $log);
            $this->assertSame([], $this->factoryCalls, 'a client was built with no signer');
            $this->assertSame([], $this->t->requests, 'Plex was asked with no signer');
        }
    }

    public function testABrokenKeyStoreAtTheClientStageIsTheConnectFailureLoggedAsKeyStore(): void
    {
        $this->signer(); // resolved while the key store worked
        $this->breakKeyStore(false);
        $this->t->respond(200, $this->fixture('sections'));
        $log = $this->expectConnectFailure(fn () => $this->model()->sections($this->alice, $this->serverId));
        $this->assertStringContainsString('at client (server '.$this->serverId.', key store)', $log);
        $this->assertSame([], $this->t->requests);
        // Configuration, not this row: the status is left alone.
        $this->assertSame('ok', $this->container['db']->table('matinee_servers')->eq('id', $this->serverId)->findOneColumn('status'));
    }

    public function testAClientThatWillNotBuildIsTheConnectFailureAtTheClientStage(): void
    {
        $this->container->offsetUnset('matineePlexClientFactory');
        $this->container['matineePlexClientFactory'] = $this->container->protect(
            function (string $baseUrl, #[\SensitiveParameter] string $token, ?string $machineId, string $deviceName): PlexClient {
                $this->factoryCalls[] = ['baseUrl' => $baseUrl, 'machineId' => $machineId];
                throw new \InvalidArgumentException('plex: bad base url '.$baseUrl);
            }
        );
        $this->t->respond(200, $this->fixture('sections'));
        $log = $this->expectConnectFailure(fn () => $this->model()->sections($this->alice, $this->serverId));
        $this->assertStringContainsString('at client (server '.$this->serverId.', invalid)', $log);
        $this->assertSame([], $this->t->requests);

        $this->log->lines = [];
        $this->expectConnectFailure(fn () => $this->model()->listing($this->alice, $this->browseRef($this->alice), 0));
        $this->log->lines = [];
        $this->expectConnectFailure(fn () => $this->model()->art($this->alice, $this->artRef($this->alice), 144));
    }
}
