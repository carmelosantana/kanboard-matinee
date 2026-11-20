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

    public function testSchemaVersionIsTwo(): void
    {
        $this->assertSame(2, \Kanboard\Plugin\Matinee\Schema\VERSION);
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
}
