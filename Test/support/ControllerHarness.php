<?php
// Shared by ControllerTest and NoTokenLeakTest: boots Matinee over FakeTransport, signs users in, and calls a
// controller action with a recorded response (nothing is sent), a collecting logger and the fake token.
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }

use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Security\Role;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Matinee\Crypto\KeyStore;
use Kanboard\Plugin\Matinee\Http\PlexClient;
use Kanboard\Plugin\Matinee\Http\ResponseSink;
use Kanboard\Plugin\Matinee\Plugin;
use Kanboard\Plugin\Matinee\Test\FakeTransport;

require_once __DIR__.'/FakeTransport.php';

/** Records what would be sent instead of calling header(). */
class MatineeTestResponse extends Response
{
    public function send()
    {
    }

    private function prop(string $name): mixed
    {
        return (new ReflectionProperty(Response::class, $name))->getValue($this);
    }

    /** @return array<string, string> */
    public function headers(): array { return $this->prop('httpHeaders'); }
    public function code(): int { return $this->prop('httpStatusCode'); }
    public function body(): string { return (string) $this->prop('httpBody'); }
}

/** Collects every log line, at any level. */
class MatineeLineLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = $level.' '.$message.($context === [] ? '' : ' '.json_encode($context));
    }
}

trait MatineeControllerHarness
{
    private const TOKEN = 'test-token-NOT-REAL-0000';
    private const BASE = 'https://plex.example.test:32400';
    private const CLIENT_ID = 'c0ffee00-1111-4222-8333-444455556666';
    private const OTHER_MACHINE = 'fedcba9876543210fedcba9876543210fedcba98';
    private const CONNECT_FAILED = 'Could not connect to that server with that token. Check the address includes :32400 and the token is current.';
    private const TOKEN_REQUIRED = 'Enter the token again to change the server address.';

    private FakeTransport $t;
    private MatineeLineLogger $log;
    private string $keyDir;

    /** What the real ResponseSink of the last call() did, through recorder closures (C3e): nothing is sent. */
    private ?int $sinkStatus = null;
    /** @var list<string> header lines, in order */
    private array $sinkHeaders = [];
    /** @var list<string> header names removed, in order */
    private array $sinkRemoved = [];
    private string $sinkBody = '';
    /** @var \Closure(): bool what connection_aborted() reports; a test sets it to simulate a client that went away */
    private \Closure $sinkAborted;

    private function bootMatinee(): void
    {
        $driver = ['sqlite' => 'Sqlite', 'mysql' => 'Mysql', 'postgres' => 'Postgres'][DB_DRIVER];
        require_once __DIR__."/../../Schema/$driver.php";
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\Matinee\Schema\version_1($pdo);
        \Kanboard\Plugin\Matinee\Schema\version_2($pdo);
        \Kanboard\Plugin\Matinee\Schema\version_3($pdo);

        (new Plugin($this->container))->initialize();

        $this->keyDir = sys_get_temp_dir().'/matinee-controller-'.bin2hex(random_bytes(6));
        $this->container['matineeKeyStore'] = new KeyStore($this->keyDir, null);
        $this->t = new FakeTransport();
        $this->container['matineePlexClientFactory'] = $this->container->protect(
            fn (string $baseUrl, #[\SensitiveParameter] string $token, ?string $machineId, string $deviceName): PlexClient
                => new PlexClient($baseUrl, $token, self::CLIENT_ID, $this->t, $machineId, $deviceName)
        );
        // Secure-name discovery goes through the current fake ($this->t may be replaced between requests).
        $this->container['matineeTransport'] = $this->container->factory(fn (): FakeTransport => $this->t);
        $this->log = new MatineeLineLogger();
        unset($this->container['logger']);
        $this->container['logger'] = $this->log;
        // The real ResponseSink, its PHP calls swapped for recorders (and no output buffer is ended under PHPUnit).
        $this->sinkAborted = static fn (): bool => false;
        $this->container['matineeResponseSink'] = $this->container->factory(fn (): ResponseSink => new ResponseSink(
            status: function (int $code): void { $this->sinkStatus = $code; },
            header: function (string $line): void { $this->sinkHeaders[] = $line; },
            removeHeader: function (string $name): void { $this->sinkRemoved[] = $name; },
            output: function (string $chunk): void { $this->sinkBody .= $chunk; },
            aborted: fn (): bool => ($this->sinkAborted)(),
            endBuffers: static function (): void {
            },
            flush: static function (): void {
            },
        ));
    }

    private function cleanKeyDir(): void
    {
        foreach (['secret.key', '.lock'] as $f) {
            @unlink($this->keyDir.'/'.$f);
        }
        @rmdir($this->keyDir);
        session_set('flash', []);
    }

    private function user(string $name, string $role = Role::APP_USER): int
    {
        $id = (int) (new UserModel($this->container))->create(['username' => $name, 'name' => ucfirst($name), 'role' => $role]);
        $this->assertGreaterThan(1, $id);
        return $id;
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['userSession']->setPostAuthenticationAsValidated();
        $this->container['memoryCache']->flush();
    }

    private function queueOk(?string $machine = null): void
    {
        if ($machine === null) {
            $this->t->respond(200, (string) file_get_contents(__DIR__.'/../fixtures/plex/identity.json'));
        } else {
            $this->t->respondJson(['MediaContainer' => ['machineIdentifier' => $machine, 'version' => '1.0']]);
        }
        $this->queueSections();
    }

    private function queueSections(): void
    {
        $this->t->respond(200, (string) file_get_contents(__DIR__.'/../fixtures/plex/sections.json'));
    }

    /** A server owned by $ownerId, connected through ServerModel with the fake token. */
    private function server(int $ownerId, string $url = self::BASE): int
    {
        $this->queueOk();
        return $this->container['matineeServerModel']->connect($ownerId, 'Living room', $url, self::TOKEN);
    }

    private function setConfig(string $key, string $value): void
    {
        $this->container['configModel']->save([$key => $value]);
        $this->container['memoryCache']->flush();
    }

    /**
     * Calls Controller::$action as the signed-in user. $get are the route params, $post the form (sent whatever
     * $method says, so a GET can carry a body); with $csrf a fresh form token is added. $server adds server variables
     * (an `HTTP_RANGE` header, say); $class swaps in a subclass of the controller (a spy).
     */
    private function call(string $controller, string $action, array $get = [], array $post = [], bool $csrf = true, string $method = 'POST', array $server = [], ?string $class = null): MatineeTestResponse
    {
        [$this->sinkStatus, $this->sinkHeaders, $this->sinkRemoved, $this->sinkBody] = [null, [], [], ''];
        if ($csrf) {
            $post['csrf_token'] = $this->container['token']->getCSRFToken();
        }
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => $method] + $server,
            $get + ['controller' => $controller, 'action' => $action, 'plugin' => 'Matinee'], $post, [], []);
        $response = new MatineeTestResponse($this->container);
        unset($this->container['response']);
        $this->container['response'] = $response;
        $class ??= 'Kanboard\\Plugin\\Matinee\\Controller\\'.$controller;
        (new $class($this->container))->$action();
        return $response;
    }

    /** A plain GET, as a link on a Matinee page makes it. */
    private function get(string $controller, string $action, array $get = []): MatineeTestResponse
    {
        return $this->call($controller, $action, $get, [], false, 'GET');
    }

    /** The flash messages now in the session (left in place). */
    private function flashes(): array
    {
        return session_exists('flash') ? (array) session_get('flash') : [];
    }

    private function flash(string $key): ?string
    {
        return $this->flashes()[$key] ?? null;
    }

    /**
     * The failure shown for $response: still in the session after a redirect, or already rendered (and so taken
     * from the session) by a page that re-rendered the form. Null when neither; fails if both show something.
     */
    private function failureOf(MatineeTestResponse $response): ?string
    {
        $shown = preg_match_all('#<div class="alert alert-error">(.*?)</div>#s', $response->body(), $m) ? $m[1] : [];
        $this->assertLessThanOrEqual(1, count($shown) + count($this->flashes()), 'one message at a time');
        return $shown[0] ?? $this->flash('failure');
    }
}
