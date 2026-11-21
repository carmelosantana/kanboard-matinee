<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Model\UserModel;

class SchemaTest extends Base
{
    private const TOKEN_ROW = ['token_nonce' => 'bm9uY2U=', 'token_ct' => 'Y3Q=', 'key_id' => 'k1'];

    protected function setUp(): void
    {
        parent::setUp();
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../Schema/$driver.php";
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\Matinee\Schema\version_1($pdo);
        \Kanboard\Plugin\Matinee\Schema\version_2($pdo);
        \Kanboard\Plugin\Matinee\Schema\version_3($pdo);
    }

    private function db(): \PicoDb\Database
    {
        return $this->container['db'];
    }

    private function user(string $name): int
    {
        return (int) (new UserModel($this->container))->create(['username' => $name]);
    }

    private function server(int $ownerId): int
    {
        $this->assertTrue($this->db()->table('matinee_servers')->insert(self::TOKEN_ROW + [
            'owner_user_id' => $ownerId,
            'name' => 'Living room',
            'base_url' => 'https://plex.example.test:32400',
            'machine_id' => 'abc123',
            'is_enabled' => 1,
            'status' => 'ok',
            'last_checked_at' => 10,
            'created_at' => 10,
            'updated_at' => 10,
        ]));
        return (int) $this->db()->getLastId();
    }

    public function testForeignKeysAreEnforced(): void
    {
        if (DB_DRIVER !== 'sqlite') {
            $this->markTestSkipped('PRAGMA foreign_keys is SQLite-only; MySQL and Postgres always enforce them.');
        }
        // The cascade tests below only mean something if SQLite enforces foreign keys (PicoDb turns them on).
        $this->assertSame(1, (int) $this->db()->getConnection()->query('PRAGMA foreign_keys')->fetchColumn());
    }

    public function testCreatesTables(): void
    {
        $owner = $this->user('alice');
        $serverId = $this->server($owner);

        $server = $this->db()->table('matinee_servers')->eq('id', $serverId)->findOne();
        foreach (self::TOKEN_ROW + ['owner_user_id' => $owner, 'name' => 'Living room', 'base_url' => 'https://plex.example.test:32400',
            'machine_id' => 'abc123', 'is_enabled' => 1, 'status' => 'ok', 'last_checked_at' => 10, 'created_at' => 10, 'updated_at' => 10] as $col => $value) {
            $this->assertEquals($value, $server[$col], "matinee_servers.$col");
        }
    }

    // Light scope: a server is private (0) until its owner shares it with everyone (1).
    public function testIsSharedDefaultsToPrivate(): void
    {
        $serverId = $this->server($this->user('alice'));
        $this->assertSame(0, (int) $this->db()->table('matinee_servers')->eq('id', $serverId)->findOneColumn('is_shared'));
        $this->assertTrue($this->db()->table('matinee_servers')->eq('id', $serverId)->update(['is_shared' => 1]));
        $this->assertSame(1, (int) $this->db()->table('matinee_servers')->eq('id', $serverId)->findOneColumn('is_shared'));
    }

    public function testUserDeleteCascades(): void
    {
        $owner = $this->user('alice');
        $kept = $this->server($this->user('bob'));
        $this->server($owner);

        $this->assertTrue((new UserModel($this->container))->remove($owner));

        $this->assertSame([$kept], array_map('intval', $this->db()->table('matinee_servers')->findAllByColumn('id')));
    }

    public function testSchemaVersionIsThree(): void
    {
        $this->assertSame(3, \Kanboard\Plugin\Matinee\Schema\VERSION);
    }

    // v2: matinee_attempts backs the connect/verify rate limit (spec §11 S1).
    public function testAttemptsTableStoresUserAndTime(): void
    {
        $alice = $this->user('alice');
        $this->assertTrue($this->db()->table('matinee_attempts')->insert(['user_id' => $alice, 'created_at' => 1234]));
        $this->assertSame([['user_id' => $alice, 'created_at' => 1234]], array_map(
            fn ($r) => ['user_id' => (int) $r['user_id'], 'created_at' => (int) $r['created_at']],
            $this->db()->table('matinee_attempts')->findAll(),
        ));
    }

    public function testAttemptsNeedARealUser(): void
    {
        try {
            $ok = @$this->db()->table('matinee_attempts')->insert(['user_id' => 9999, 'created_at' => 1]);
        } catch (\PicoDb\SQLException) {
            $ok = false;
        }
        $this->assertFalse($ok);
    }

    public function testUserDeleteCascadesAttempts(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        foreach ([$alice, $alice, $bob] as $user) {
            $this->assertTrue($this->db()->table('matinee_attempts')->insert(['user_id' => $user, 'created_at' => 5]));
        }

        $this->assertTrue((new UserModel($this->container))->remove($alice));

        $this->assertSame([$bob], array_map('intval', $this->db()->table('matinee_attempts')->findAllByColumn('user_id')));
    }

    // v3 (P3, C4): one matinee_transcodes row per long stream, and the caps lock row.
    private function transcode(int $serverId, int $userId, string $session, array $extra = []): bool
    {
        try {
            return (bool) @$this->db()->table('matinee_transcodes')->insert($extra + [
                'server_id' => $serverId,
                'user_id' => $userId,
                'session' => $session,
                'kind' => 'hls',
                'created_at' => 100,
                'last_seen_at' => 100,
            ]);
        } catch (\PicoDb\SQLException) {
            return false;
        }
    }

    private const S1 = '11111111-1111-4111-8111-111111111111';
    private const S2 = '22222222-2222-4222-8222-222222222222';

    public function testLockRowIsSeeded(): void
    {
        $this->assertSame(
            [['name' => 'streams', 'n' => 0]],
            array_map(fn ($r) => ['name' => $r['name'], 'n' => (int) $r['n']], $this->db()->table('matinee_locks')->findAll())
        );
    }

    public function testSeedingTheLockRowAgainChangesNothing(): void
    {
        $pdo = $this->db()->getConnection();
        \Kanboard\Plugin\Matinee\Schema\seed_streams_lock($pdo);
        \Kanboard\Plugin\Matinee\Schema\seed_streams_lock($pdo);
        $this->assertSame(
            [['name' => 'streams', 'n' => 0]],
            array_map(fn ($r) => ['name' => $r['name'], 'n' => (int) $r['n']], $this->db()->table('matinee_locks')->findAll())
        );
    }

    /** SQL Server is not in the test matrix: its seed is checked by reading it. */
    public function testTheMssqlSeedIsGuarded(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../Schema/Mssql.php');
        $this->assertMatchesRegularExpression(
            "/function seed_streams_lock\\(PDO \\\$pdo\\)\\s*\\{\\s*\\\$pdo->exec\\(\"IF NOT EXISTS \\(SELECT 1 FROM dbo\\.matinee_locks WHERE name = 'streams'\\)\\s+INSERT INTO dbo\\.matinee_locks \\(name, n\\) VALUES \\('streams', 0\\)\"\\);\\s*\\}/",
            $src
        );
        $this->assertSame(1, substr_count($src, 'INSERT INTO dbo.matinee_locks'), 'the only insert is the guarded one');
        $this->assertStringContainsString("seed_streams_lock(\$pdo);\n}", $src, 'version_3 seeds through it');
    }

    public function testTranscodesStoreARowAndStoppedDefaultsToZero(): void
    {
        $alice = $this->user('alice');
        $serverId = $this->server($alice);
        $this->assertTrue($this->transcode($serverId, $alice, self::S1, ['kind' => 'mp3', 'created_at' => 7, 'last_seen_at' => 9]));

        $row = $this->db()->table('matinee_transcodes')->eq('session', self::S1)->findOne();
        $this->assertGreaterThan(0, (int) $row['id']);
        $this->assertSame(
            ['server_id' => $serverId, 'user_id' => $alice, 'session' => self::S1, 'kind' => 'mp3', 'created_at' => 7, 'last_seen_at' => 9, 'stopped' => 0],
            ['server_id' => (int) $row['server_id'], 'user_id' => (int) $row['user_id'], 'session' => $row['session'], 'kind' => $row['kind'],
                'created_at' => (int) $row['created_at'], 'last_seen_at' => (int) $row['last_seen_at'], 'stopped' => (int) $row['stopped']]
        );
    }

    public function testDuplicateSessionIsRefused(): void
    {
        $alice = $this->user('alice');
        $serverId = $this->server($alice);
        $this->assertTrue($this->transcode($serverId, $alice, self::S1));
        $this->assertFalse($this->transcode($serverId, $alice, self::S1));
        $this->assertSame(1, $this->db()->table('matinee_transcodes')->count());
    }

    public function testTranscodeNeedsARealServerAndUser(): void
    {
        $alice = $this->user('alice');
        $serverId = $this->server($alice);
        $this->assertFalse($this->transcode(9999, $alice, self::S1), 'unknown server');
        $this->assertFalse($this->transcode($serverId, 9999, self::S2), 'unknown user');
        $this->assertSame(0, $this->db()->table('matinee_transcodes')->count());
    }

    public function testServerDeleteCascadesTranscodes(): void
    {
        $alice = $this->user('alice');
        $gone = $this->server($alice);
        $kept = $this->server($alice);
        $this->assertTrue($this->transcode($gone, $alice, self::S1));
        $this->assertTrue($this->transcode($kept, $alice, self::S2));

        $this->assertTrue($this->db()->table('matinee_servers')->eq('id', $gone)->remove());

        $this->assertSame([self::S2], $this->db()->table('matinee_transcodes')->findAllByColumn('session'));
    }

    public function testUserDeleteCascadesTranscodes(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $serverId = $this->server($alice);
        $this->assertTrue($this->transcode($serverId, $bob, self::S1));
        $this->assertTrue($this->transcode($serverId, $alice, self::S2));

        $this->assertTrue((new UserModel($this->container))->remove($bob));

        $this->assertSame([self::S2], $this->db()->table('matinee_transcodes')->findAllByColumn('session'));
    }
}
