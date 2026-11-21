<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/support/ControllerHarness.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Matinee\Controller\MatineeAdminController;
use Kanboard\Plugin\Matinee\Controller\MatineeServerController;
use Kanboard\Plugin\Matinee\Crypto\KeyStore;
use Kanboard\Plugin\Matinee\Model\Settings;

/**
 * The user and admin controllers, templates, hooks and ACL (plan Tasks 9 and 11; spec §4, §5, §11 S1, S6, S9).
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

    // ── CSRF ─────────────────────────────────────────────────────────────────

    public function testEveryPostWithoutACsrfTokenIsForbiddenAndChangesNothing(): void
    {
        $server = $this->server($this->owner);
        $before = [$this->serverCount(), $this->container['matineeServerModel']->get($server)];
        $plexCalls = count($this->t->requests);

        $posts = [
            [self::SRV, 'save', [], ['name' => 'Den', 'url' => 'https://den.example.test:32400', 'token' => self::TOKEN]],
            [self::SRV, 'save', [], ['server_id' => $server, 'name' => 'Renamed', 'url' => self::BASE, 'token' => '']],
            [self::SRV, 'verify', ['server_id' => $server], []],
            [self::SRV, 'remove', ['server_id' => $server], []],
            [self::SRV, 'share', ['server_id' => $server], ['shared' => '1']],
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
        ];
        foreach ($admin as [$controller, $action, $get, $post]) {
            $this->forbidden(fn () => $this->call($controller, $action, $get, $post, false), $action);
        }

        $this->assertSame($before, [$this->serverCount(), $this->container['matineeServerModel']->get($server)]);
        $this->assertSame(Settings::POLICY_EVERYONE, (new Settings($this->container))->connectPolicy());
        $this->assertCount($plexCalls, $this->t->requests, 'no Plex call');
    }

    /**
     * Every state change is POST only: a GET carrying a valid single-use token (in the query and in a body, so only
     * the method check stands in the way) is refused and changes nothing.
     */
    public function testEveryStateChangeSentAsAGetIsForbiddenEvenWithAValidToken(): void
    {
        $server = $this->server($this->owner);
        $before = [$this->serverCount(), $this->container['matineeServerModel']->get($server)];
        $plexCalls = count($this->t->requests);
        $getWithToken = function (string $controller, string $action, array $get, array $post): void {
            $token = $this->container['token']->getCSRFToken();
            $this->call($controller, $action, $get + ['csrf_token' => $token], $post + ['csrf_token' => $token], false, 'GET');
        };

        $this->queueOk();
        foreach ([
            ['save', [], ['name' => 'Den', 'url' => 'https://den.example.test:32400', 'token' => self::TOKEN]],
            ['save', [], ['server_id' => $server, 'name' => 'Renamed', 'url' => self::BASE, 'token' => '']],
            ['verify', ['server_id' => $server], []],
            ['share', ['server_id' => $server], ['shared' => '1']],
            ['remove', ['server_id' => $server], []],
        ] as [$action, $get, $post]) {
            $this->forbidden(fn () => $getWithToken(self::SRV, $action, $get, $post), $action);
        }
        $this->loginAs(self::ADMIN);
        $this->forbidden(fn () => $getWithToken(self::ADM, 'save', [], ['matinee_connect_policy' => 'admins', 'matinee_private_targets' => '', 'matinee_allowed_hosts' => '', 'matinee_attempt_limit' => '5', 'matinee_max_servers' => '1']), 'admin save');
        $this->forbidden(fn () => $getWithToken(self::ADM, 'toggle', ['server_id' => $server], ['enabled' => '0']), 'toggle');

        $this->assertSame($before, [$this->serverCount(), $this->container['matineeServerModel']->get($server)]);
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

    /** Without sodium or curl no route is registered, but a query string still names the controller: it refuses. */
    public function testEveryControllerRefusesWhenTheExtensionsAreMissing(): void
    {
        $make = [
            self::SRV => fn () => new class($this->container) extends MatineeServerController {
                protected static function supported(): bool { return false; }
            },
            self::ADM => fn () => new class($this->container) extends MatineeAdminController {
                protected static function supported(): bool { return false; }
            },
        ];
        foreach ($make as $label => $fn) {
            $this->call(self::SRV, 'index'); // a fresh recorded response
            $this->forbidden($fn, $label);
            $this->assertSame('same-origin', $this->container['response']->headers()['Referrer-Policy'] ?? null, $label);
        }
        // The real check is Plugin::isSupported().
        $supported = new ReflectionMethod(MatineeServerController::class, 'supported');
        $this->assertSame(\Kanboard\Plugin\Matinee\Plugin::isSupported(), $supported->invoke(null));
    }

    public function testEveryActionHasAnAclRuleAndAnonymousUsersAreRefused(): void
    {
        $map = $this->container['applicationAccessMap'];
        $auth = $this->container['applicationAuthorization'];
        $this->assertSame(['create', 'edit', 'index', 'remove', 'save', 'share', 'show', 'verify'], self::actions(MatineeServerController::class));
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
            $this->forbidden(fn () => $this->call(self::ADM, 'save', [], ['matinee_connect_policy' => 'admins', 'matinee_attempt_limit' => '1', 'matinee_max_servers' => '1', 'matinee_stream_cap_server' => '1', 'matinee_stream_cap_user' => '1', 'matinee_stream_cap_global' => '1']), 'save');
            $this->forbidden(fn () => $this->call(self::ADM, 'toggle', ['server_id' => $server], ['enabled' => '0']), 'toggle');
        }
        $this->assertSame(1, (int) $this->container['matineeServerModel']->get($server)['is_enabled']);
        $this->assertSame(Settings::POLICY_EVERYONE, (new Settings($this->container))->connectPolicy());
        $settings = new Settings($this->container);
        $this->assertSame([4, 2, 5], [$settings->streamCapServer(), $settings->streamCapUser(), $settings->streamCapGlobal()]);
        foreach ([Settings::STREAM_CAP_SERVER_KEY, Settings::STREAM_CAP_USER_KEY, Settings::STREAM_CAP_GLOBAL_KEY] as $key) {
            $this->assertSame('', (string) $this->container['configModel']->getOption($key), $key);
        }
    }

    /** The live users row decides, not the session: a demoted or deactivated admin is refused without signing in again. */
    public function testAnAdminDemotedOrDeactivatedSinceSignInIsRefused(): void
    {
        $server = $this->server($this->owner);
        $settingsBefore = [Settings::CONNECT_POLICY => (new Settings($this->container))->connectPolicy(), 'limit' => (new Settings($this->container))->attemptLimit()];
        foreach ([['role' => Role::APP_USER], ['is_active' => 0]] as $change) {
            $this->loginAs(self::ADMIN);
            $this->container['db']->table('users')->eq('id', self::ADMIN)->update($change);
            $this->assertTrue($this->container['userSession']->isAdmin(), 'the session still says admin');
            $this->forbidden(fn () => $this->call(self::ADM, 'save', [], ['matinee_connect_policy' => 'admins', 'matinee_private_targets' => '', 'matinee_allowed_hosts' => '', 'matinee_attempt_limit' => '5', 'matinee_max_servers' => '1']), 'save '.json_encode($change));
            $this->forbidden(fn () => $this->call(self::ADM, 'toggle', ['server_id' => $server], ['enabled' => '0']), 'toggle '.json_encode($change));
            $this->forbidden(fn () => $this->get(self::ADM, 'show'), 'show '.json_encode($change));
            $this->container['db']->table('users')->eq('id', self::ADMIN)->update(['role' => Role::APP_ADMIN, 'is_active' => 1]);
        }
        $this->container['memoryCache']->flush();
        $this->assertSame($settingsBefore, [Settings::CONNECT_POLICY => (new Settings($this->container))->connectPolicy(), 'limit' => (new Settings($this->container))->attemptLimit()]);
        $this->assertSame(1, (int) $this->container['matineeServerModel']->get($server)['is_enabled']);
    }

    // ── IDOR ─────────────────────────────────────────────────────────────

    public function testAnotherUsersServerIsForbidden(): void
    {
        $server = $this->server($this->owner);
        $this->loginAs($this->outsider);
        $id = ['server_id' => $server];
        $this->forbidden(fn () => $this->get(self::SRV, 'show', $id), 'show');
        $this->forbidden(fn () => $this->get(self::SRV, 'edit', $id), 'edit');
        $this->forbidden(fn () => $this->call(self::SRV, 'verify', $id), 'verify');
        $this->forbidden(fn () => $this->call(self::SRV, 'remove', $id), 'remove');
        $this->forbidden(fn () => $this->call(self::SRV, 'share', $id, ['shared' => '1']), 'share');
        $this->forbidden(fn () => $this->call(self::SRV, 'share', ['server_id' => $server + 100], ['shared' => '1']), 'share missing');
        $this->forbidden(fn () => $this->call(self::SRV, 'save', [], ['server_id' => $server, 'name' => 'Mine now', 'url' => self::BASE, 'token' => '']), 'save');
        // An id that does not exist reads the same.
        $this->forbidden(fn () => $this->get(self::SRV, 'show', ['server_id' => $server + 100]), 'missing');

        $this->assertSame('Living room', $this->container['matineeServerModel']->get($server)['name']);
        $this->assertSame(1, $this->serverCount());
        $this->assertSame(0, (int) $this->container['matineeServerModel']->get($server)['is_shared']);
        // The outsider's list shows only their own servers.
        $this->assertStringNotContainsString('Living room', $this->get(self::SRV, 'index')->body());
    }

    public function testAnAdminMayOpenAndRemoveAnyServerButNotEditIt(): void
    {
        $server = $this->server($this->owner);
        $this->loginAs(self::ADMIN);
        $this->assertStringContainsString('Living room', $this->get(self::SRV, 'show', ['server_id' => $server])->body());
        $this->forbidden(fn () => $this->get(self::SRV, 'edit', ['server_id' => $server]), 'edit');
        // An admin can't flip someone's sharing, and the page offers no switch.
        $this->assertSame('', $this->shareForm($this->get(self::SRV, 'show', ['server_id' => $server])->body()));
        $this->forbidden(fn () => $this->call(self::SRV, 'share', ['server_id' => $server], ['shared' => '1']), 'share');
        $this->assertSame(0, (int) $this->container['matineeServerModel']->get($server)['is_shared']);
        $this->call(self::SRV, 'remove', ['server_id' => $server]);
        $this->assertSame(0, $this->serverCount());
    }

    // ── forms ────────────────────────────────────────────────────────────────

    /** @return string the <input> named token in $html */
    private function tokenInput(string $html): string
    {
        $this->assertSame(1, preg_match_all('/<input\b[^>]*\bname="token"[^>]*>/', $html, $m));
        return $m[0][0];
    }

    private function urlInput(string $html): string
    {
        $this->assertSame(1, preg_match_all('/<input\b[^>]*\bname="url"[^>]*>/', $html, $m));
        return $m[0][0];
    }

    public function testTheTokenFieldIsWriteOnly(): void
    {
        $add = $this->get(self::SRV, 'create')->body();
        $input = $this->tokenInput($add);
        $this->assertStringContainsString('type="password"', $input);
        $this->assertStringContainsString('value=""', $input);
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

    /** Browsers and password managers fill neither field: the token is a new secret, the address is not a login. */
    public function testTheTokenAndAddressFieldsAreNotAutofilled(): void
    {
        $server = $this->server($this->owner);
        foreach (['create' => $this->get(self::SRV, 'create')->body(), 'edit' => $this->get(self::SRV, 'edit', ['server_id' => $server])->body()] as $page => $html) {
            $this->assertStringContainsString('autocomplete="new-password"', $this->tokenInput($html), $page);
            $this->assertStringContainsString('autocomplete="off"', $this->urlInput($html), $page);
        }
    }

    public function testTheAddressFieldExplainsTheIpAndPlexDirectForms(): void
    {
        $help = 'Use https://&lt;Plex IP&gt;:32400 or your plex.direct address; Matinee finds the secure name for you. Plex listens on port 32400.';
        $server = $this->server($this->owner);
        foreach (['create' => $this->get(self::SRV, 'create')->body(), 'edit' => $this->get(self::SRV, 'edit', ['server_id' => $server])->body()] as $page => $html) {
            $this->assertStringContainsString('<p class="form-help">'.$help.'</p>', $html, $page);
        }
    }

    public function testAnHttpsIpIsSavedAndShownAsItsPlexDirectName(): void
    {
        $secure = 'https://192-168-1-86.0123456789abcdef0123456789abcdef.plex.direct:32400';
        $this->setConfig('matinee_private_targets', '192.168.1.86:32400');
        defined('MATINEE_TESTING') || define('MATINEE_TESTING', true);
        \Kanboard\Plugin\Matinee\Http\UrlGuard::setResolverForTesting(fn (string $host): array => str_ends_with($host, '.plex.direct') ? ['192.168.1.86'] : []);
        try {
            $this->t->discovers($secure);
            $this->queueOk();
            $this->call(self::SRV, 'save', [], ['name' => 'Den', 'url' => 'https://192.168.1.86:32400', 'token' => self::TOKEN]);
            $this->assertSame('Server connected.', $this->flash('success'));
            $server = $this->container['matineeServerModel']->listForOwner($this->owner)[0];
            $this->assertSame($secure, $server['base_url']);
            $this->assertStringContainsString('192-168-1-86.0123456789abcdef0123456789abcdef.plex.direct', $this->get(self::SRV, 'show', ['server_id' => $server['id']])->body());
        } finally {
            \Kanboard\Plugin\Matinee\Http\UrlGuard::setResolverForTesting(null);
        }
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

    // ── sharing ──────────────────────────────────────────────────────────────

    private function isShared(int $server): int
    {
        return (int) $this->container['matineeServerModel']->get($server)['is_shared'];
    }

    /** The share form on $html, or '' when the page has none. */
    private function shareForm(string $html): string
    {
        return preg_match('#<form[^>]*action="[^"]*controller=MatineeServerController&amp;action=share&amp;[^"]*"[^>]*>.*?</form>#s', $html, $m) === 1 ? $m[0] : '';
    }

    public function testTheOwnerSharesTheWholeServerWithEveryoneAndMakesItPrivateAgain(): void
    {
        $server = $this->server($this->owner);
        $show = $this->get(self::SRV, 'show', ['server_id' => $server])->body();
        $form = $this->shareForm($show);
        $this->assertStringContainsString('method="post"', $form);
        $this->assertStringContainsString('name="csrf_token"', $form);
        $this->assertStringContainsString('name="shared" value="1"', $form);
        $this->assertStringContainsString('Shared with everyone', $show);
        $this->assertStringContainsString('every Kanboard user browse and play this whole server', $show);
        $this->assertStringContainsString('Plex will show all of their playback as you', $show);

        $response = $this->call(self::SRV, 'share', ['server_id' => $server], ['shared' => '1']);
        $this->assertSame(1, $this->isShared($server));
        $this->assertSame('Server shared with everyone.', $this->flash('success'));
        $this->assertStringContainsString('server_id='.$server, $response->headers()['Location']);
        // The wanted state is in the body: a repeated submit changes nothing.
        $this->call(self::SRV, 'share', ['server_id' => $server], ['shared' => '1']);
        $this->assertSame(1, $this->isShared($server));
        $this->assertStringContainsString('name="shared" value="0"', $this->shareForm($this->get(self::SRV, 'show', ['server_id' => $server])->body()));

        session_set('flash', []);
        foreach (['0', '', 'yes'] as $value) {
            $this->call(self::SRV, 'share', ['server_id' => $server], ['shared' => $value]);
            $this->assertSame(0, $this->isShared($server), $value);
        }
        $this->assertSame('Server is private.', $this->flash('success'));
    }

    public function testADisabledServerCannotBeShared(): void
    {
        $server = $this->server($this->owner);
        $this->container['matineeServerModel']->setEnabled($server, false, self::ADMIN);
        $this->assertSame('', $this->shareForm($this->get(self::SRV, 'show', ['server_id' => $server])->body()));
        $this->forbidden(fn () => $this->call(self::SRV, 'share', ['server_id' => $server], ['shared' => '1']));
        $this->assertSame(0, $this->isShared($server));
    }

    /** Making a server private only narrows access, so the owner may do it while an admin has it disabled. */
    public function testTheOwnerCanMakeADisabledServerPrivate(): void
    {
        $server = $this->server($this->owner);
        $this->call(self::SRV, 'share', ['server_id' => $server], ['shared' => '1']);
        $this->container['matineeServerModel']->setEnabled($server, false, self::ADMIN);
        $this->loginAs($this->owner);
        $this->assertStringContainsString('Make private', $this->shareForm($this->get(self::SRV, 'show', ['server_id' => $server])->body()));
        $this->call(self::SRV, 'share', ['server_id' => $server], ['shared' => '0']);
        $this->assertSame(0, $this->isShared($server));
        $this->forbidden(fn () => $this->call(self::SRV, 'share', ['server_id' => $server], ['shared' => '1']));
        $this->assertSame(0, $this->isShared($server));
        $this->loginAs(self::ADMIN);
        $this->forbidden(fn () => $this->call(self::SRV, 'share', ['server_id' => $server], ['shared' => '0']));
    }

    /** Every string a user or a Plex server controls is escaped on every page that shows it. */
    public function testUserControlledStringsAreEscaped(): void
    {
        $owner = $this->user('<u>own"er');
        $this->loginAs($owner);
        $this->queueOk();
        $server = $this->container['matineeServerModel']->connect($owner, '<b>x</b>"\'', self::BASE, self::TOKEN);
        // Shared, so the pages render the toggle's other state and the admin list's Yes.
        $this->call(self::SRV, 'share', ['server_id' => $server], ['shared' => '1']);
        $this->assertSame(1, (int) $this->container['matineeServerModel']->get($server)['is_shared']);

        $pages = [
            'index' => $this->get(self::SRV, 'index')->body(),
            'show' => $this->get(self::SRV, 'show', ['server_id' => $server])->body(),
            'edit' => $this->get(self::SRV, 'edit', ['server_id' => $server])->body(),
        ];
        $this->loginAs(self::ADMIN);
        $pages['admin'] = $this->get(self::ADM, 'show')->body();

        $name = '&lt;b&gt;x&lt;/b&gt;&quot;&#039;';
        foreach ($pages as $page => $body) {
            // Core's project switcher carries project names as JSON in a data attribute, read by its own script: not
            // Matinee's output, so it is left out of the raw-string check.
            $body = (string) preg_replace("/ data-params='[^']*'/", '', $body);
            foreach (['<b>x', '<u>own', '&lt;/b&gt;"\''] as $raw) {
                $this->assertStringNotContainsString($raw, $body, "$page shows $raw");
            }
            $this->assertStringContainsString($name, $body, $page);
        }
        $this->assertStringContainsString('&lt;u&gt;own&quot;er', $pages['admin']);
    }

    /**
     * Spec §2: a bad key fails closed with a notice the admin sees; it names neither the key nor where it lives. A
     * good key shows where it comes from and its 8-hex id.
     */
    public function testTheAdminPageReportsTheKeyStore(): void
    {
        $this->loginAs(self::ADMIN);
        $notice = 'The Matinee key could not be loaded. Check MATINEE_KEY or the key file.';

        $badEnv = base64_encode(random_bytes(16)); // 16 bytes: valid base64, wrong size
        $this->container['matineeKeyStore'] = new KeyStore($this->keyDir, $badEnv);
        $body = $this->get(self::ADM, 'show')->body();
        $this->assertStringContainsString('<p class="alert alert-error">'.$notice.'</p>', $body);
        foreach ([$badEnv, $this->keyDir, 'secret.key', 'keystore:'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        // A key file of the wrong size reads the same.
        mkdir($this->keyDir, 0700, true);
        file_put_contents($this->keyDir.'/secret.key', random_bytes(7));
        chmod($this->keyDir.'/secret.key', 0600);
        $this->container['matineeKeyStore'] = new KeyStore($this->keyDir, null);
        $body = $this->get(self::ADM, 'show')->body();
        $this->assertStringContainsString($notice, $body);
        $this->assertStringNotContainsString($this->keyDir, $body);
        unlink($this->keyDir.'/secret.key');

        $env = random_bytes(32);
        $this->container['matineeKeyStore'] = new KeyStore($this->keyDir, base64_encode($env));
        $body = $this->get(self::ADM, 'show')->body();
        $this->assertStringNotContainsString($notice, $body);
        $this->assertStringContainsString('Encryption key: the MATINEE_KEY environment variable, key id '.KeyStore::keyIdFor($env), $body);
        $this->assertStringNotContainsString(base64_encode($env), $body);

        $store = new KeyStore($this->keyDir, null);
        $this->container['matineeKeyStore'] = $store;
        $body = $this->get(self::ADM, 'show')->body();
        $this->assertStringContainsString('Encryption key: the key file in the data directory, key id '.$store->activeKeyId(), $body);
        $this->assertStringNotContainsString($this->keyDir, $body);
    }

    /**
     * A refusal that core's ACL middleware makes before any Matinee controller is built still carries the header:
     * the plugin sets it on app.bootstrap for any request routed to Matinee. Runs core's Runner, sessions aside.
     */
    public function testARefusalBeforeTheControllerCarriesReferrerPolicy(): void
    {
        $this->container['sessionManager'] = new class($this->container) extends \Kanboard\Core\Session\SessionManager {
            public function open() {}
        };
        $this->loginAs($this->owner);
        foreach ([[self::ADM, 'show', 'Matinee', 'same-origin'], ['DashboardController', 'show', '', null]] as [$controller, $action, $plugin, $expected]) {
            unset($this->container['request'], $this->container['response']);
            $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, ['REQUEST_METHOD' => 'GET'],
                ['controller' => $controller, 'action' => $action, 'plugin' => $plugin], [], [], []);
            $this->container['response'] = $response = new MatineeTestResponse($this->container);
            $this->container['router']->dispatch();
            if ($expected !== null) {
                (new \Kanboard\Core\Controller\Runner($this->container))->execute();
                $this->assertSame(403, $response->code());
            } else {
                $this->container['dispatcher']->dispatch(new \Symfony\Contracts\EventDispatcher\Event(), 'app.bootstrap');
            }
            $this->assertSame($expected, $response->headers()['Referrer-Policy'] ?? null, $controller);
        }
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
            'matinee_stream_cap_server' => '4',
            'matinee_stream_cap_user' => '2',
            'matinee_stream_cap_global' => '5',
        ]);
    }

    private const STREAM_CAPS_ERROR = 'The stream limits must be whole numbers from 1 to 1000.';

    /** @return array{0: int, 1: int, 2: int} the stored caps, read the way TranscodeModel reads them */
    private function streamCaps(): array
    {
        $this->container['memoryCache']->flush();
        $s = new Settings($this->container);
        return [$s->streamCapServer(), $s->streamCapUser(), $s->streamCapGlobal()];
    }

    public function testTheAdminPageShowsTheStreamCaps(): void
    {
        $this->loginAs(self::ADMIN);
        $this->container['configModel']->save([
            Settings::STREAM_CAP_SERVER_KEY => '7',
            Settings::STREAM_CAP_USER_KEY => '3',
            Settings::STREAM_CAP_GLOBAL_KEY => '9',
        ]);
        $this->container['memoryCache']->flush();
        $page = $this->get(self::ADM, 'show')->body();

        foreach ([
            'matinee_stream_cap_server' => ['Long streams per server', '7'],
            'matinee_stream_cap_user' => ['Long streams per user', '3'],
            'matinee_stream_cap_global' => ['Long streams on this Kanboard', '9'],
        ] as $name => [$label, $value]) {
            $this->assertMatchesRegularExpression('#<label for="form-'.$name.'"[^>]*>'.preg_quote($label, '#').'</label>#', $page, $name);
            $this->assertMatchesRegularExpression('#<input type="number" name="'.$name.'" id="form-'.$name.'" value="'.$value.'"[^>]*min="1"[^>]*max="1000"[^>]*required#', $page, $name);
        }
        $this->assertStringContainsString(
            '<p class="form-help">Video sessions and transcoded music count. Keep the last limit under a quarter of PHP-FPM&#039;s pm.max_children.</p>',
            $page
        );
    }

    public function testAdminSaveStoresTheStreamCaps(): void
    {
        $this->loginAs(self::ADMIN);
        $this->adminSave(['matinee_stream_cap_server' => '2', 'matinee_stream_cap_user' => '1', 'matinee_stream_cap_global' => '3']);
        $this->assertSame('Settings saved successfully.', $this->flash('success'));
        $this->assertSame([2, 1, 3], $this->streamCaps());
        $this->assertSame('2', (string) $this->container['configModel']->getOption(Settings::STREAM_CAP_SERVER_KEY));
    }

    public function testABadStreamCapInAnyFieldFlashesTheErrorAndSavesNothing(): void
    {
        $this->loginAs(self::ADMIN);
        $this->adminSave(['matinee_stream_cap_server' => '6', 'matinee_stream_cap_user' => '3', 'matinee_stream_cap_global' => '8']);
        $this->assertSame([6, 3, 8], $this->streamCaps());

        foreach (['matinee_stream_cap_server', 'matinee_stream_cap_user', 'matinee_stream_cap_global'] as $field) {
            foreach (['0', '1001', '1.5', 'x'] as $bad) {
                session_set('flash', []);
                $this->adminSave([$field => $bad, 'matinee_attempt_limit' => '7']);
                $this->assertSame(self::STREAM_CAPS_ERROR, $this->flash('failure'), "$field=$bad");
                $this->assertNull($this->flash('success'), "$field=$bad");
                $this->assertSame([6, 3, 8], $this->streamCaps(), "$field=$bad");
                $this->assertSame(10, (new Settings($this->container))->attemptLimit(), "$field=$bad saves no other field either");
            }
        }
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

    public function testTheAdminListShowsOwnerHostStatusAndToggles(): void
    {
        $server = $this->server($this->owner);
        $this->loginAs(self::ADMIN);
        $page = $this->get(self::ADM, 'show')->body();
        $this->assertStringContainsString('Living room', $page);
        $this->assertStringContainsString('olive', $page);
        $this->assertStringContainsString('plex.example.test', $page);
        $this->assertStringNotContainsString(self::BASE, $page);
        $this->assertStringNotContainsString(self::TOKEN, $page);
        $this->assertStringContainsString('<th>Shared</th>', $page);
        $this->assertMatchesRegularExpression('#<td>\s*No\s*</td>#', $page);
        $this->container['matineeServerModel']->setShared($server, $this->owner, true);
        $this->assertMatchesRegularExpression('#<td>\s*Yes\s*</td>#', $this->get(self::ADM, 'show')->body());

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
