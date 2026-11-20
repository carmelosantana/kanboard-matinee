<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/support/ControllerHarness.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Matinee\Http\PlexException;
use Kanboard\Plugin\Matinee\Model\Settings;
use Kanboard\Plugin\Matinee\Test\FakeTransport;

/**
 * Review focus 1 (plan Task 9; spec §11 S1, S9): a server is connected with the fake token, then every Matinee page
 * is rendered as its owner, an admin and an outsider, and every error path is driven. The token must not appear in
 * any response (body or headers), flash message, exception message or log line, in any spelling a browser or a
 * server would decode.
 */
class NoTokenLeakTest extends Base
{
    use MatineeControllerHarness;

    private const SRV = 'MatineeServerController';
    private const ADM = 'MatineeAdminController';
    private const ADMIN = 1;

    private int $owner;
    private int $outsider;
    private int $server;

    /** @var list<string> everything Matinee showed or said */
    private array $seen = [];
    private int $pages = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMatinee();
        $this->owner = $this->user('olive');
        $this->outsider = $this->user('oscar');
        $this->server = $this->server($this->owner);
        $this->loginAs($this->owner);
    }

    protected function tearDown(): void
    {
        $this->cleanKeyDir();
        parent::tearDown();
    }

    /** Runs a request (queueing $plex first) and keeps its body, headers, flash and any refusal message. */
    private function capture(callable $request, ?callable $plex = null): void
    {
        $this->t = new FakeTransport();
        if ($plex !== null) {
            $plex();
        }
        session_set('flash', []);
        try {
            $response = $request();
        } catch (AccessForbiddenException $e) {
            $this->seen[] = 'forbidden: '.$e->getMessage();
            $response = $this->container['response'];
        }
        $this->seen[] = $response->body();
        $this->seen[] = json_encode($response->headers());
        $this->seen[] = json_encode($this->flashes());
        $this->pages++;
    }

    /** The token in no form: literal, any case, HTML-decoded, URL-decoded, or split by markup. */
    private function assertNoTokenAnywhere(): void
    {
        $everything = array_merge($this->seen, $this->log->lines);
        $this->assertNotEmpty($this->seen);
        $decode = fn (string $s): string => rawurldecode(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach ($everything as $i => $text) {
            foreach ([$text, $decode($text), $decode(strip_tags($text)), stripslashes($text)] as $form) {
                $this->assertStringNotContainsStringIgnoringCase(self::TOKEN, $form, 'output #'.$i);
                $this->assertStringNotContainsStringIgnoringCase(base64_encode(self::TOKEN), $form, 'output #'.$i);
                $this->assertStringNotContainsStringIgnoringCase(bin2hex(self::TOKEN), $form, 'output #'.$i);
            }
        }
    }

    /** @return list<array{0: string, 1: string, 2: array}> every GET page */
    private function pages(): array
    {
        $server = ['server_id' => $this->server];
        return [
            [self::SRV, 'index', []],
            [self::SRV, 'create', []],
            [self::SRV, 'edit', $server],
            [self::SRV, 'show', $server],
            [self::ADM, 'show', []],
        ];
    }

    public function testEveryPageAsOwnerAdminAndOutsider(): void
    {
        foreach ([$this->owner, self::ADMIN, $this->outsider] as $userId) {
            $this->loginAs($userId);
            foreach ($this->pages() as [$controller, $action, $get]) {
                $this->capture(fn () => $this->get($controller, $action, $get));
            }
            $hook = $this->container['helper']->hook;
            $this->seen[] = $hook->render('template:user:sidebar:actions', ['user' => $this->container['userModel']->getById($userId)]);
            $this->seen[] = $hook->render('template:config:sidebar');
        }
        $this->assertSame(15, $this->pages);
        // The owner's pages did render the server, so the sweep looked at real content.
        $this->assertStringContainsString('Living room', implode("\n", $this->seen));
        $this->assertNoTokenAnywhere();
    }

    public function testEveryConnectAndUpdateErrorPath(): void
    {
        $identity = fn () => $this->t->respond(200, (string) file_get_contents(__DIR__.'/fixtures/plex/identity.json'));
        $connect = fn (string $url = 'https://den.example.test:32400', string $name = 'Den') => fn () => $this->call(self::SRV, 'save', [], ['name' => $name, 'url' => $url, 'token' => self::TOKEN]);
        $update = fn (array $post) => fn () => $this->call(self::SRV, 'save', [], $post + ['server_id' => $this->server, 'name' => 'Living room', 'url' => self::BASE, 'token' => self::TOKEN]);

        // A refused URL, a URL that carries the token, a 401, an unreachable server, a hostile upstream body and a
        // transport error whose message holds the token.
        $this->capture($connect('ftp://plex.example.test'));
        $this->capture($connect('https://plex.example.test:32400/?X-Plex-Token='.self::TOKEN));
        $this->capture($connect('https://u:'.self::TOKEN.'@den.example.test:32400'));
        $this->capture($connect(), fn () => $this->t->respond(401, 'token '.self::TOKEN.' rejected'));
        $this->capture($connect(), fn () => $identity() && $this->t->respond(401, ''));
        $this->capture($connect(), fn () => $this->t->fail(PlexException::unreachable()));
        $this->capture($connect(), fn () => $this->t->respond(500, '<html>'.self::TOKEN.'</html>'));
        $this->capture($connect(), fn () => $this->t->fail(new \RuntimeException('curl said '.self::TOKEN)));
        // A bad name, a duplicate, the same token on a second connect.
        $this->capture($connect('https://den.example.test:32400', ''));
        $this->capture($connect(self::BASE));
        // Updates: a changed address without a token, the server changed under the stored token, a bad new token.
        $this->capture($update(['url' => 'https://elsewhere.example.test:32400', 'token' => '']));
        $this->capture($update(['token' => '']), fn () => $this->t->respondJson(['MediaContainer' => ['machineIdentifier' => self::OTHER_MACHINE]]));
        $this->capture($update([]), fn () => $this->t->respond(401, self::TOKEN));
        $this->capture($update(['name' => ''])); // a failed form is re-rendered
        $this->capture($update(['token' => self::TOKEN.' with a space']));
        // Verify: rejected, then the server changed.
        $this->capture(fn () => $this->call(self::SRV, 'verify', ['server_id' => $this->server]), fn () => $this->t->respond(401, self::TOKEN));
        $this->capture(fn () => $this->call(self::SRV, 'verify', ['server_id' => $this->server]), fn () => $this->t->respondJson(['MediaContainer' => ['machineIdentifier' => self::OTHER_MACHINE]]));

        $this->capture($connect(), fn () => $this->t->respond(401, ''));
        $this->assertSame(self::CONNECT_FAILED, $this->failureOf($this->container['response']));
        $this->assertNoTokenAnywhere();
    }

    public function testRateLimitAndServerLimit(): void
    {
        $this->setConfig(Settings::MAX_SERVERS_KEY, '1');
        $this->capture(fn () => $this->call(self::SRV, 'save', [], ['name' => 'Den', 'url' => 'https://den.example.test:32400', 'token' => self::TOKEN]));
        $this->assertSame(self::CONNECT_FAILED, $this->failureOf($this->container['response']));

        $this->setConfig(Settings::MAX_SERVERS_KEY, '3');
        $this->setConfig(Settings::ATTEMPT_LIMIT_KEY, '1');
        $this->capture(fn () => $this->call(self::SRV, 'save', [], ['name' => 'Den', 'url' => 'https://den.example.test:32400', 'token' => self::TOKEN]));
        $this->capture(fn () => $this->call(self::SRV, 'verify', ['server_id' => $this->server]));
        $this->assertSame(self::CONNECT_FAILED, $this->flash('failure'));
        $this->assertNoTokenAnywhere();
    }

    public function testATamperedTokenOnEveryPageThatOpensIt(): void
    {
        $this->container['db']->table('matinee_servers')->eq('id', $this->server)->update(['token_ct' => base64_encode(random_bytes(40))]);
        $this->capture(fn () => $this->call(self::SRV, 'verify', ['server_id' => $this->server]));
        $this->assertSame(self::CONNECT_FAILED, $this->flash('failure'));
        $this->assertSame('reconnect', $this->container['matineeServerModel']->get($this->server)['status']);
        $this->capture(fn () => $this->call(self::SRV, 'save', [], ['server_id' => $this->server, 'name' => 'Den', 'url' => self::BASE, 'token' => '']));
        $this->capture(fn () => $this->get(self::SRV, 'show', ['server_id' => $this->server]));
        $this->capture(fn () => $this->get(self::SRV, 'index'));
        $this->loginAs(self::ADMIN);
        $this->capture(fn () => $this->get(self::ADM, 'show'));
        $this->assertNoTokenAnywhere();
    }

    public function testShareAndRemovePaths(): void
    {
        $share = fn (string $on, int $server = 0) => fn () => $this->call(self::SRV, 'share', ['server_id' => $server ?: $this->server], ['shared' => $on]);
        $this->capture($share('1'));
        $this->capture(fn () => $this->get(self::SRV, 'show', ['server_id' => $this->server]));
        $this->capture($share('0'));
        $this->capture($share(self::TOKEN));
        $this->capture($share('1', $this->server + 9));
        $this->loginAs(self::ADMIN);
        $this->capture($share('1'));
        $this->capture(fn () => $this->get(self::ADM, 'show'));
        $this->loginAs($this->outsider);
        $this->capture($share('1'));
        $this->capture(fn () => $this->call(self::SRV, 'remove', ['server_id' => $this->server]));
        $this->loginAs($this->owner);
        $this->capture(fn () => $this->call(self::SRV, 'remove', ['server_id' => $this->server]));
        $this->assertNoTokenAnywhere();
    }

    public function testAdminErrorPaths(): void
    {
        $this->loginAs(self::ADMIN);
        $this->capture(fn () => $this->call(self::ADM, 'save', [], [
            'matinee_connect_policy' => 'everyone',
            'matinee_private_targets' => "10.0.0.0/8\n".self::TOKEN.'/99',
            'matinee_allowed_hosts' => '',
            'matinee_attempt_limit' => '10',
            'matinee_max_servers' => '3',
        ]));
        $this->assertStringContainsString('line 2', (string) $this->flash('failure'));
        $this->capture(fn () => $this->call(self::ADM, 'toggle', ['server_id' => $this->server], ['enabled' => '0']));
        $this->capture(fn () => $this->call(self::ADM, 'toggle', ['server_id' => $this->server + 9], ['enabled' => '0']));
        $this->capture(fn () => $this->get(self::ADM, 'show'));
        $this->assertNoTokenAnywhere();
    }
}
