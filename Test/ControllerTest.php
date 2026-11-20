<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/support/ControllerHarness.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Matinee\Controller\MatineeAdminController;
use Kanboard\Plugin\Matinee\Controller\MatineeServerController;
use Kanboard\Plugin\Matinee\Model\Settings;
use Kanboard\Plugin\Matinee\Model\SharePolicy;

/**
 * The user and admin controllers, templates, hooks and ACL (plan Task 9; spec §4, §5, §11 S1, S5, S6, S9).
 * Plex is FakeTransport behind the client factory; responses are recorded, never sent.
 */
class ControllerTest extends Base
{
    use MatineeControllerHarness;

    private const SRV = 'MatineeServerController';
    private const ADM = 'MatineeAdminController';
    private const ADMIN = 1;

    private int $owner;
    private int $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMatinee();
        $this->owner = $this->user('olive');
        $this->outsider = $this->user('oscar');
        $this->loginAs($this->owner);
    }

    protected function tearDown(): void
    {
        $this->cleanKeyDir();
        parent::tearDown();
    }

    private function forbidden(callable $fn, string $label = ''): void
    {
        try {
            $fn();
        } catch (AccessForbiddenException) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->fail('expected AccessForbiddenException '.$label);
    }

    private function serverCount(): int
    {
        return $this->container['db']->table('matinee_servers')->count();
    }

    private function shareCount(): int
    {
        return $this->container['db']->table('matinee_shares')->count();
    }

    /** A project the owner manages, and a whole-server share of $serverId with it. */
    private function projectShare(int $serverId, ?int $projectId = null): int
    {
        $projectId ??= $this->project('Movie night');
        if ($this->container['projectUserRoleModel']->getUserRole($projectId, $this->owner) !== Role::PROJECT_MANAGER) {
            $this->role($projectId, $this->owner, Role::PROJECT_MANAGER);
        }
        return $this->container['matineeShareModel']->create($this->owner, $serverId, SharePolicy::AUDIENCE_PROJECT, $projectId, true, []);
    }

    // ── CSRF ─────────────────────────────────────────────────────────────────

    public function testEveryPostWithoutACsrfTokenIsForbiddenAndChangesNothing(): void
    {
        $server = $this->server($this->owner);
        $share = $this->projectShare($server);
        $before = [$this->serverCount(), $this->shareCount(), $this->container['matineeServerModel']->get($server)];
        $plexCalls = count($this->t->requests);

        $posts = [
            [self::SRV, 'save', [], ['name' => 'Den', 'url' => 'https://den.example.test:32400', 'token' => self::TOKEN]],
            [self::SRV, 'save', [], ['server_id' => $server, 'name' => 'Renamed', 'url' => self::BASE, 'token' => '']],
            [self::SRV, 'verify', ['server_id' => $server], []],
            [self::SRV, 'remove', ['server_id' => $server], []],
            [self::SRV, 'shareSave', ['server_id' => $server], ['audience' => 'everyone', 'whole_server' => '1']],
            [self::SRV, 'shareUnlink', ['share_id' => $share], []],
        ];
        foreach ($posts as [$controller, $action, $get, $post]) {
            $this->forbidden(fn () => $this->call($controller, $action, $get, $post, false), $action);
            // A wrong token is refused the same way.
            $this->forbidden(fn () => $this->call($controller, $action, $get, $post + ['csrf_token' => 'nope'], false), $action);
        }

        $this->loginAs(self::ADMIN);
        $admin = [
            [self::ADM, 'save', [], ['matinee_connect_policy' => 'admins', 'matinee_private_targets' => '', 'matinee_allowed_hosts' => '', 'matinee_attempt_limit' => '5', 'matinee_max_servers' => '1']],
            [self::ADM, 'toggle', ['server_id' => $server], ['enabled' => '0']],
            [self::SRV, 'remove', ['server_id' => $server], []],
            [self::SRV, 'shareUnlink', ['share_id' => $share], []],
        ];
        foreach ($admin as [$controller, $action, $get, $post]) {
            $this->forbidden(fn () => $this->call($controller, $action, $get, $post, false), $action);
        }

        $this->assertSame($before, [$this->serverCount(), $this->shareCount(), $this->container['matineeServerModel']->get($server)]);
        $this->assertSame(Settings::POLICY_EVERYONE, (new Settings($this->container))->connectPolicy());
        $this->assertCount($plexCalls, $this->t->requests, 'no Plex call');
    }

    // ── ACL ──────────────────────────────────────────────────────────────────

    /** @return list<string> the public actions a controller declares */
    private static function actions(string $class): array
    {
        $out = [];
        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if (! $m->isStatic() && ! $m->isConstructor() && str_starts_with($m->getDeclaringClass()->getName(), 'Kanboard\\Plugin\\Matinee\\')) {
                $out[] = $m->getName();
            }
        }
        sort($out);
        return $out;
    }

    public function testEveryActionHasAnAclRuleAndAnonymousUsersAreRefused(): void
    {
        $map = $this->container['applicationAccessMap'];
        $auth = $this->container['applicationAuthorization'];
        $this->assertSame(['create', 'edit', 'index', 'remove', 'save', 'shareCreate', 'shareSave', 'shareUnlink', 'show', 'verify'], self::actions(MatineeServerController::class));
        $this->assertSame(['save', 'show', 'toggle'], self::actions(MatineeAdminController::class));

        foreach (self::actions(MatineeServerController::class) as $action) {
            $this->assertSame($map->getRoleHierarchy(Role::APP_USER), $map->getRoles(self::SRV, $action), $action);
            $this->assertTrue($auth->isAllowed(self::SRV, $action, Role::APP_USER), $action);
            $this->assertFalse($auth->isAllowed(self::SRV, $action, Role::APP_PUBLIC), $action);
        }
        foreach (self::actions(MatineeAdminController::class) as $action) {
            $this->assertSame($map->getRoleHierarchy(Role::APP_ADMIN), $map->getRoles(self::ADM, $action), $action);
            $this->assertTrue($auth->isAllowed(self::ADM, $action, Role::APP_ADMIN), $action);
            $this->assertFalse($auth->isAllowed(self::ADM, $action, Role::APP_MANAGER), $action);
            $this->assertFalse($auth->isAllowed(self::ADM, $action, Role::APP_USER), $action);
            $this->assertFalse($auth->isAllowed(self::ADM, $action, Role::APP_PUBLIC), $action);
        }
    }

    public function testAdminActionsAsAnAppUserAreForbidden(): void
    {
        $server = $this->server($this->owner);
        foreach ([$this->owner, $this->user('mgr', Role::APP_MANAGER)] as $userId) {
            $this->loginAs($userId);
            $this->forbidden(fn () => $this->get(self::ADM, 'show'), 'show');
            $this->forbidden(fn () => $this->call(self::ADM, 'save', [], ['matinee_connect_policy' => 'admins', 'matinee_attempt_limit' => '1', 'matinee_max_servers' => '1']), 'save');
            $this->forbidden(fn () => $this->call(self::ADM, 'toggle', ['server_id' => $server], ['enabled' => '0']), 'toggle');
        }
        $this->assertSame(1, (int) $this->container['matineeServerModel']->get($server)['is_enabled']);
        $this->assertSame(Settings::POLICY_EVERYONE, (new Settings($this->container))->connectPolicy());
    }

    // ── IDOR and S5 ──────────────────────────────────────────────────────────

    public function testAnotherUsersServerIsForbidden(): void
    {
        $server = $this->server($this->owner);
        $this->loginAs($this->outsider);
        $id = ['server_id' => $server];
        $this->forbidden(fn () => $this->get(self::SRV, 'show', $id), 'show');
        $this->forbidden(fn () => $this->get(self::SRV, 'edit', $id), 'edit');
        $this->forbidden(fn () => $this->get(self::SRV, 'shareCreate', $id), 'shareCreate');
        $this->forbidden(fn () => $this->call(self::SRV, 'verify', $id), 'verify');
        $this->forbidden(fn () => $this->call(self::SRV, 'remove', $id), 'remove');
        $this->forbidden(fn () => $this->call(self::SRV, 'save', [], ['server_id' => $server, 'name' => 'Mine now', 'url' => self::BASE, 'token' => '']), 'save');
        $this->forbidden(fn () => $this->call(self::SRV, 'shareSave', $id, ['audience' => 'everyone', 'whole_server' => '1']), 'shareSave');
        // An id that does not exist reads the same.
        $this->forbidden(fn () => $this->get(self::SRV, 'show', ['server_id' => $server + 100]), 'missing');

        $this->assertSame('Living room', $this->container['matineeServerModel']->get($server)['name']);
        $this->assertSame(1, $this->serverCount());
        $this->assertSame(0, $this->shareCount());
        // The outsider's list shows only their own servers.
        $this->assertStringNotContainsString('Living room', $this->get(self::SRV, 'index')->body());
    }

    public function testAnAdminMayOpenAndRemoveAnyServerButNotEditOrShareIt(): void
    {
        $server = $this->server($this->owner);
        $this->loginAs(self::ADMIN);
        $this->assertStringContainsString('Living room', $this->get(self::SRV, 'show', ['server_id' => $server])->body());
        $this->forbidden(fn () => $this->get(self::SRV, 'edit', ['server_id' => $server]), 'edit');
        $this->forbidden(fn () => $this->get(self::SRV, 'shareCreate', ['server_id' => $server]), 'shareCreate');
        $this->call(self::SRV, 'remove', ['server_id' => $server]);
        $this->assertSame(0, $this->serverCount());
    }

    public function testAnOutsiderCannotUnlinkAnotherServersShareWhateverProjectTheUrlNames(): void
    {
        $server = $this->server($this->owner);
        $share = $this->projectShare($server);
        // The outsider manages a project of their own: naming it in the URL or the form grants nothing (S5).
        $theirs = $this->project('Oscar project');
        $this->role($theirs, $this->outsider, Role::PROJECT_MANAGER);
        $this->loginAs($this->outsider);
        $this->forbidden(fn () => $this->call(self::SRV, 'shareUnlink', ['share_id' => $share, 'project_id' => $theirs], ['project_id' => $theirs]));
        $this->forbidden(fn () => $this->call(self::SRV, 'shareUnlink', ['share_id' => $share + 100]));
        $this->assertSame(1, $this->shareCount());
    }

    public function testTheSharesProjectManagerUnlinksFromTheRowsProject(): void
    {
        $server = $this->server($this->owner);
        $project = $this->project('Movie night');
        $share = $this->projectShare($server, $project);
        $this->role($project, $this->outsider, Role::PROJECT_MANAGER);
        $this->loginAs($this->outsider);
        $response = $this->call(self::SRV, 'shareUnlink', ['share_id' => $share]);
        $this->assertSame(0, $this->shareCount());
        $this->assertSame('Share removed.', $this->flash('success'));
        $this->assertArrayHasKey('Location', $response->headers());
    }

    // ── forms ────────────────────────────────────────────────────────────────

    /** @return string the <input> named token in $html */
    private function tokenInput(string $html): string
    {
        $this->assertSame(1, preg_match_all('/<input\b[^>]*\bname="token"[^>]*>/', $html, $m));
        return $m[0][0];
    }

    public function testTheTokenFieldIsWriteOnly(): void
    {
        $add = $this->get(self::SRV, 'create')->body();
        $input = $this->tokenInput($add);
        $this->assertStringContainsString('type="password"', $input);
        $this->assertStringContainsString('value=""', $input);
        $this->assertStringContainsString('autocomplete="off"', $input);
        $this->assertStringNotContainsString('placeholder', $input);

        $server = $this->server($this->owner);
        $edit = $this->get(self::SRV, 'edit', ['server_id' => $server])->body();
        $input = $this->tokenInput($edit);
        $this->assertStringContainsString('type="password"', $input);
        $this->assertStringContainsString('value=""', $input);
        $this->assertStringContainsString('placeholder="stored — leave blank to keep"', $input);
        $this->assertStringNotContainsString(self::TOKEN, $edit);
        $this->assertStringContainsString('value="Living room"', $edit);
        $this->assertStringContainsString('value="'.self::BASE.'"', $edit);
    }

    public function testConnectAddsTheServerAndOpensIt(): void
    {
        $this->queueOk();
        $response = $this->call(self::SRV, 'save', [], ['name' => 'Den', 'url' => self::BASE, 'token' => self::TOKEN]);
        $server = $this->container['matineeServerModel']->listForOwner($this->owner)[0];
        $this->assertSame('Den', $server['name']);
        $this->assertStringContainsString('server_id='.$server['id'], $response->headers()['Location']);
        $this->assertSame('Server connected.', $this->flash('success'));

        $list = $this->get(self::SRV, 'index')->body();
        $this->assertStringContainsString('Den', $list);
        $this->assertStringContainsString('plex.example.test', $list);
    }

    public function testAConnectFailureShowsExactlyTheGenericMessageAndKeepsOnlyNameAndUrl(): void
    {
        $this->t->respond(200, (string) file_get_contents(__DIR__.'/fixtures/plex/identity.json'))->respond(401, '');
        $response = $this->call(self::SRV, 'save', [], ['name' => '<b>Den</b>', 'url' => self::BASE, 'token' => self::TOKEN]);

        $this->assertSame(self::CONNECT_FAILED, $this->failureOf($response));
        $this->assertSame(0, $this->serverCount());
        $body = $response->body();
        $this->assertStringContainsString('value="&lt;b&gt;Den&lt;/b&gt;"', $body);
        $this->assertStringNotContainsString('<b>Den</b>', $body);
        $this->assertStringContainsString('value="'.self::BASE.'"', $body);
        $this->assertStringContainsString('value=""', $this->tokenInput($body));
        $this->assertStringNotContainsString(self::TOKEN, $body);
    }

    public function testAnAddressThatIsNotABaseUrlIsNotEchoedBack(): void
    {
        foreach (['https://plex.example.test:32400/?X-Plex-Token=abc123', 'https://me:abc123@plex.example.test:32400', 'https://plex.example.test/web#abc123'] as $url) {
            session_set('flash', []);
            $body = $this->call(self::SRV, 'save', [], ['name' => 'Den', 'url' => $url, 'token' => self::TOKEN])->body();
            $this->assertStringNotContainsString('abc123', $body, $url);
            $this->assertStringContainsString('name="url" id="form-url" value=""', $body, $url);
        }
    }

    public function testARefusedUrlReadsTheSameAsARejectedToken(): void
    {
        $response = $this->call(self::SRV, 'save', [], ['name' => 'Den', 'url' => 'ftp://nope', 'token' => self::TOKEN]);
        $this->assertSame(self::CONNECT_FAILED, $this->failureOf($response));
    }

    public function testChangingTheAddressAsksForTheTokenAgain(): void
    {
        $server = $this->server($this->owner);
        $response = $this->call(self::SRV, 'save', [], ['server_id' => $server, 'name' => 'Living room', 'url' => 'https://other.example.test:32400', 'token' => '']);
        $this->assertSame(self::TOKEN_REQUIRED, $this->failureOf($response));
        $this->assertStringContainsString('value="https://other.example.test:32400"', $response->body());
        $this->assertSame(self::BASE, $this->container['matineeServerModel']->get($server)['base_url']);
    }

    public function testABlankTokenKeepsTheStoredOneOnRename(): void
    {
        $server = $this->server($this->owner);
        $this->queueOk();
        $this->call(self::SRV, 'save', [], ['server_id' => $server, 'name' => 'Den', 'url' => self::BASE, 'token' => '']);
        $this->assertSame('Den', $this->container['matineeServerModel']->get($server)['name']);
        $this->assertSame('Server saved.', $this->flash('success'));
        $this->assertSame(self::TOKEN, $this->container['matineeServerModel']->tokenFor(['id' => $server]));
    }

    public function testVerifyReportsOkOrTheGenericFailure(): void
    {
        $server = $this->server($this->owner);
        $this->queueOk();
        $this->call(self::SRV, 'verify', ['server_id' => $server]);
        $this->assertSame('Server is reachable.', $this->flash('success'));

        session_set('flash', []);
        $this->t->respond(401, '');
        $this->call(self::SRV, 'verify', ['server_id' => $server]);
        $this->assertSame(['failure' => self::CONNECT_FAILED], $this->flashes());
        $this->assertSame('reconnect', $this->container['matineeServerModel']->get($server)['status']);
    }

    public function testTheShareFormListsLiveSectionsAndManagedProjectsOnly(): void
    {
        $server = $this->server($this->owner);
        $direct = $this->project('Direct managed');
        $this->role($direct, $this->owner, Role::PROJECT_MANAGER);
        $viaGroup = $this->project('Group managed');
        $this->groupRole($viaGroup, $this->owner, Role::PROJECT_MANAGER);
        $member = $this->project('Only member');
        $this->role($member, $this->owner, Role::PROJECT_MEMBER);
        $custom = $this->project('Custom role');
        $this->role($custom, $this->owner, 'Curator');

        $this->queueSections();
        $body = $this->get(self::SRV, 'shareCreate', ['server_id' => $server])->body();
        $this->assertStringContainsString('<option value="'.$direct.'">Direct managed</option>', $body);
        $this->assertStringContainsString('<option value="'.$viaGroup.'">Group managed</option>', $body);
        $this->assertStringNotContainsString('>Only member</option>', $body);
        $this->assertStringNotContainsString('>Custom role</option>', $body);
        $this->assertStringNotContainsString('value="everyone"', $body);
        $this->assertStringContainsString('name="whole_server"', $body);
        $this->assertStringContainsString('name="sections[]" value="1"', $body);
        $this->assertStringContainsString('TV Shows', $body);
        $this->assertStringNotContainsString('Clips', $body);
    }

    public function testEveryoneIsOfferedToActiveAppAdminsOnly(): void
    {
        $admin = $this->user('ada', Role::APP_ADMIN);
        $server = $this->server($admin);
        $this->loginAs($admin);
        $this->queueSections();
        $this->assertStringContainsString('value="everyone"', $this->get(self::SRV, 'shareCreate', ['server_id' => $server])->body());

        $this->call(self::SRV, 'shareSave', ['server_id' => $server], ['audience' => 'everyone', 'whole_server' => '1']);
        $this->assertSame('Share saved.', $this->flash('success'));
        $this->assertSame(SharePolicy::AUDIENCE_EVERYONE, $this->container['db']->table('matinee_shares')->findOneColumn('audience'));
    }

    public function testTheShareFormShowsTheGenericMessageWhenSectionsFail(): void
    {
        $server = $this->server($this->owner);
        $this->role($this->project('Movie night'), $this->owner, Role::PROJECT_MANAGER);
        $this->t->respond(500, 'upstream says '.self::TOKEN);
        $body = $this->get(self::SRV, 'shareCreate', ['server_id' => $server])->body();
        $this->assertStringContainsString(self::CONNECT_FAILED, $body);
        $this->assertStringNotContainsString('name="sections[]"', $body);
        $this->assertStringContainsString('name="whole_server"', $body);
        $this->assertStringNotContainsString(self::TOKEN, $body);
    }

    public function testSharingSectionsWithAManagedProject(): void
    {
        $server = $this->server($this->owner);
        $project = $this->project('Movie night');
        $this->role($project, $this->owner, Role::PROJECT_MANAGER);
        $this->queueSections();
        $response = $this->call(self::SRV, 'shareSave', ['server_id' => $server], ['audience' => (string) $project, 'sections' => ['1', '2']]);
        $this->assertSame('Share saved.', $this->flash('success'));
        $this->assertStringContainsString('server_id='.$server, $response->headers()['Location']);
        $share = $this->container['matineeShareModel']->listForServer($server, $this->owner)[0];
        $this->assertSame(['1', '2'], $share['sections']);
        $this->assertSame($project, (int) $share['project_id']);

        $show = $this->get(self::SRV, 'show', ['server_id' => $server])->body();
        $this->assertStringContainsString('Movie night', $show);
        $this->assertStringContainsString($this->container['helper']->url->href(self::SRV, 'shareUnlink', ['plugin' => 'Matinee', 'share_id' => $share['id']]), $show);
    }

    public function testAnInvalidShareFlashesTheFixedMessage(): void
    {
        $server = $this->server($this->owner);
        $project = $this->project('Movie night');
        $this->role($project, $this->owner, Role::PROJECT_MANAGER);
        foreach ([
            ['audience' => (string) $project, 'whole_server' => '1', 'sections' => ['1']],
            ['audience' => (string) $project, 'sections' => ['<script>']],
            ['audience' => 'somebody', 'whole_server' => '1'],
            ['audience' => (string) $project],
        ] as $post) {
            session_set('flash', []);
            $this->call(self::SRV, 'shareSave', ['server_id' => $server], $post);
            $this->assertSame(['failure' => self::INVALID_SHARE], $this->flashes(), json_encode($post));
        }
        $this->assertSame(0, $this->shareCount());
        // A project the owner does not manage is refused outright.
        $other = $this->project('Not mine');
        $this->forbidden(fn () => $this->call(self::SRV, 'shareSave', ['server_id' => $server], ['audience' => (string) $other, 'whole_server' => '1']));
    }

    // ── S9 ───────────────────────────────────────────────────────────────────

    public function testMatineePagesSendReferrerPolicySameOrigin(): void
    {
        $server = $this->server($this->owner);
        $responses = [
            $this->get(self::SRV, 'index'),
            $this->get(self::SRV, 'create'),
            $this->get(self::SRV, 'edit', ['server_id' => $server]),
            $this->get(self::SRV, 'show', ['server_id' => $server]),
            $this->call(self::SRV, 'save', [], ['name' => 'Den', 'url' => 'ftp://nope', 'token' => self::TOKEN]),
        ];
        $this->loginAs(self::ADMIN);
        $responses[] = $this->get(self::ADM, 'show');
        $responses[] = $this->call(self::ADM, 'toggle', ['server_id' => $server], ['enabled' => '0']);
        foreach ($responses as $i => $response) {
            $this->assertSame('same-origin', $response->headers()['Referrer-Policy'] ?? null, (string) $i);
        }
        // A refused request carries it too: the header is set before any check.
        $this->loginAs($this->outsider);
        try {
            $this->get(self::SRV, 'show', ['server_id' => $server]);
            $this->fail('expected forbidden');
        } catch (AccessForbiddenException) {
            $this->assertSame('same-origin', $this->container['response']->headers()['Referrer-Policy'] ?? null);
        }
    }

    // ── admin ────────────────────────────────────────────────────────────────

    private function adminSave(array $post): MatineeTestResponse
    {
        return $this->call(self::ADM, 'save', [], $post + [
            'matinee_connect_policy' => 'everyone',
            'matinee_private_targets' => '',
            'matinee_allowed_hosts' => '',
            'matinee_attempt_limit' => '10',
            'matinee_max_servers' => '3',
        ]);
    }

    public function testAdminSettingsRejectAnInvalidTargetLineByNumberAndStoreValidOnes(): void
    {
        $this->loginAs(self::ADMIN);
        $this->adminSave(['matinee_private_targets' => "10.0.0.0/8\n\n10.0.0.0/4 secret-looking-entry\n"]);
        $failure = (string) $this->flash('failure');
        $this->assertStringContainsString('line 3', $failure);
        $this->assertStringNotContainsString('10.0.0.0', $failure);
        $this->assertStringNotContainsString('secret-looking-entry', $failure);
        $this->assertSame('', (string) $this->container['configModel']->getOption(Settings::PRIVATE_TARGETS));

        session_set('flash', []);
        $this->adminSave(['matinee_allowed_hosts' => "plex.example.test\nbad host!"]);
        $this->assertStringContainsString('line 2', (string) $this->flash('failure'));
        $this->assertSame('', (string) $this->container['configModel']->getOption(Settings::ALLOWED_HOSTS));

        foreach ([['matinee_attempt_limit' => '0'], ['matinee_max_servers' => 'x'], ['matinee_connect_policy' => 'nobody']] as $bad) {
            session_set('flash', []);
            $this->adminSave($bad);
            $this->assertNotNull($this->flash('failure'), json_encode($bad));
        }

        session_set('flash', []);
        $this->adminSave([
            'matinee_connect_policy' => 'admins',
            'matinee_private_targets' => "10.0.0.0/8\r\n192.168.1.20:32401, fd00::/16\n10.0.0.0/8",
            'matinee_allowed_hosts' => "Plex.Example.test\n*.plex.direct",
            'matinee_attempt_limit' => '5',
            'matinee_max_servers' => '1',
        ]);
        $this->assertSame('Settings saved successfully.', $this->flash('success'));
        $this->container['memoryCache']->flush();
        $config = $this->container['configModel'];
        $this->assertSame("10.0.0.0/8\n192.168.1.20:32401\nfd00::/16", $config->getOption(Settings::PRIVATE_TARGETS));
        $this->assertSame("plex.example.test\n*.plex.direct", $config->getOption(Settings::ALLOWED_HOSTS));
        $settings = new Settings($this->container);
        $this->assertSame(Settings::POLICY_ADMINS, $settings->connectPolicy());
        $this->assertSame(5, $settings->attemptLimit());
        $this->assertSame(1, $settings->maxServers());

        $page = $this->get(self::ADM, 'show')->body();
        $this->assertStringContainsString('192.168.1.20:32401', $page);
    }

    public function testTheAdminListShowsOwnerHostStatusAndShareCountAndToggles(): void
    {
        $server = $this->server($this->owner);
        $this->projectShare($server);
        $this->loginAs(self::ADMIN);
        $page = $this->get(self::ADM, 'show')->body();
        $this->assertStringContainsString('Living room', $page);
        $this->assertStringContainsString('olive', $page);
        $this->assertStringContainsString('plex.example.test', $page);
        $this->assertStringNotContainsString(self::BASE, $page);
        $this->assertMatchesRegularExpression('#<td[^>]*>\s*1\s*</td>#', $page);
        $this->assertStringNotContainsString(self::TOKEN, $page);

        $this->call(self::ADM, 'toggle', ['server_id' => $server], ['enabled' => '0']);
        $this->assertSame(0, (int) $this->container['matineeServerModel']->get($server)['is_enabled']);
        $this->assertSame('Server disabled.', $this->flash('success'));
        $this->call(self::ADM, 'toggle', ['server_id' => $server], ['enabled' => '1']);
        $this->assertSame(1, (int) $this->container['matineeServerModel']->get($server)['is_enabled']);
        $this->forbidden(fn () => $this->call(self::ADM, 'toggle', ['server_id' => $server + 100], ['enabled' => '0']));
    }

    public function testConnectingIsHiddenWhenThePolicyIsAdminsOnly(): void
    {
        $this->setConfig(Settings::CONNECT_POLICY, Settings::POLICY_ADMINS);
        $addLink = $this->container['helper']->url->href(self::SRV, 'create', ['plugin' => 'Matinee']);
        $this->assertStringNotContainsString($addLink, $this->get(self::SRV, 'index')->body());
        $this->setConfig(Settings::CONNECT_POLICY, Settings::POLICY_EVERYONE);
        $this->assertStringContainsString($addLink, $this->get(self::SRV, 'index')->body());
        $this->setConfig(Settings::CONNECT_POLICY, Settings::POLICY_ADMINS);
        $this->forbidden(fn () => $this->get(self::SRV, 'create'));
        $response = $this->call(self::SRV, 'save', [], ['name' => 'Den', 'url' => self::BASE, 'token' => self::TOKEN]);
        $this->assertSame(self::CONNECT_FAILED, $this->failureOf($response));
    }

    // ── hooks ────────────────────────────────────────────────────────────────

    public function testTheUserSidebarLinkShowsOnlyOnYourOwnProfile(): void
    {
        $hook = $this->container['helper']->hook;
        $own = $hook->render('template:user:sidebar:actions', ['user' => $this->container['userModel']->getById($this->owner)]);
        $this->assertStringContainsString('Plex servers', $own);
        $other = $hook->render('template:user:sidebar:actions', ['user' => $this->container['userModel']->getById($this->outsider)]);
        $this->assertStringNotContainsString('Plex servers', $other);
        $this->loginAs(self::ADMIN);
        $this->assertStringNotContainsString('Plex servers', $hook->render('template:user:sidebar:actions', ['user' => $this->container['userModel']->getById($this->owner)]));
    }

    public function testTheConfigSidebarLinkIsForAdmins(): void
    {
        $hook = $this->container['helper']->hook;
        $this->assertStringNotContainsString('Matinee', $hook->render('template:config:sidebar'));
        $this->loginAs(self::ADMIN);
        $this->assertStringContainsString('Matinee', $hook->render('template:config:sidebar'));
    }
}
