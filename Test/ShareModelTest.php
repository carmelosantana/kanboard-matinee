<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/support/FakeTransport.php';

use KanboardTests\units\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Model\GroupMemberModel;
use Kanboard\Model\GroupModel;
use Kanboard\Model\ProjectGroupRoleModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\ProjectRoleModel;
use Kanboard\Model\ProjectUserRoleModel;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Matinee\Crypto\KeyStore;
use Kanboard\Plugin\Matinee\Http\PlexClient;
use Kanboard\Plugin\Matinee\Http\PlexException;
use Kanboard\Plugin\Matinee\Model\MatineeConnectException;
use Kanboard\Plugin\Matinee\Model\MatineeForbiddenException;
use Kanboard\Plugin\Matinee\Model\MatineeShareException;
use Kanboard\Plugin\Matinee\Model\ShareModel;
use Kanboard\Plugin\Matinee\Model\SharePolicy;
use Kanboard\Plugin\Matinee\Plugin;
use Kanboard\Plugin\Matinee\Test\FakeTransport;

/**
 * ShareModel and the container-wired SharePolicy against a real DB (plan Task 8; spec §5, §11 S5, S6, S13).
 * Servers are connected through ServerModel with FakeTransport behind the client factory; the live section list a
 * share is checked against is whatever the test queues for /library/sections.
 */
class ShareModelTest extends Base
{
    private const TOKEN = 'test-token-NOT-REAL-0000';
    private const BASE = 'https://plex.example.test:32400';
    private const MACHINE = '0123456789abcdef0123456789abcdef01234567';
    private const CLIENT_ID = 'c0ffee00-1111-4222-8333-444455556666';
    private const INVALID = 'matinee.invalid_share';

    /** The live sections the fake server lists: 3 is a clip library, which Matinee never shares. */
    private const LIVE = [
        ['key' => '1', 'type' => 'movie', 'title' => 'Movies'],
        ['key' => '2', 'type' => 'show', 'title' => 'Shows'],
        ['key' => '3', 'type' => 'clip', 'title' => 'Clips'],
        ['key' => '9', 'type' => 'photo', 'title' => 'Photos'],
    ];

    private FakeTransport $t;
    private string $keyDir;
    private int $serverSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../Schema/$driver.php";
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\Matinee\Schema\version_1($pdo);
        \Kanboard\Plugin\Matinee\Schema\version_2($pdo);

        (new Plugin($this->container))->initialize();

        $this->keyDir = sys_get_temp_dir().'/matinee-sharemodel-'.bin2hex(random_bytes(6));
        $this->container['matineeKeyStore'] = new KeyStore($this->keyDir, null);
        $this->t = new FakeTransport();
        $this->container['matineePlexClientFactory'] = $this->container->protect(
            fn (string $baseUrl, #[\SensitiveParameter] string $token, ?string $machineId, string $deviceName): PlexClient
                => new PlexClient($baseUrl, $token, self::CLIENT_ID, $this->t, $machineId, $deviceName)
        );
        $this->container['logger'] = new \Psr\Log\NullLogger();
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

    private function shares(): ShareModel
    {
        return $this->container['matineeShareModel'];
    }

    private function policy(): SharePolicy
    {
        return $this->container['matineeSharePolicy'];
    }

    private function db(): \PicoDb\Database
    {
        return $this->container['db'];
    }

    private function user(string $name, string $role = Role::APP_USER): int
    {
        $id = (int) (new UserModel($this->container))->create(['username' => $name, 'role' => $role]);
        $this->assertGreaterThan(1, $id);
        return $id;
    }

    private function project(string $name): int
    {
        return (int) (new ProjectModel($this->container))->create(['name' => $name]);
    }

    private function role(int $projectId, int $userId, string $role): void
    {
        if (! in_array($role, [Role::PROJECT_MANAGER, Role::PROJECT_MEMBER, Role::PROJECT_VIEWER], true)) {
            (new ProjectRoleModel($this->container))->create($projectId, $role);
        }
        $this->assertTrue((new ProjectUserRoleModel($this->container))->addUser($projectId, $userId, $role));
    }

    /** A server owned by $ownerId, connected through ServerModel with the fake transport. */
    private function server(int $ownerId): int
    {
        $this->t->respond(200, (string) file_get_contents(__DIR__.'/fixtures/plex/identity.json'));
        $this->t->respond(200, (string) file_get_contents(__DIR__.'/fixtures/plex/sections.json'));
        $host = 'https://plex'.(++$this->serverSeq).'.example.test:32400';
        return $this->container['matineeServerModel']->connect($ownerId, 'Server '.$this->serverSeq, $host, self::TOKEN);
    }

    private function queueLive(array $sections = self::LIVE): void
    {
        $this->t->respondJson(['MediaContainer' => ['machineIdentifier' => self::MACHINE, 'Directory' => $sections]]);
    }

    /** A project share of chosen sections, with the live list queued. */
    private function projectShare(int $ownerId, int $serverId, int $projectId, array $sections = ['1']): int
    {
        $this->queueLive();
        return $this->shares()->create($ownerId, $serverId, 'project', $projectId, false, $sections);
    }

    private function expectForbidden(callable $fn): void
    {
        try {
            $fn();
        } catch (MatineeForbiddenException $e) {
            $this->assertSame('matinee.forbidden', $e->getMessage());
            return;
        }
        $this->fail('expected MatineeForbiddenException');
    }

    private function expectInvalid(callable $fn): void
    {
        try {
            $fn();
        } catch (MatineeShareException $e) {
            $this->assertSame(self::INVALID, $e->getMessage());
            $this->assertNull($e->getPrevious());
            return;
        }
        $this->fail('expected MatineeShareException');
    }

    private function shareRows(): int
    {
        return $this->db()->table(ShareModel::TABLE)->count();
    }

    private function sectionRows(): int
    {
        return $this->db()->table(ShareModel::SECTIONS_TABLE)->count();
    }

    private function deactivate(int $userId): void
    {
        $this->db()->table('users')->eq('id', $userId)->update(['is_active' => 0]);
    }

    /** @return list<int> */
    private function ids(array $shares): array
    {
        return array_map(static fn (array $s): int => (int) $s['id'], $shares);
    }

    // ── wiring ───────────────────────────────────────────────────────────────

    public function testPluginRegistersTheShareServices(): void
    {
        $this->assertInstanceOf(ShareModel::class, $this->container['matineeShareModel']);
        $this->assertInstanceOf(SharePolicy::class, $this->container['matineeSharePolicy']);
    }

    // ── create ───────────────────────────────────────────────────────────────

    public function testOwnerManagerSharesChosenLiveSectionsWithAProject(): void
    {
        $owner = $this->user('owner');
        $project = $this->project('Film club');
        $this->role($project, $owner, Role::PROJECT_MANAGER);
        $server = $this->server($owner);
        $this->t->requests = [];

        $id = $this->projectShare($owner, $server, $project, ['2', '1']);

        $this->assertStringEndsWith('/library/sections', $this->t->requests[0]['url']);
        $share = $this->shares()->get($id);
        $this->assertSame('project', $share['audience']);
        $this->assertSame($project, (int) $share['project_id']);
        $this->assertSame($server, (int) $share['server_id']);
        $this->assertSame($owner, (int) $share['created_by']);
        $this->assertSame(0, (int) $share['whole_server']);
        $this->assertSame(1, (int) $share['is_enabled']);
        $this->assertSame(['1', '2'], $share['sections']);
    }

    public function testWholeServerStoresNoSectionRowsAndSkipsTheLiveCall(): void
    {
        $owner = $this->user('owner');
        $project = $this->project('Film club');
        $this->role($project, $owner, Role::PROJECT_MANAGER);
        $server = $this->server($owner);
        $this->t->requests = [];

        $id = $this->shares()->create($owner, $server, 'project', $project, true, []);

        $this->assertSame([], $this->t->requests);
        $this->assertSame(0, $this->sectionRows());
        $share = $this->shares()->get($id);
        $this->assertSame(1, (int) $share['whole_server']);
        $this->assertSame([], $share['sections']);
    }

    public function testSectionsMustBeLiveAndOfAnAllowListedType(): void
    {
        $owner = $this->user('owner');
        $project = $this->project('Film club');
        $this->role($project, $owner, Role::PROJECT_MANAGER);
        $server = $this->server($owner);

        foreach ([['1', '7'], ['1', '3']] as $sections) { // 7 is not on the server; 3 is a clip library
            $this->queueLive();
            $this->expectInvalid(fn () => $this->shares()->create($owner, $server, 'project', $project, false, $sections));
        }
        $this->assertSame(0, $this->shareRows());
        $this->assertSame(0, $this->sectionRows());
    }

    public function testMalformedSectionsAreRefusedBeforeAnyFetchAndNeverEchoed(): void
    {
        $owner = $this->user('owner');
        $project = $this->project('Film club');
        $this->role($project, $owner, Role::PROJECT_MANAGER);
        $server = $this->server($owner);
        $this->t->requests = [];

        $bad = [[], ['1a'], ["1\n"], [''], ['-1'], ['123456789012345678901'], [1], [['1']], ['1', '1'], ['<script>x</script>']];
        foreach ($bad as $sections) {
            $this->expectInvalid(fn () => $this->shares()->create($owner, $server, 'project', $project, false, $sections));
        }
        // Whole-server takes no section list.
        $this->expectInvalid(fn () => $this->shares()->create($owner, $server, 'project', $project, true, ['1']));
        $this->assertSame([], $this->t->requests);
        $this->assertSame(0, $this->shareRows());
    }

    public function testAudienceShapeIsValidated(): void
    {
        $admin = $this->user('sysadmin', Role::APP_ADMIN);
        $project = $this->project('Film club');
        $this->role($project, $admin, Role::PROJECT_MANAGER);
        $server = $this->server($admin);

        $this->expectInvalid(fn () => $this->shares()->create($admin, $server, 'public', null, true, []));
        $this->expectInvalid(fn () => $this->shares()->create($admin, $server, 'project', null, true, []));
        $this->expectInvalid(fn () => $this->shares()->create($admin, $server, 'project', 0, true, []));
        $this->expectInvalid(fn () => $this->shares()->create($admin, $server, 'everyone', $project, true, []));
        $this->assertSame(0, $this->shareRows());
    }

    public function testLiveSectionFailureStoresNothing(): void
    {
        $owner = $this->user('owner');
        $project = $this->project('Film club');
        $this->role($project, $owner, Role::PROJECT_MANAGER);
        $server = $this->server($owner);
        $this->t->fail(PlexException::tokenRejected());

        try {
            $this->shares()->create($owner, $server, 'project', $project, false, ['1']);
            $this->fail('expected MatineeConnectException');
        } catch (MatineeConnectException $e) {
            $this->assertSame('matinee.connect_failed', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame(0, $this->shareRows());
    }

    public function testAProjectShareNeedsTheOwnerToManageAnActiveProject(): void
    {
        $owner = $this->user('owner');
        $server = $this->server($owner);
        $roles = ['viewer' => Role::PROJECT_VIEWER, 'member' => Role::PROJECT_MEMBER, 'custom' => 'Producer'];
        foreach ($roles as $name => $role) {
            $project = $this->project($name);
            $this->role($project, $owner, $role);
            $this->expectForbidden(fn () => $this->shares()->create($owner, $server, 'project', $project, true, []));
        }
        $this->expectForbidden(fn () => $this->shares()->create($owner, $server, 'project', $this->project('no role'), true, []));
        $this->expectForbidden(fn () => $this->shares()->create($owner, $server, 'project', 9999, true, []));

        $archived = $this->project('archived');
        $this->role($archived, $owner, Role::PROJECT_MANAGER);
        $this->assertTrue((new ProjectModel($this->container))->disable($archived));
        $this->expectForbidden(fn () => $this->shares()->create($owner, $server, 'project', $archived, true, []));

        // An app admin is not a project manager by being an admin.
        $admin = $this->user('sysadmin', Role::APP_ADMIN);
        $adminServer = $this->server($admin);
        $this->expectForbidden(fn () => $this->shares()->create($admin, $adminServer, 'project', $this->project('admin-less'), true, []));
        $this->assertSame(0, $this->shareRows());
    }

    public function testAGroupManagerMayShareWithTheProject(): void
    {
        $owner = $this->user('owner');
        $server = $this->server($owner);
        $project = $this->project('Film club');
        $group = (int) (new GroupModel($this->container))->create('Crew');
        (new GroupMemberModel($this->container))->addUser($group, $owner);
        (new ProjectGroupRoleModel($this->container))->addGroup($project, $group, Role::PROJECT_MANAGER);

        $id = $this->shares()->create($owner, $server, 'project', $project, true, []);
        $this->assertSame($project, (int) $this->shares()->get($id)['project_id']);
    }

    public function testOnlyTheServerOwnerMayShareIt(): void
    {
        $owner = $this->user('owner');
        $intruder = $this->user('intruder');
        $admin = $this->user('sysadmin', Role::APP_ADMIN);
        $server = $this->server($owner);
        $project = $this->project('Film club');
        $this->role($project, $intruder, Role::PROJECT_MANAGER);
        $this->role($project, $admin, Role::PROJECT_MANAGER);

        // IDOR: another user's server id, even as the project's manager or as an app admin.
        $this->expectForbidden(fn () => $this->shares()->create($intruder, $server, 'project', $project, true, []));
        $this->expectForbidden(fn () => $this->shares()->create($admin, $server, 'project', $project, true, []));
        $this->expectForbidden(fn () => $this->shares()->create($admin, $server, 'everyone', null, true, []));
        $this->expectForbidden(fn () => $this->shares()->create($owner, 9999, 'project', $project, true, []));
        $this->assertSame(0, $this->shareRows());
    }

    public function testAnInactiveOwnerOrADisabledServerCannotShare(): void
    {
        $owner = $this->user('owner');
        $admin = $this->user('sysadmin', Role::APP_ADMIN);
        $project = $this->project('Film club');
        $this->role($project, $owner, Role::PROJECT_MANAGER);
        $server = $this->server($owner);

        $this->container['matineeServerModel']->setEnabled($server, false, $admin);
        $this->expectForbidden(fn () => $this->shares()->create($owner, $server, 'project', $project, true, []));

        $this->container['matineeServerModel']->setEnabled($server, true, $admin);
        $this->deactivate($owner);
        $this->expectForbidden(fn () => $this->shares()->create($owner, $server, 'project', $project, true, []));
        $this->assertSame(0, $this->shareRows());
    }

    public function testEveryoneSharesAreForActiveAppAdminsOnly(): void
    {
        $user = $this->user('user');
        $manager = $this->user('manager', Role::APP_MANAGER);
        $admin = $this->user('sysadmin', Role::APP_ADMIN);
        $this->expectForbidden(fn () => $this->shares()->create($user, $this->server($user), 'everyone', null, true, []));
        $this->expectForbidden(fn () => $this->shares()->create($manager, $this->server($manager), 'everyone', null, true, []));

        $id = $this->shares()->create($admin, $this->server($admin), 'everyone', null, true, []);
        $share = $this->shares()->get($id);
        $this->assertSame('everyone', $share['audience']);
        $this->assertNull($share['project_id']);
    }

    /**
     * At most one everyone-share per server. MySQL has no partial unique index, so ShareModel checks in code: drop
     * the index on the drivers that have it and the second create is still refused, on every driver.
     */
    public function testASecondEveryoneShareIsRefusedInCode(): void
    {
        $admin = $this->user('sysadmin', Role::APP_ADMIN);
        $server = $this->server($admin);
        $this->shares()->create($admin, $server, 'everyone', null, true, []);
        if (DB_DRIVER !== 'mysql') {
            $this->db()->getConnection()->exec('DROP INDEX matinee_shares_everyone_uq');
        }

        $this->queueLive();
        $this->expectInvalid(fn () => $this->shares()->create($admin, $server, 'everyone', null, false, ['1']));
        $this->assertSame(1, $this->shareRows());
        // Another server may have its own.
        $this->shares()->create($admin, $this->server($admin), 'everyone', null, true, []);
        $this->assertSame(2, $this->shareRows());
    }

    public function testASecondShareWithTheSameProjectIsRefused(): void
    {
        $owner = $this->user('owner');
        $project = $this->project('Film club');
        $this->role($project, $owner, Role::PROJECT_MANAGER);
        $server = $this->server($owner);
        $this->shares()->create($owner, $server, 'project', $project, true, []);

        $this->expectInvalid(fn () => $this->shares()->create($owner, $server, 'project', $project, true, []));
        $this->assertSame(1, $this->shareRows());
    }

    // ── update ───────────────────────────────────────────────────────────────

    public function testOwnerUpdatesScope(): void
    {
        $owner = $this->user('owner');
        $project = $this->project('Film club');
        $this->role($project, $owner, Role::PROJECT_MANAGER);
        $server = $this->server($owner);
        $id = $this->projectShare($owner, $server, $project, ['1']);

        $this->queueLive();
        $this->shares()->update($id, $owner, false, ['9', '2']);
        $this->assertSame(['2', '9'], $this->shares()->get($id)['sections']);

        $this->shares()->update($id, $owner, true, []);
        $share = $this->shares()->get($id);
        $this->assertSame(1, (int) $share['whole_server']);
        $this->assertSame([], $share['sections']);
        $this->assertSame(0, $this->sectionRows());

        $this->queueLive();
        $this->expectInvalid(fn () => $this->shares()->update($id, $owner, false, ['3']));
        $this->assertSame(1, (int) $this->shares()->get($id)['whole_server']);
    }

    public function testUpdatingSomeoneElsesShareIsRefused(): void
    {
        $owner = $this->user('owner');
        $other = $this->user('other');
        $admin = $this->user('sysadmin', Role::APP_ADMIN);
        $project = $this->project('Film club');
        $this->role($project, $owner, Role::PROJECT_MANAGER);
        $this->role($project, $other, Role::PROJECT_MANAGER);
        $id = $this->projectShare($owner, $this->server($owner), $project, ['1']);
        $this->server($other); // the other owner has a server of their own

        $this->t->requests = [];
        // IDOR: a share id on someone else's server, by its project's other manager, an admin, or nobody's id.
        $this->expectForbidden(fn () => $this->shares()->update($id, $other, true, []));
        $this->expectForbidden(fn () => $this->shares()->update($id, $admin, true, []));
        $this->expectForbidden(fn () => $this->shares()->update(9999, $owner, true, []));
        $this->assertSame([], $this->t->requests);
        $this->assertSame(['1'], $this->shares()->get($id)['sections']);
    }

    public function testUpdateRechecksTheManagerRule(): void
    {
        $owner = $this->user('owner');
        $project = $this->project('Film club');
        $this->role($project, $owner, Role::PROJECT_MANAGER);
        $id = $this->projectShare($owner, $this->server($owner), $project, ['1']);

        (new ProjectUserRoleModel($this->container))->changeUserRole($project, $owner, Role::PROJECT_MEMBER);
        $this->expectForbidden(fn () => $this->shares()->update($id, $owner, true, []));
        $this->assertSame(0, (int) $this->shares()->get($id)['whole_server']);
    }

    // ── unlink and enable ────────────────────────────────────────────────────

    public function testTheProjectManagerUnlinksButCannotUpdate(): void
    {
        $owner = $this->user('owner');
        $manager = $this->user('manager');
        $member = $this->user('member');
        $project = $this->project('Film club');
        $this->role($project, $owner, Role::PROJECT_MANAGER);
        $this->role($project, $manager, Role::PROJECT_MANAGER);
        $this->role($project, $member, Role::PROJECT_MEMBER);
        $id = $this->projectShare($owner, $this->server($owner), $project, ['1', '2']);

        $this->expectForbidden(fn () => $this->shares()->update($id, $manager, true, []));
        $this->expectForbidden(fn () => $this->shares()->unlink($id, $member));
        $this->shares()->unlink($id, $manager);
        $this->assertNull($this->shares()->get($id));
        $this->assertSame(0, $this->sectionRows());
        $this->expectForbidden(fn () => $this->shares()->unlink($id, $manager));
    }

    public function testOwnerAndAdminUnlink(): void
    {
        $owner = $this->user('owner');
        $admin = $this->user('sysadmin', Role::APP_ADMIN);
        $project = $this->project('Film club');
        $this->role($project, $owner, Role::PROJECT_MANAGER);
        $server = $this->server($owner);
        $a = $this->shares()->create($owner, $server, 'project', $project, true, []);
        $this->shares()->unlink($a, $owner);
        $b = $this->shares()->create($owner, $server, 'project', $project, true, []);
        $this->shares()->unlink($b, $admin);
        $this->assertSame(0, $this->shareRows());
    }

    public function testOnlyAnActiveAdminEnablesOrDisablesAShare(): void
    {
        $owner = $this->user('owner');
        $admin = $this->user('sysadmin', Role::APP_ADMIN);
        $project = $this->project('Film club');
        $this->role($project, $owner, Role::PROJECT_MANAGER);
        $id = $this->shares()->create($owner, $this->server($owner), 'project', $project, true, []);

        $this->expectForbidden(fn () => $this->shares()->setEnabled($id, false, $owner));
        $this->shares()->setEnabled($id, false, $admin);
        $this->assertSame(0, (int) $this->shares()->get($id)['is_enabled']);
        $this->shares()->setEnabled($id, true, $admin);
        $this->assertSame(1, (int) $this->shares()->get($id)['is_enabled']);
        $this->expectForbidden(fn () => $this->shares()->setEnabled(9999, false, $admin));
        $this->deactivate($admin);
        $this->expectForbidden(fn () => $this->shares()->setEnabled($id, false, $admin));
    }

    // ── listing ──────────────────────────────────────────────────────────────

    public function testListForServer(): void
    {
        $admin = $this->user('sysadmin', Role::APP_ADMIN);
        $project = $this->project('Film club');
        $this->role($project, $admin, Role::PROJECT_MANAGER);
        $server = $this->server($admin);
        $a = $this->projectShare($admin, $server, $project, ['1']);
        $b = $this->shares()->create($admin, $server, 'everyone', null, true, []);
        $this->shares()->create($admin, $this->server($admin), 'everyone', null, true, []);

        $list = $this->shares()->listForServer($server);
        $this->assertSame([$a, $b], $this->ids($list));
        $this->assertSame(['1'], $list[0]['sections']);
    }

    public function testListVisibleExcludesEverythingItShould(): void
    {
        $owner = $this->user('owner');
        $admin = $this->user('sysadmin', Role::APP_ADMIN);
        $viewer = $this->user('viewer');
        $custom = $this->user('custom');
        $outsider = $this->user('outsider');
        $club = $this->project('Film club');
        $other = $this->project('Other');
        $archived = $this->project('Archived');
        foreach ([$club, $other, $archived] as $p) {
            $this->role($p, $owner, Role::PROJECT_MANAGER);
        }
        $this->role($club, $viewer, Role::PROJECT_VIEWER);
        $this->role($club, $custom, 'Producer');
        $this->role($archived, $viewer, Role::PROJECT_VIEWER);

        $server = $this->server($owner);
        $clubShare = $this->shares()->create($owner, $server, 'project', $club, true, []);
        $otherShare = $this->shares()->create($owner, $server, 'project', $other, true, []);
        $archivedShare = $this->shares()->create($owner, $server, 'project', $archived, true, []);
        (new ProjectModel($this->container))->disable($archived);
        $everyone = $this->shares()->create($admin, $this->server($admin), 'everyone', null, true, []);

        $this->assertSame([$clubShare, $everyone], $this->ids($this->shares()->listVisible($viewer, null)));
        $this->assertSame([$clubShare], $this->ids($this->shares()->listVisible($viewer, $club)));
        $this->assertSame([], $this->ids($this->shares()->listVisible($viewer, $other)));
        $this->assertSame([], $this->ids($this->shares()->listVisible($viewer, $archived)));
        // S6: a custom role counts as member.
        $this->assertSame([$clubShare, $everyone], $this->ids($this->shares()->listVisible($custom, null)));
        $this->assertSame([$everyone], $this->ids($this->shares()->listVisible($outsider, null)));
        // §5: the admin has no role in the club, so it is not visible to them.
        $this->assertSame([$everyone], $this->ids($this->shares()->listVisible($admin, null)));
        // The owner sees their live shares, not the archived project's.
        $this->assertSame([$clubShare, $otherShare, $everyone], $this->ids($this->shares()->listVisible($owner, null)));
        $this->assertNotContains($archivedShare, $this->ids($this->shares()->listVisible($owner, null)));

        // A disabled share drops out.
        $this->shares()->setEnabled($clubShare, false, $admin);
        $this->assertSame([$everyone], $this->ids($this->shares()->listVisible($viewer, null)));
        $this->shares()->setEnabled($clubShare, true, $admin);

        // S6: the everyone-share's creator loses app admin.
        $this->db()->table('users')->eq('id', $admin)->update(['role' => Role::APP_USER]);
        $this->assertSame([$clubShare], $this->ids($this->shares()->listVisible($viewer, null)));
        $this->db()->table('users')->eq('id', $admin)->update(['role' => Role::APP_ADMIN]);

        // A disabled server drops out.
        $this->container['matineeServerModel']->setEnabled($server, false, $admin);
        $this->assertSame([$everyone], $this->ids($this->shares()->listVisible($viewer, null)));
        $this->container['matineeServerModel']->setEnabled($server, true, $admin);

        // S6: an inactive owner makes their shares inert.
        $this->deactivate($owner);
        $this->assertSame([$everyone], $this->ids($this->shares()->listVisible($viewer, null)));
        $this->db()->table('users')->eq('id', $owner)->update(['is_active' => 1]);

        // S6: an inactive viewer (the session lives on, the DB says no) sees nothing.
        $this->deactivate($viewer);
        $this->assertSame([], $this->ids($this->shares()->listVisible($viewer, null)));
    }

    // ── the container policy against the DB ──────────────────────────────────

    public function testTheContainerPolicyReadsLiveDbState(): void
    {
        $owner = $this->user('owner');
        $groupManager = $this->user('groupmanager');
        $admin = $this->user('sysadmin', Role::APP_ADMIN);
        $project = $this->project('Film club');
        $this->role($project, $owner, Role::PROJECT_MANAGER);
        $group = (int) (new GroupModel($this->container))->create('Crew');
        (new GroupMemberModel($this->container))->addUser($group, $groupManager);
        (new ProjectGroupRoleModel($this->container))->addGroup($project, $group, Role::PROJECT_MANAGER);
        $server = $this->server($owner);
        $share = $this->shares()->get($this->shares()->create($owner, $server, 'project', $project, true, []));

        // A group role counts (getUserRole), including for unlink.
        $this->assertTrue($this->policy()->canBrowse($groupManager, $share));
        $this->assertTrue($this->policy()->canUnlinkShare($groupManager, $share));
        $this->assertFalse($this->policy()->canEditShare($groupManager, $share));
        $this->assertTrue($this->policy()->canEditShare($owner, $share));
        $this->assertFalse($this->policy()->canBrowse($admin, $share));
        $this->assertTrue($this->policy()->canManageServer($admin, $this->container['matineeServerModel']->get($server)));

        // Whole-server covers only live sections of allow-listed types (S13).
        $this->assertTrue($this->policy()->sectionAllowed($share, '1', self::LIVE));
        $this->assertFalse($this->policy()->sectionAllowed($share, '3', self::LIVE));
        $this->assertFalse($this->policy()->sectionAllowed($share, '7', self::LIVE));

        // The same share array, judged again after the DB changes: nothing is cached.
        $this->deactivate($groupManager);
        $this->assertFalse($this->policy()->canBrowse($groupManager, $share));
        $this->assertTrue((new ProjectModel($this->container))->disable($project));
        $this->assertFalse($this->policy()->canBrowse($owner, $share));
        $this->assertTrue((new ProjectModel($this->container))->enable($project));
        $this->assertTrue($this->policy()->canBrowse($owner, $share));
        $this->container['configModel']->save(['matinee_connect_policy' => 'admins']);
        $this->assertFalse($this->policy()->canConnectServer($owner));
        $this->assertTrue($this->policy()->canConnectServer($admin));
    }
}
