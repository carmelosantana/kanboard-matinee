<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/support/ReleasesContainer.php';

use KanboardTests\units\Base;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Matinee\Model\MatineeBusyException;
use Kanboard\Plugin\Matinee\Model\InflightGate;
use Kanboard\Plugin\Matinee\Model\Settings;
use Kanboard\Plugin\Matinee\Model\TranscodeModel;
use Kanboard\Plugin\Matinee\Plugin;

/** P3 C4: the stream caps, the lock row, the lookups and the housekeeping of matinee_transcodes; and the cap settings. */
class TranscodeModelTest extends Base
{
    use MatineeReleasesContainer;

    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
    private const NOW = 1_800_000_000;
    private const LOCK_LINE = 'error Matinee: streams lock unavailable';
    private const TOKEN_ROW = ['token_nonce' => 'bm9uY2U=', 'token_ct' => 'Y3Q=', 'key_id' => 'k1'];

    private int $seq = 0;

    /** Every line the model logs, as "level message". */
    private \Psr\Log\AbstractLogger $log;

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
        $this->log = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, $message, array $context = []): void
            {
                $this->lines[] = $level.' '.$message.($context === [] ? '' : ' '.json_encode($context));
            }
        };
        $this->container['logger'] = $this->log;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->releaseContainer();
    }

    private function db(): \PicoDb\Database
    {
        return $this->container['db'];
    }

    private function model(?\Closure $uuid = null): TranscodeModel
    {
        return $uuid === null ? $this->container['matineeTranscodeModel'] : new TranscodeModel($this->container, $uuid);
    }

    private function user(string $name): int
    {
        $id = (int) (new UserModel($this->container))->create(['username' => $name]);
        $this->assertGreaterThan(1, $id);
        return $id;
    }

    private function server(int $ownerId): int
    {
        $this->assertTrue($this->db()->table('matinee_servers')->insert(self::TOKEN_ROW + [
            'owner_user_id' => $ownerId, 'name' => 'Den', 'base_url' => 'https://plex.example.test:32400',
            'machine_id' => 'abc123', 'created_at' => 1, 'updated_at' => 1,
        ]));
        return (int) $this->db()->getLastId();
    }

    /** Inserts a row directly; active (fresh, not stopped) unless $extra says otherwise. Returns its session. */
    private function row(int $serverId, int $userId, array $extra = []): string
    {
        $session = sprintf('00000000-0000-4000-8000-%012d', ++$this->seq);
        $this->assertTrue($this->db()->table(TranscodeModel::TABLE)->insert($extra + [
            'server_id' => $serverId, 'user_id' => $userId, 'session' => $session, 'kind' => 'hls',
            'created_at' => self::NOW, 'last_seen_at' => self::NOW, 'stopped' => 0,
        ]));
        return $session;
    }

    private function rowCount(): int
    {
        return $this->db()->table(TranscodeModel::TABLE)->count();
    }

    /** $sameCount false: another connection commits a row meanwhile, so the caller checks the rows itself. */
    private function assertBusy(callable $claim, bool $sameCount = true): void
    {
        $before = $this->rowCount();
        try {
            $claim();
            $this->fail('expected MatineeBusyException');
        } catch (MatineeBusyException $e) {
            $this->assertSame('matinee.busy', $e->getMessage());
        }
        if ($sameCount) {
            $this->assertSame($before, $this->rowCount(), 'a busy claim inserts nothing');
        }
        $this->assertFalse($this->db()->getConnection()->inTransaction(), 'a busy claim leaves no open transaction');
    }

    private function setCap(string $key, string $value): void
    {
        $this->assertTrue($this->container['configModel']->save([$key => $value]));
        $this->container['memoryCache']->flush();
    }

    private function lockCount(): int
    {
        return (int) $this->db()->table('matinee_locks')->eq('name', 'streams')->findOneColumn('n');
    }

    // ── Caps ────────────────────────────────────────────────────────────────

    public function testClaimUnderEveryCapInsertsOneActiveRow(): void
    {
        $alice = $this->user('alice');
        $serverId = $this->server($alice);

        $claim = $this->model()->claim($alice, $serverId, 'hls', self::NOW);
        $session = $claim['session'];

        $this->assertSame(['id', 'session'], array_keys($claim));
        $this->assertMatchesRegularExpression(self::UUID, $session);
        $rows = $this->db()->table(TranscodeModel::TABLE)->findAll();
        $this->assertCount(1, $rows);
        $this->assertSame((int) $rows[0]['id'], $claim['id'], 'claim() returns the new row id');
        $this->assertSame(
            ['server_id' => $serverId, 'user_id' => $alice, 'session' => $session, 'kind' => 'hls', 'created_at' => self::NOW, 'last_seen_at' => self::NOW, 'stopped' => 0],
            ['server_id' => (int) $rows[0]['server_id'], 'user_id' => (int) $rows[0]['user_id'], 'session' => $rows[0]['session'], 'kind' => $rows[0]['kind'],
                'created_at' => (int) $rows[0]['created_at'], 'last_seen_at' => (int) $rows[0]['last_seen_at'], 'stopped' => (int) $rows[0]['stopped']]
        );
        $this->assertSame(1, $this->lockCount(), 'the claim went through the lock row');
        $second = $this->model()->claim($alice, $serverId, 'mp3', self::NOW);
        $this->assertNotSame($session, $second['session'], 'each claim mints a new session');
        $this->assertSame((int) $this->model()->find($second['session'], $alice)['id'], $second['id'], 'the second row id, not the first');
    }

    public function testUnknownKindIsRefused(): void
    {
        $alice = $this->user('alice');
        $serverId = $this->server($alice);
        $this->expectException(\InvalidArgumentException::class);
        $this->model()->claim($alice, $serverId, 'video', self::NOW);
    }

    public function testPerServerCap(): void
    {
        $owner = $this->user('owner');
        [$a, $b] = [$this->server($owner), $this->server($owner)];
        $u1 = $this->user('u1');
        $u2 = $this->user('u2');
        foreach ([$u1, $u1, $u2, $u2] as $u) {
            $this->row($a, $u);
        }
        $u3 = $this->user('u3');

        $this->assertBusy(fn () => $this->model()->claim($u3, $a, 'hls', self::NOW));
        $this->assertMatchesRegularExpression(self::UUID, $this->model()->claim($u3, $b, 'hls', self::NOW)['session']);
        $this->assertSame([], $this->log->lines, 'a reached cap is not a lock failure');
    }

    public function testPerUserCap(): void
    {
        $owner = $this->user('owner');
        [$a, $b, $c] = [$this->server($owner), $this->server($owner), $this->server($owner)];
        $u = $this->user('u');
        $v = $this->user('v');
        $this->row($a, $u);
        $this->row($b, $u);

        $this->assertBusy(fn () => $this->model()->claim($u, $c, 'mp3', self::NOW));
        $this->assertMatchesRegularExpression(self::UUID, $this->model()->claim($v, $c, 'mp3', self::NOW)['session']);
    }

    public function testGlobalCap(): void
    {
        $owner = $this->user('owner');
        [$a, $b, $c] = [$this->server($owner), $this->server($owner), $this->server($owner)];
        [$u1, $u2, $u3, $u4] = [$this->user('u1'), $this->user('u2'), $this->user('u3'), $this->user('u4')];
        foreach ([[$a, $u1], [$b, $u1], [$a, $u2], [$c, $u2], [$b, $u3]] as [$s, $u]) {
            $this->row($s, $u);
        }

        $this->assertBusy(fn () => $this->model()->claim($u4, $c, 'hls', self::NOW));
    }

    public function testAdminSetCapsAreHonoured(): void
    {
        $owner = $this->user('owner');
        $a = $this->server($owner);
        $this->setCap(Settings::STREAM_CAP_SERVER_KEY, '1');

        $this->model()->claim($this->user('u1'), $a, 'hls', self::NOW);
        $this->assertBusy(fn () => $this->model()->claim($this->user('u2'), $a, 'hls', self::NOW));

        $this->setCap(Settings::STREAM_CAP_USER_KEY, '1');
        $b = $this->server($owner);
        $u3 = $this->user('u3');
        $this->model()->claim($u3, $b, 'hls', self::NOW);
        $this->assertBusy(fn () => $this->model()->claim($u3, $this->server($owner), 'hls', self::NOW));

        $this->setCap(Settings::STREAM_CAP_GLOBAL_KEY, '2');
        $this->assertBusy(fn () => $this->model()->claim($this->user('u4'), $this->server($owner), 'hls', self::NOW));
    }

    public function testStaleAndStoppedRowsDoNotCount(): void
    {
        $owner = $this->user('owner');
        $a = $this->server($owner);
        $this->setCap(Settings::STREAM_CAP_SERVER_KEY, '1');
        $u = $this->user('u');

        $this->row($a, $u, ['last_seen_at' => self::NOW - 121]);
        $this->row($a, $u, ['stopped' => 1]);
        $this->assertMatchesRegularExpression(self::UUID, $this->model()->claim($u, $a, 'hls', self::NOW)['session']);
    }

    public function testARowSeenExactlyAtTheWindowEdgeStillCounts(): void
    {
        $owner = $this->user('owner');
        $a = $this->server($owner);
        $this->setCap(Settings::STREAM_CAP_SERVER_KEY, '1');
        $this->row($a, $this->user('u'), ['last_seen_at' => self::NOW - TranscodeModel::ACTIVE_WINDOW]);

        $this->assertBusy(fn () => $this->model()->claim($this->user('v'), $a, 'hls', self::NOW));
    }

    // ── The lock row ────────────────────────────────────────────────────────

    public function testMissingLockRowIsBusy(): void
    {
        $alice = $this->user('alice');
        $a = $this->server($alice);
        $this->assertTrue($this->db()->table('matinee_locks')->eq('name', 'streams')->remove());

        $this->assertBusy(fn () => $this->model()->claim($alice, $a, 'hls', self::NOW));
        $this->assertSame(0, $this->rowCount());
        $this->assertSame([self::LOCK_LINE], $this->log->lines);
    }

    public function testClaimRefusesToRunInsideAnOpenTransaction(): void
    {
        $alice = $this->user('alice');
        $a = $this->server($alice);
        $this->db()->startTransaction();
        try {
            $caught = null;
            try {
                $this->model()->claim($alice, $a, 'hls', self::NOW);
            } catch (\Throwable $e) {
                $caught = $e;
            }
            $this->assertNotNull($caught, 'claim() ran inside the caller\'s transaction');
            $this->assertSame(\LogicException::class, get_class($caught));
            $this->assertSame(0, $this->lockCount(), 'the lock row is untouched');
            $this->assertSame(0, $this->rowCount());
            $this->assertTrue($this->db()->getConnection()->inTransaction(), "the caller's transaction is left alone");
        } finally {
            $this->db()->cancelTransaction();
        }
        $this->assertSame(0, $this->lockCount());
    }

    /** A second connection to the same test database, outside PicoDb. */
    private function secondConnection(): PDO
    {
        return new PDO(self::dsn(), DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private static function dsn(): string
    {
        $port = defined('DB_PORT') && DB_PORT ? ';port='.DB_PORT : '';
        return (DB_DRIVER === 'mysql' ? 'mysql' : 'pgsql').':host='.DB_HOSTNAME.$port.';dbname='.DB_NAME;
    }

    private function setLockWait(int $seconds): void
    {
        $this->db()->getConnection()->exec(DB_DRIVER === 'mysql'
            ? 'SET SESSION innodb_lock_wait_timeout = '.$seconds
            : "SET lock_timeout = '".$seconds."s'");
    }

    public function testAClaimWaitingOnAHeldLockIsBusy(): void
    {
        if (DB_DRIVER === 'sqlite') {
            $this->markTestSkipped('lock contention needs a second server connection');
        }
        $alice = $this->user('alice');
        $a = $this->server($alice);

        $other = $this->secondConnection();
        try {
            $other->beginTransaction();
            $this->assertSame(1, $other->exec("UPDATE matinee_locks SET n = n + 1 WHERE name = 'streams'"));
            // A safety net only, so a regression fails in 20 s instead of hanging: claim() bounds its own wait at 5 s.
            $this->setLockWait(20);

            $started = microtime(true);
            $this->assertBusy(fn () => $this->model()->claim($alice, $a, 'hls', self::NOW));
            $waited = microtime(true) - $started;
            $this->assertGreaterThanOrEqual(4.5, $waited, 'the claim waited on the lock');
            $this->assertLessThanOrEqual(6.5, $waited, 'claim() bounds the lock wait at 5 s');
            $this->assertSame([self::LOCK_LINE], $this->log->lines, 'the SQL error is not logged');
        } finally {
            $other->rollBack();
            $other = null;
        }
        $this->assertSame(0, $this->rowCount());
    }

    /**
     * MariaDB's InnoDB takes a transaction's snapshot at its first plain read, and Postgres (READ COMMITTED) takes one
     * per statement. The lock UPDATE is claim()'s first data statement, so on both the counts run after the lock is
     * granted and see the row B committed while A waited.
     */
    public function testCountsRunAfterTheLockIsTaken(): void
    {
        if (DB_DRIVER === 'sqlite') {
            $this->markTestSkipped('needs a second server connection');
        }
        $owner = $this->user('owner');
        $a = $this->server($owner);
        $u = $this->user('u');
        $v = $this->user('v');
        $this->setCap(Settings::STREAM_CAP_SERVER_KEY, '1');
        $this->setLockWait(20);

        // Connection B lives in a child process: it takes the lock, inserts an active row for the server, says so,
        // waits 0.5 s and commits. A's claim starts while B holds the lock and must come back busy.
        $child = <<<'PHP'
            [$dsn, $user, $pass, $serverId, $userId, $now] = array_map('getenv', ['M_DSN', 'M_USER', 'M_PASS', 'M_SERVER', 'M_USER_ID', 'M_NOW']);
            $b = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $b->beginTransaction();
            $b->exec("UPDATE matinee_locks SET n = n + 1 WHERE name = 'streams'");
            $b->prepare('INSERT INTO matinee_transcodes (server_id, user_id, session, kind, created_at, last_seen_at, stopped) VALUES (?, ?, ?, ?, ?, ?, 0)')
                ->execute([(int) $serverId, (int) $userId, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'hls', (int) $now, (int) $now]);
            fwrite(STDOUT, "locked\n");
            fflush(STDOUT);
            usleep(500000);
            $b->commit();
            fwrite(STDOUT, "committed\n");
            PHP;
        $env = [
            'M_DSN' => self::dsn(), 'M_USER' => DB_USERNAME, 'M_PASS' => (string) DB_PASSWORD,
            'M_SERVER' => (string) $a, 'M_USER_ID' => (string) $u, 'M_NOW' => (string) self::NOW,
        ];
        $proc = proc_open([PHP_BINARY, '-r', $child], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($proc);
        try {
            $line = fgets($pipes[1]);
            if ($line !== "locked\n") {
                $this->fail('B did not take the lock: '.stream_get_contents($pipes[2]));
            }
            $this->assertBusy(fn () => $this->model()->claim($v, $a, 'hls', self::NOW), false);
            $this->assertSame("committed\n", fgets($pipes[1]));
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc));
        }
        $this->assertSame(['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'], $this->db()->table(TranscodeModel::TABLE)->findAllByColumn('session'));
    }

    // ── Rollback ────────────────────────────────────────────────────────────

    public function testAFailedInsertRollsEverythingBack(): void
    {
        $alice = $this->user('alice');
        $a = $this->server($alice);
        $taken = $this->row($a, $alice, ['stopped' => 1, 'last_seen_at' => self::NOW]);
        $old = $this->row($a, $alice, ['stopped' => 1, 'last_seen_at' => self::NOW - TranscodeModel::RETENTION - 1]);

        $caught = null;
        try {
            $this->model(fn (): string => $taken)->claim($alice, $a, 'hls', self::NOW);
        } catch (\Throwable $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'the duplicate session fails the claim');
        $this->assertSame(\RuntimeException::class, get_class($caught), 'a failed insert is not busy');
        $this->assertSame('Matinee: could not record the stream.', $caught->getMessage());

        $this->assertFalse($this->db()->getConnection()->inTransaction());
        $this->assertEqualsCanonicalizing([$taken, $old], $this->db()->table(TranscodeModel::TABLE)->findAllByColumn('session'), 'the housekeeping delete was rolled back too');
        $this->assertSame(0, $this->lockCount(), 'the lock bump was rolled back');
    }

    // ── Lookups ─────────────────────────────────────────────────────────────

    public function testFindReturnsOnlyTheCallersWellFormedSession(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $a = $this->server($alice);
        $session = $this->model()->claim($alice, $a, 'hls', self::NOW)['session'];

        $row = $this->model()->find($session, $alice);
        $this->assertSame(['id', 'server_id', 'user_id', 'session', 'kind', 'last_seen_at', 'stopped'], array_keys($row));
        $this->assertSame([$a, $alice, $session, 'hls', self::NOW, 0], [(int) $row['server_id'], (int) $row['user_id'], $row['session'], $row['kind'], (int) $row['last_seen_at'], (int) $row['stopped']]);

        $this->assertNull($this->model()->find($session, $bob), "another user's session");
        $this->assertNull($this->model()->find(strtoupper($session), $alice), 'uppercase');
        $this->assertNull($this->model()->find(substr($session, 0, 35), $alice), '35 characters');
        $this->assertNull($this->model()->find('../'.substr($session, 3), $alice), 'a path');
        $this->assertNull($this->model()->find('dddddddd-dddd-4ddd-8ddd-dddddddddddd', $alice), 'unknown');
    }

    public function testFindRefusesAMalformedSessionEvenWhenStored(): void
    {
        $alice = $this->user('alice');
        $a = $this->server($alice);
        $bad = 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA';
        $this->assertTrue($this->db()->table(TranscodeModel::TABLE)->insert([
            'server_id' => $a, 'user_id' => $alice, 'session' => $bad, 'kind' => 'hls', 'created_at' => 1, 'last_seen_at' => 1,
        ]));
        $this->assertNull($this->model()->find($bad, $alice));
    }

    public function testHeartbeatRefreshesLastSeen(): void
    {
        $alice = $this->user('alice');
        $a = $this->server($alice);
        $session = $this->model()->claim($alice, $a, 'hls', self::NOW)['session'];
        $other = $this->model()->claim($alice, $a, 'hls', self::NOW)['session'];
        $id = (int) $this->model()->find($session, $alice)['id'];

        $this->assertTrue($this->model()->heartbeat($id, self::NOW + 30));

        $this->assertSame(self::NOW + 30, (int) $this->model()->find($session, $alice)['last_seen_at']);
        $this->assertSame(self::NOW, (int) $this->model()->find($other, $alice)['last_seen_at']);
        $this->assertTrue($this->model()->heartbeat($id, self::NOW + 30), 'a second beat in the same second still counts');
        $this->assertFalse($this->model()->heartbeat(999999, self::NOW + 30), 'unknown id');
    }

    public function testHeartbeatNeverRevivesAStaleRow(): void
    {
        $alice = $this->user('alice');
        $a = $this->server($alice);
        $session = $this->row($a, $alice, ['last_seen_at' => self::NOW - TranscodeModel::ACTIVE_WINDOW - 1]);
        $id = (int) $this->model()->find($session, $alice)['id'];

        $this->assertFalse($this->model()->heartbeat($id, self::NOW));
        $this->assertSame(self::NOW - TranscodeModel::ACTIVE_WINDOW - 1, (int) $this->model()->find($session, $alice)['last_seen_at']);
    }

    public function testHeartbeatAtTheWindowEdgeStillRefreshes(): void
    {
        $alice = $this->user('alice');
        $a = $this->server($alice);
        $session = $this->row($a, $alice, ['last_seen_at' => self::NOW - TranscodeModel::ACTIVE_WINDOW]);
        $id = (int) $this->model()->find($session, $alice)['id'];

        $this->assertTrue($this->model()->heartbeat($id, self::NOW));
        $this->assertSame(self::NOW, (int) $this->model()->find($session, $alice)['last_seen_at']);
    }

    public function testHeartbeatNeverRevivesAStoppedRow(): void
    {
        $alice = $this->user('alice');
        $a = $this->server($alice);
        $session = $this->row($a, $alice, ['stopped' => 1]);
        $id = (int) $this->model()->find($session, $alice)['id'];

        $this->assertFalse($this->model()->heartbeat($id, self::NOW + 30));
        $this->assertFalse($this->model()->heartbeat($id, self::NOW), 'not even in the second it was last seen');
        $this->assertSame([self::NOW, 1], [(int) $this->model()->find($session, $alice)['last_seen_at'], (int) $this->model()->find($session, $alice)['stopped']]);
    }

    public function testStopSucceedsOnce(): void
    {
        $alice = $this->user('alice');
        $a = $this->server($alice);
        $session = $this->model()->claim($alice, $a, 'hls', self::NOW)['session'];
        $id = (int) $this->model()->find($session, $alice)['id'];

        $this->assertTrue($this->model()->stop($id));
        $this->assertFalse($this->model()->stop($id));
        $this->assertSame(1, (int) $this->model()->find($session, $alice)['stopped']);
        $this->assertFalse($this->model()->stop(999999), 'unknown id');
    }

    public function testStaleListsAtMostTwoOldestFirst(): void
    {
        $alice = $this->user('alice');
        $a = $this->server($alice);
        $mid = $this->row($a, $alice, ['last_seen_at' => self::NOW - 300]);
        $oldest = $this->row($a, $alice, ['last_seen_at' => self::NOW - 900]);
        $this->row($a, $alice, ['last_seen_at' => self::NOW - 200]);
        $this->row($a, $alice, ['last_seen_at' => self::NOW - 5000, 'stopped' => 1]);
        $this->row($a, $alice, ['last_seen_at' => self::NOW - 120]);
        $this->row($a, $alice);

        $this->assertSame([$oldest, $mid], array_column($this->model()->stale(self::NOW), 'session'));
        $this->assertCount(3, $this->model()->stale(self::NOW, 10), 'stopped and fresh rows are skipped');
    }

    // ── Housekeeping ────────────────────────────────────────────────────────

    public function testClaimPrunesStoppedRowsOlderThanADay(): void
    {
        $alice = $this->user('alice');
        $a = $this->server($alice);
        $this->row($a, $alice, ['stopped' => 1, 'last_seen_at' => self::NOW - TranscodeModel::RETENTION - 1]);
        $keptStopped = $this->row($a, $alice, ['stopped' => 1, 'last_seen_at' => self::NOW - TranscodeModel::RETENTION]);
        $keptStale = $this->row($a, $alice, ['last_seen_at' => self::NOW - TranscodeModel::RETENTION - 1]);

        $new = $this->model()->claim($alice, $a, 'hls', self::NOW)['session'];

        $this->assertEqualsCanonicalizing([$keptStopped, $keptStale, $new], $this->db()->table(TranscodeModel::TABLE)->findAllByColumn('session'));
    }

    // ── Settings ────────────────────────────────────────────────────────────

    public function testDefaultCaps(): void
    {
        $s = new Settings($this->container);
        $this->assertSame([4, 2, 5], [$s->streamCapServer(), $s->streamCapUser(), $s->streamCapGlobal()]);
        $this->assertSame(
            ['matinee_stream_cap_server', 'matinee_stream_cap_user', 'matinee_stream_cap_global'],
            [Settings::STREAM_CAP_SERVER_KEY, Settings::STREAM_CAP_USER_KEY, Settings::STREAM_CAP_GLOBAL_KEY]
        );
    }

    public function testCapsReadSavedValuesAndFallBackOnBadOnes(): void
    {
        $s = new Settings($this->container);
        $this->setCap(Settings::STREAM_CAP_SERVER_KEY, '7');
        $this->setCap(Settings::STREAM_CAP_USER_KEY, '3');
        $this->setCap(Settings::STREAM_CAP_GLOBAL_KEY, '9');
        $this->assertSame([7, 3, 9], [$s->streamCapServer(), $s->streamCapUser(), $s->streamCapGlobal()]);

        foreach (['0', '-1', 'abc', '', '1001', '99999999999999999999'] as $bad) {
            foreach ([Settings::STREAM_CAP_SERVER_KEY, Settings::STREAM_CAP_USER_KEY, Settings::STREAM_CAP_GLOBAL_KEY] as $key) {
                $this->setCap($key, $bad);
            }
            $this->assertSame([4, 2, 5], [$s->streamCapServer(), $s->streamCapUser(), $s->streamCapGlobal()], "'$bad' falls back");
        }

        foreach ([Settings::STREAM_CAP_SERVER_KEY, Settings::STREAM_CAP_USER_KEY, Settings::STREAM_CAP_GLOBAL_KEY] as $key) {
            $this->setCap($key, (string) Settings::LIMIT_MAX);
        }
        $this->assertSame(1000, Settings::LIMIT_MAX);
        $this->assertSame([1000, 1000, 1000], [$s->streamCapServer(), $s->streamCapUser(), $s->streamCapGlobal()], 'the top of the range is kept');
    }

    public function testThePluginRegistersTheModel(): void
    {
        $this->assertInstanceOf(TranscodeModel::class, $this->container['matineeTranscodeModel']);
    }

    /** M1: the in-flight gate's global cap is one admin setting, read the way the stream caps are. */
    public function testTheInflightGlobalCapDefaultsTo8AndFallsBackOnBadValues(): void
    {
        $s = new Settings($this->container);
        $this->assertSame('matinee_inflight_global', Settings::INFLIGHT_GLOBAL_KEY);
        $this->assertSame(8, $s->inflightGlobal());
        $this->setCap(Settings::INFLIGHT_GLOBAL_KEY, '3');
        $this->assertSame(3, $s->inflightGlobal());
        foreach (['0', '-1', 'abc', '', '1001', '99999999999999999999'] as $bad) {
            $this->setCap(Settings::INFLIGHT_GLOBAL_KEY, $bad);
            $this->assertSame(8, $s->inflightGlobal(), "'$bad' falls back");
        }
        $this->setCap(Settings::INFLIGHT_GLOBAL_KEY, '1000');
        $this->assertSame(1000, $s->inflightGlobal());
    }

    /**
     * The plugin's gate sits under DATA_DIR/matinee/inflight, 4 per user, and reads the saved global cap on every run.
     * It is inspected, never run: DATA_DIR is read-only in the real-DB runs.
     */
    public function testThePluginRegistersTheInflightGateOnTheSavedCap(): void
    {
        $gate = $this->container['matineeInflightGate'];
        $this->assertInstanceOf(InflightGate::class, $gate);
        $prop = fn (string $name): mixed => (new \ReflectionProperty(InflightGate::class, $name))->getValue($gate);
        $this->assertSame(DATA_DIR.'/matinee/inflight', $prop('dir'));
        $this->assertSame(InflightGate::PER_USER, $prop('perUser'));
        $this->assertSame(8, ($prop('globalCap'))());
        $this->setCap(Settings::INFLIGHT_GLOBAL_KEY, '2');
        $this->assertSame(2, ($prop('globalCap'))());
    }
}
