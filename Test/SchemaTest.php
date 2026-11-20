<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Model\ProjectModel;
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

    private function project(string $name): int
    {
        return (int) (new ProjectModel($this->container))->create(['name' => $name]);
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

    private function share(int $serverId, string $audience, ?int $projectId, int $createdBy = 1): int|false
    {
        $ok = $this->db()->table('matinee_shares')->insert([
            'server_id' => $serverId,
            'created_by' => $createdBy,
            'audience' => $audience,
            'project_id' => $projectId,
            'whole_server' => 0,
            'is_enabled' => 1,
            'created_at' => 10,
        ]);
        return $ok ? (int) $this->db()->getLastId() : false;
    }

    private function section(int $shareId, string $key): void
    {
        $this->assertTrue($this->db()->table('matinee_share_sections')->insert(['share_id' => $shareId, 'section_key' => $key]));
    }

    private function rows(string $table): int
    {
        return $this->db()->table($table)->count();
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
        $project = $this->project('Movies');
        $serverId = $this->server($owner);
        $shareId = $this->share($serverId, 'project', $project, $owner);
        $this->section($shareId, '12');

        $server = $this->db()->table('matinee_servers')->eq('id', $serverId)->findOne();
        foreach (self::TOKEN_ROW + ['owner_user_id' => $owner, 'name' => 'Living room', 'base_url' => 'https://plex.example.test:32400',
            'machine_id' => 'abc123', 'is_enabled' => 1, 'status' => 'ok', 'last_checked_at' => 10, 'created_at' => 10, 'updated_at' => 10] as $col => $value) {
            $this->assertEquals($value, $server[$col], "matinee_servers.$col");
        }

        $share = $this->db()->table('matinee_shares')->eq('id', $shareId)->findOne();
        foreach (['server_id' => $serverId, 'created_by' => $owner, 'audience' => 'project', 'project_id' => $project,
            'whole_server' => 0, 'is_enabled' => 1, 'created_at' => 10] as $col => $value) {
            $this->assertEquals($value, $share[$col], "matinee_shares.$col");
        }

        $this->assertSame([['share_id' => $shareId, 'section_key' => '12']], array_map(
            fn ($r) => ['share_id' => (int) $r['share_id'], 'section_key' => $r['section_key']],
            $this->db()->table('matinee_share_sections')->findAll(),
        ));
    }

    public function testEveryoneShareHasNoProject(): void
    {
        $serverId = $this->server($this->user('alice'));
        $shareId = $this->share($serverId, 'everyone', null);
        $this->assertNotFalse($shareId);
        $this->assertNull($this->db()->table('matinee_shares')->eq('id', $shareId)->findOneColumn('project_id'));
    }

    public function testUserDeleteCascades(): void
    {
        $owner = $this->user('alice');
        $other = $this->user('bob');
        $project = $this->project('Movies');
        $serverId = $this->server($owner);
        $keptServer = $this->server($other);
        $this->section($this->share($serverId, 'project', $project, $owner), '1');
        $this->section($this->share($serverId, 'everyone', null, $owner), '2');
        $this->section($this->share($keptServer, 'project', $project, $other), '3');

        $this->assertTrue((new UserModel($this->container))->remove($owner));

        $this->assertSame([$keptServer], array_map('intval', $this->db()->table('matinee_servers')->findAllByColumn('id')));
        $this->assertSame(1, $this->rows('matinee_shares'));
        $this->assertSame(['3'], $this->db()->table('matinee_share_sections')->findAllByColumn('section_key'));
    }

    public function testServerDeleteCascades(): void
    {
        $serverId = $this->server($this->user('alice'));
        $this->section($this->share($serverId, 'project', $this->project('Movies')), '1');

        $this->assertTrue($this->db()->table('matinee_servers')->eq('id', $serverId)->remove());

        $this->assertSame(0, $this->rows('matinee_shares'));
        $this->assertSame(0, $this->rows('matinee_share_sections'));
    }

    public function testProjectDeleteCascades(): void
    {
        $owner = $this->user('alice');
        $doomed = $this->project('Movies');
        $kept = $this->project('Shows');
        $serverId = $this->server($owner);
        $this->section($this->share($serverId, 'project', $doomed), '1');
        $keptShare = $this->share($serverId, 'project', $kept);
        $this->section($keptShare, '2');

        $this->assertTrue((new ProjectModel($this->container))->remove($doomed));

        $this->assertSame(1, $this->rows('matinee_servers'));
        $this->assertSame([$keptShare], array_map('intval', $this->db()->table('matinee_shares')->findAllByColumn('id')));
        $this->assertSame(['2'], $this->db()->table('matinee_share_sections')->findAllByColumn('section_key'));
    }

    public function testUniqueShareAudience(): void
    {
        $owner = $this->user('alice');
        $project = $this->project('Movies');
        $serverId = $this->server($owner);
        $otherServer = $this->server($owner);

        $this->assertNotFalse($this->share($serverId, 'project', $project));
        // PicoDb answers false on SQLite/MySQL and throws on Postgres; either way the duplicate is refused.
        try {
            $dup = @$this->share($serverId, 'project', $project);
        } catch (\PicoDb\SQLException) {
            $dup = false;
        }
        $this->assertFalse($dup);
        $this->assertSame(1, $this->rows('matinee_shares'));

        // Same project on another server, and another audience on the same server, are distinct shares.
        $this->assertNotFalse($this->share($otherServer, 'project', $project));
        $this->assertNotFalse($this->share($serverId, 'everyone', null));
    }

    public function testDuplicateSectionRefused(): void
    {
        $shareId = $this->share($this->server($this->user('alice')), 'everyone', null);
        $this->section($shareId, '7');
        try {
            $dup = @$this->db()->table('matinee_share_sections')->insert(['share_id' => $shareId, 'section_key' => '7']);
        } catch (\PicoDb\SQLException) {
            $dup = false;
        }
        $this->assertFalse($dup);
    }

    // At most one everyone-share per server: UNIQUE(server_id, audience, project_id) alone lets NULL project_ids repeat.
    public function testDuplicateEveryoneShareRefused(): void
    {
        if (DB_DRIVER === 'mysql') {
            $this->markTestSkipped('MySQL has no partial unique index; ShareModel enforces one everyone-share per server.');
        }
        $serverId = $this->server($this->user('alice'));
        $otherServer = $this->server($this->user('bob'));
        $this->assertNotFalse($this->share($serverId, 'everyone', null));
        try {
            $dup = @$this->share($serverId, 'everyone', null);
        } catch (\PicoDb\SQLException) {
            $dup = false;
        }
        $this->assertFalse($dup);
        $this->assertSame(1, $this->rows('matinee_shares'));
        $this->assertNotFalse($this->share($otherServer, 'everyone', null));
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
