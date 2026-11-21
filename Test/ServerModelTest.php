<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/support/FakeTransport.php';

use KanboardTests\units\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Matinee\Crypto\CryptoException;
use Kanboard\Plugin\Matinee\Crypto\KeyStore;
use Kanboard\Plugin\Matinee\Crypto\KeyStoreException;
use Kanboard\Plugin\Matinee\Crypto\RefSigner;
use Kanboard\Plugin\Matinee\Crypto\Subkeys;
use Kanboard\Plugin\Matinee\Crypto\TokenBox;
use Kanboard\Plugin\Matinee\Http\CurlTransport;
use Kanboard\Plugin\Matinee\Http\PlexClient;
use Kanboard\Plugin\Matinee\Http\PlexException;
use Kanboard\Plugin\Matinee\Http\UnsafeUrlException;
use Kanboard\Plugin\Matinee\Http\UrlGuard;
use Kanboard\Plugin\Matinee\Model\MatineeConnectException;
use Kanboard\Plugin\Matinee\Model\MatineeForbiddenException;
use Kanboard\Plugin\Matinee\Model\MatineePolicy;
use Kanboard\Plugin\Matinee\Model\ServerModel;
use Kanboard\Plugin\Matinee\Model\Settings;
use Kanboard\Plugin\Matinee\Plugin;
use Kanboard\Plugin\Matinee\Test\FakeTransport;

/** Collects every log line so tests can check the logged stage and that no secret is logged. */
class MatineeTestLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = $level.' '.$message;
    }
}

/**
 * ServerModel (plan Task 7; spec §4, §2, §11 S1, S6, S7, S9, S12). The Plex side is FakeTransport behind the
 * injectable client factory; the key store lives in a temp dir.
 */
class ServerModelTest extends Base
{
    private const TOKEN = 'test-token-NOT-REAL-0000';
    private const TOKEN2 = 'test-token-NOT-REAL-1111';
    private const BASE = 'https://plex.example.test:32400';
    private const MACHINE = '0123456789abcdef0123456789abcdef01234567';
    private const OTHER_MACHINE = 'fedcba9876543210fedcba9876543210fedcba98';
    private const CLIENT_ID = 'c0ffee00-1111-4222-8333-444455556666';
    private const FAILED = 'matinee.connect_failed';
    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    private FakeTransport $t;
    private MatineeTestLogger $log;
    private string $keyDir;

    /** @var list<array{baseUrl: string, machineId: ?string, deviceName: string}> */
    private array $factoryCalls = [];

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

        $this->keyDir = sys_get_temp_dir().'/matinee-servermodel-'.bin2hex(random_bytes(6));
        $this->container['matineeKeyStore'] = new KeyStore($this->keyDir, null);

        $this->t = new FakeTransport();
        $this->container['matineePlexClientFactory'] = $this->container->protect(
            function (string $baseUrl, #[\SensitiveParameter] string $token, ?string $machineId, string $deviceName): PlexClient {
                $this->factoryCalls[] = ['baseUrl' => $baseUrl, 'machineId' => $machineId, 'deviceName' => $deviceName];
                return new PlexClient($baseUrl, $token, self::CLIENT_ID, $this->t, $machineId, $deviceName);
            }
        );

        // Secure-name discovery goes through the same fake (ServerModel reads the transport from the container).
        $this->container['matineeTransport'] = $this->container->factory(fn (): FakeTransport => $this->t);

        $this->log = new MatineeTestLogger();
        $this->container['logger'] = $this->log;
    }

    protected function tearDown(): void
    {
        UrlGuard::setResolverForTesting(null);
        foreach (['secret.key', '.lock'] as $f) {
            @unlink($this->keyDir.'/'.$f);
        }
        @rmdir($this->keyDir);
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function model(): ServerModel
    {
        return $this->container['matineeServerModel'];
    }

    private function db(): \PicoDb\Database
    {
        return $this->container['db'];
    }

    private function user(string $name, string $role = Role::APP_USER): int
    {
        return (int) (new UserModel($this->container))->create(['username' => $name, 'role' => $role]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/plex/'.$name.'.json');
    }

    private function queueOk(?string $machine = null): void
    {
        if ($machine === null) {
            $this->t->respond(200, $this->fixture('identity'));
        } else {
            $this->t->respondJson(['MediaContainer' => ['machineIdentifier' => $machine, 'version' => '1.0']]);
        }
        $this->t->respond(200, $this->fixture('sections'));
    }

    private function connect(int $userId, string $url = self::BASE, string $token = self::TOKEN): int
    {
        $this->queueOk();
        return $this->model()->connect($userId, 'Living room', $url, $token);
    }

    private function setConfig(string $key, string $value): void
    {
        $this->container['configModel']->save([$key => $value]);
    }

    /** Runs $fn, expects the generic connect failure, and returns the exception. */
    private function expectFailure(callable $fn, string $message = self::FAILED): MatineeConnectException
    {
        try {
            $fn();
        } catch (MatineeConnectException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertNull($e->getPrevious());
            return $e;
        }
        $this->fail('expected MatineeConnectException '.$message);
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

    private function serverRows(): int
    {
        return $this->db()->table('matinee_servers')->count();
    }

    private function row(int $id): array
    {
        return $this->db()->table('matinee_servers')->eq('id', $id)->findOne();
    }

    private function lastLog(): string
    {
        return (string) end($this->log->lines);
    }

    /** @return list<string> */
    private function matineeTables(): array
    {
        $pdo = $this->db()->getConnection();
        $sql = match (DB_DRIVER) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'matinee%'",
            'mysql' => "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'matinee%'",
            'postgres' => "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name LIKE 'matinee%'",
        };
        $tables = $pdo->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
        sort($tables);
        return $tables;
    }

    // ── connect ──────────────────────────────────────────────────────────────

    public function testPluginRegistersTheServices(): void
    {
        // The default wiring, before the test overrides: a KeyStore and a factory that builds a CurlTransport client.
        (new Plugin($this->container))->initialize();
        $this->assertInstanceOf(ServerModel::class, $this->container['matineeServerModel']);
        $this->assertInstanceOf(MatineePolicy::class, $this->container['matineePolicy']);
        $this->assertFalse(isset($this->container['matineeSharePolicy']));
        $this->assertFalse(isset($this->container['matineeShareModel']));
        $this->assertInstanceOf(KeyStore::class, $this->container['matineeKeyStore']);
        // The factory only reads the client id: without one it refuses, and writes nothing.
        try {
            $this->container['matineePlexClientFactory'](self::BASE, self::TOKEN, null, 'Matinee');
            $this->fail('expected the factory to refuse a missing client id');
        } catch (\InvalidArgumentException) {
        }
        $this->assertFalse($this->container['configModel']->exists(Settings::CLIENT_ID));
        (new Settings($this->container))->ensureClientId();
        $client = $this->container['matineePlexClientFactory'](self::BASE, self::TOKEN, null, 'Matinee');
        $this->assertInstanceOf(PlexClient::class, $client);
        $transport = (new \ReflectionProperty(PlexClient::class, 'transport'))->getValue($client);
        $this->assertInstanceOf(CurlTransport::class, $transport);
        // Discovery uses the same guarded transport, built afresh (so a changed setting is read) on each use.
        $this->assertInstanceOf(CurlTransport::class, $this->container['matineeTransport']);
        $this->assertNotSame($this->container['matineeTransport'], $this->container['matineeTransport']);
    }

    /** matineeRefSigner is keyed by the refs subkey of the active root, read lazily from matineeKeyStore. */
    public function testTheRefSignerUsesTheRefsSubkeyOfTheActiveRoot(): void
    {
        $env = random_bytes(32);
        $this->container->offsetUnset('matineeKeyStore');
        $this->container['matineeKeyStore'] = new KeyStore($this->keyDir, base64_encode($env));

        $signer = $this->container['matineeRefSigner'];
        $this->assertInstanceOf(RefSigner::class, $signer);
        $ref = $signer->sign(7, 3, '2', 'browse', '/library/sections/2/all', 60);
        $this->assertSame('2', (new RefSigner(Subkeys::refs($env)))->verify($ref, 7)['s']);
        foreach ([$env, Subkeys::token($env)] as $other) {
            try {
                (new RefSigner($other))->verify($ref, 7);
                $this->fail('a ref verified under the wrong key');
            } catch (MatineeForbiddenException) {
            }
        }
    }

    /** The container's policy reads the live users and server rows on every call (S6). */
    public function testThePolicyReadsLiveRows(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $server = $this->model()->get($this->connect($alice));
        $policy = $this->container['matineePolicy'];

        $this->assertTrue($policy->canBrowse($alice, $server));
        $this->assertFalse($policy->canBrowse($bob, $server));
        $this->db()->table('matinee_servers')->eq('id', $server['id'])->update(['is_shared' => 1]);
        $this->assertTrue($policy->canBrowse($bob, $server), 'the stale array still says private');
        $this->db()->table('users')->eq('id', $alice)->update(['is_active' => 0]);
        $this->assertFalse($policy->canBrowse($bob, $server));
        $this->db()->table('users')->eq('id', $alice)->update(['is_active' => 1]);
        $this->db()->table('users')->eq('id', $bob)->update(['role' => Role::APP_ADMIN]);
        $this->assertTrue($policy->isActiveAdmin($bob));
        $this->assertFalse($policy->canToggleShared($bob, $server));
        $this->setConfig(Settings::CONNECT_POLICY, Settings::POLICY_ADMINS);
        $this->assertFalse($policy->canConnectServer($alice));
        $this->assertTrue($policy->canConnectServer($bob));
    }

    public function testConnectStoresTheServerAndTheToken(): void
    {
        $alice = $this->user('alice');
        $this->queueOk();
        $id = $this->model()->connect($alice, '  Living room ', 'HTTPS://Plex.Example.Test:32400/', self::TOKEN);

        $server = $this->model()->get($id);
        $this->assertSame($alice, (int) $server['owner_user_id']);
        $this->assertSame('Living room', $server['name']);
        $this->assertSame(self::BASE, $server['base_url']);
        $this->assertSame(self::MACHINE, $server['machine_id']);
        $this->assertSame('ok', $server['status']);
        $this->assertSame(1, (int) $server['is_enabled']);
        $this->assertGreaterThan(0, (int) $server['created_at']);
        $this->assertGreaterThan(0, (int) $server['last_checked_at']);

        $this->assertSame($this->container['matineeKeyStore']->activeKeyId(), $this->row($id)['key_id']);
        $this->assertSame(self::TOKEN, $this->model()->tokenFor($server));
        $this->assertSame([['baseUrl' => self::BASE, 'machineId' => null, 'deviceName' => 'Matinee (alice)']], $this->factoryCalls);
    }

    public function testConnectCallsIdentityBeforeSections(): void
    {
        $id = $this->connect($this->user('alice'));
        $this->assertSame([self::BASE.'/identity', self::BASE.'/library/sections'], array_column($this->t->requests, 'url'));

        $this->t->requests = [];
        $this->queueOk();
        $this->assertSame('ok', $this->model()->verify($id, (int) $this->model()->get($id)['owner_user_id']));
        $this->assertSame([self::BASE.'/identity', self::BASE.'/library/sections'], array_column($this->t->requests, 'url'));
    }

    public function testNoTableColumnOrLogLineHoldsTheToken(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $this->queueOk();
        $this->model()->verify($id, $alice);
        $this->queueOk();
        $this->model()->update($id, $alice, 'Den', self::BASE, null);
        $this->t->fail(new \RuntimeException('boom '.self::TOKEN));
        $this->expectFailure(fn () => $this->model()->verify($id, $alice));
        $this->t->respond(401, '');
        $this->expectFailure(fn () => $this->model()->connect($alice, 'X', 'https://other.example.test', self::TOKEN));

        $tables = $this->matineeTables();
        $this->assertSame(['matinee_attempts', 'matinee_locks', 'matinee_servers', 'matinee_transcodes'], $tables);
        $scanned = 0;
        foreach (array_merge($tables, ['settings']) as $table) {
            foreach ($this->db()->table($table)->findAll() as $row) {
                foreach ($row as $col => $value) {
                    $this->assertStringNotContainsString(self::TOKEN, (string) $value, "$table.$col");
                    $this->assertStringNotContainsString('NOT-REAL', (string) $value, "$table.$col");
                    $scanned++;
                }
            }
        }
        $this->assertGreaterThan(13, $scanned);
        foreach ($this->log->lines as $line) {
            $this->assertStringNotContainsString(self::TOKEN, $line);
            $this->assertStringNotContainsString('NOT-REAL', $line);
        }
        $this->assertSame(self::TOKEN, $this->model()->tokenFor($this->model()->get($id)));
    }

    public function testBadUrlStoresNothingAndSendsNothing(): void
    {
        $alice = $this->user('alice');
        foreach (['', 'ftp://plex.example.test', 'https://plex.example.test:32400/web', 'https://u:p@plex.example.test',
            'https://plex.example.test/?X-Plex-Token='.self::TOKEN, 'https://0x7f.1', 'https://plex.example.test:080'] as $url) {
            $this->expectFailure(fn () => $this->model()->connect($alice, 'Living room', $url, self::TOKEN));
            $this->assertStringContainsString('at url', $this->lastLog());
        }
        $this->assertSame(0, $this->serverRows());
        $this->assertSame([], $this->t->requests);
    }

    public function testRejectedTokenStoresNothing(): void
    {
        $this->t->respond(401, '');
        $this->expectFailure(fn () => $this->model()->connect($this->user('alice'), 'Living room', self::BASE, self::TOKEN));
        $this->assertSame(0, $this->serverRows());
        $this->assertStringContainsString('at identity', $this->lastLog());
        $this->assertStringContainsString(PlexException::TOKEN_REJECTED, $this->lastLog());
    }

    public function testUnreachableServerStoresNothing(): void
    {
        $this->t->fail(new \RuntimeException('connection refused '.self::TOKEN));
        $this->expectFailure(fn () => $this->model()->connect($this->user('alice'), 'Living room', self::BASE, self::TOKEN));
        $this->assertSame(0, $this->serverRows());
        $this->assertStringContainsString(PlexException::UNREACHABLE, $this->lastLog());
    }

    public function testSectionsFailureStoresNothing(): void
    {
        $this->t->respond(200, $this->fixture('identity'))->respond(401, '');
        $this->expectFailure(fn () => $this->model()->connect($this->user('alice'), 'Living room', self::BASE, self::TOKEN));
        $this->assertSame(0, $this->serverRows());
        $this->assertStringContainsString('at sections', $this->lastLog());
    }

    public function testASealFailureAfterTheInsertRollsBack(): void
    {
        // A malformed env key fails only when the token is sealed, after the row is inserted.
        $this->container['matineeKeyStore'] = new KeyStore($this->keyDir, 'not a key');
        $this->expectFailure(fn () => $this->connect($this->user('alice')));
        $this->assertStringContainsString('at store', $this->lastLog());
        $this->assertSame(0, $this->serverRows());
    }

    public function testAdminsPolicyRefusesAUser(): void
    {
        $this->setConfig(Settings::CONNECT_POLICY, Settings::POLICY_ADMINS);
        $this->expectFailure(fn () => $this->model()->connect($this->user('alice'), 'Living room', self::BASE, self::TOKEN));
        $this->assertStringContainsString('at policy', $this->lastLog());
        $this->assertSame([], $this->t->requests);
        $this->assertSame(0, $this->serverRows());

        $admin = $this->user('root2', Role::APP_ADMIN);
        $this->assertGreaterThan(0, $this->connect($admin));
    }

    public function testInactiveUserCannotConnect(): void
    {
        $alice = $this->user('alice');
        $this->db()->table('users')->eq('id', $alice)->update(['is_active' => 0]);
        $this->expectFailure(fn () => $this->model()->connect($alice, 'Living room', self::BASE, self::TOKEN));
        $this->assertSame([], $this->t->requests);
    }

    public function testEveryFailureStageGivesTheSameMessage(): void
    {
        $n = 0;
        $fresh = function () use (&$n): int {
            return $this->user('user'.(++$n));
        };
        $connect = fn (int $user, string $url = self::BASE) => fn () => $this->model()->connect($user, 'Living room', $url, self::TOKEN);

        $scenarios = [];
        $scenarios['url'] = $connect($fresh(), 'gopher://plex.example.test');
        $scenarios['guard'] = function () use ($fresh) {
            $this->t->fail(new UnsafeUrlException(UnsafeUrlException::PRIVATE_NETWORK, 'plex.example.test'));
            $this->model()->connect($fresh(), 'Living room', self::BASE, self::TOKEN);
        };
        $scenarios['unreachable'] = function () use ($fresh) {
            $this->t->fail(new \RuntimeException('timeout'));
            $this->model()->connect($fresh(), 'Living room', self::BASE, self::TOKEN);
        };
        $scenarios['rejected'] = function () use ($fresh) {
            $this->t->respond(401, '');
            $this->model()->connect($fresh(), 'Living room', self::BASE, self::TOKEN);
        };
        $scenarios['http'] = function () use ($fresh) {
            $this->t->respond(500, 'upstream says '.self::TOKEN);
            $this->model()->connect($fresh(), 'Living room', self::BASE, self::TOKEN);
        };
        $scenarios['badjson'] = function () use ($fresh) {
            $this->t->respond(200, '{"MediaContainer": {}}');
            $this->model()->connect($fresh(), 'Living room', self::BASE, self::TOKEN);
        };
        $scenarios['sections'] = function () use ($fresh) {
            $this->t->respond(200, $this->fixture('identity'))->fail(new UnsafeUrlException(UnsafeUrlException::BLOCKED_ADDRESS));
            $this->model()->connect($fresh(), 'Living room', self::BASE, self::TOKEN);
        };
        $inputUser = $fresh();
        $scenarios['input'] = fn () => $this->model()->connect($inputUser, '', self::BASE, self::TOKEN);
        $dupUser = $fresh();
        $this->connect($dupUser);
        $scenarios['duplicate'] = $connect($dupUser, 'https://plex.example.test:32400/');
        $limitUser = $fresh();
        foreach (['a', 'b', 'c'] as $h) {
            $this->connect($limitUser, "https://$h.example.test");
        }
        $scenarios['server_limit'] = $connect($limitUser, 'https://d.example.test');
        $rateUser = $fresh();
        $this->db()->table('matinee_attempts')->insert(['user_id' => $rateUser, 'created_at' => time()]);
        $scenarios['rate_limit'] = $connect($rateUser);
        $verifyUser = $fresh();
        $verifyServer = $this->connect($verifyUser, 'https://v.example.test');
        $scenarios['verify_guard'] = function () use ($verifyServer, $verifyUser) {
            $this->t->fail(new UnsafeUrlException(UnsafeUrlException::PORT_NOT_ALLOWED, 'v.example.test', 8080));
            $this->model()->verify($verifyServer, $verifyUser);
        };
        $scenarios['verify_plex'] = function () use ($verifyServer, $verifyUser) {
            $this->t->respond(401, '');
            $this->model()->verify($verifyServer, $verifyUser);
        };

        $seen = [];
        foreach ($scenarios as $stage => $fn) {
            if ($stage === 'rate_limit') {
                $this->setConfig(Settings::ATTEMPT_LIMIT_KEY, '1');
            }
            if ($stage === 'verify_guard') {
                $this->setConfig(Settings::ATTEMPT_LIMIT_KEY, '10');
            }
            $e = $this->expectFailure($fn);
            $seen[$stage] = [get_class($e), $e->getMessage(), $e->getCode()];
            $this->assertStringNotContainsString(self::TOKEN, $this->lastLog(), $stage);
            $this->assertStringNotContainsString('https://', $this->lastLog(), $stage);
        }
        $this->assertCount(1, array_unique(array_map('serialize', $seen)), print_r($seen, true));
        $this->assertSame([MatineeConnectException::class, self::FAILED, 0], reset($seen));

        $logged = implode("\n", $this->log->lines);
        foreach (['url', 'identity', 'sections', 'input', 'duplicate', 'server_limit', 'rate_limit'] as $stage) {
            $this->assertStringContainsString("at $stage ", $logged);
        }
        $this->assertStringContainsString(UnsafeUrlException::PRIVATE_NETWORK, $logged);
    }

    // ── rate and server limits ───────────────────────────────────────────────

    public function testEleventhAttemptInTenMinutesIsRefused(): void
    {
        $alice = $this->user('alice');
        for ($i = 0; $i < 10; $i++) {
            $this->t->respond(401, '');
            $this->expectFailure(fn () => $this->model()->connect($alice, 'Living room', self::BASE, self::TOKEN));
        }
        $this->assertCount(10, $this->t->requests);

        $this->queueOk();
        $this->expectFailure(fn () => $this->model()->connect($alice, 'Living room', self::BASE, self::TOKEN));
        $this->assertStringContainsString('at rate_limit', $this->lastLog());
        $this->assertCount(10, $this->t->requests);
        $this->assertSame(10, $this->db()->table('matinee_attempts')->eq('user_id', $alice)->count());

        // Another user is not affected.
        $this->assertGreaterThan(0, $this->model()->connect($this->user('bob'), 'Living room', self::BASE, self::TOKEN));
    }

    public function testVerifyCountsTowardTheAttemptLimit(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        for ($i = 0; $i < 9; $i++) {
            $this->queueOk();
            $this->model()->verify($id, $alice);
        }
        $before = count($this->t->requests);
        $this->expectFailure(fn () => $this->model()->verify($id, $alice));
        $this->assertSame($before, count($this->t->requests));
    }

    public function testOldAttemptsDoNotCountAndArePruned(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        for ($i = 0; $i < 10; $i++) {
            $this->db()->table('matinee_attempts')->insert(['user_id' => $alice, 'created_at' => time() - 601]);
        }
        $this->db()->table('matinee_attempts')->insert(['user_id' => $bob, 'created_at' => time() - 3601]);

        $this->assertGreaterThan(0, $this->connect($alice));

        $this->assertSame(0, $this->db()->table('matinee_attempts')->eq('user_id', $bob)->count());
        $this->assertSame(11, $this->db()->table('matinee_attempts')->eq('user_id', $alice)->count());
    }

    public function testFourthServerIsRefused(): void
    {
        $alice = $this->user('alice');
        foreach (['a', 'b', 'c'] as $h) {
            $this->connect($alice, "https://$h.example.test");
        }
        $sent = count($this->t->requests);
        $this->expectFailure(fn () => $this->model()->connect($alice, 'Living room', 'https://d.example.test', self::TOKEN));
        $this->assertStringContainsString('at server_limit', $this->lastLog());
        $this->assertSame($sent, count($this->t->requests));
        $this->assertSame(3, $this->serverRows());

        // The limit is per owner.
        $this->assertGreaterThan(0, $this->connect($this->user('bob'), 'https://d.example.test'));
    }

    public function testDuplicateServerComparesHostAndEffectivePort(): void
    {
        $alice = $this->user('alice');
        $this->connect($alice, 'http://plex.example.test');
        $this->connect($alice, 'https://plex.example.test');
        $sent = count($this->t->requests);

        foreach (['http://plex.example.test:80', 'HTTP://PLEX.example.test./', 'https://plex.example.test:443'] as $url) {
            $this->expectFailure(fn () => $this->model()->connect($alice, 'Living room', $url, self::TOKEN));
            $this->assertStringContainsString('at duplicate', $this->lastLog());
        }
        $this->assertSame($sent, count($this->t->requests));
        $this->assertSame(2, $this->serverRows());

        // Another owner may add the same server.
        $this->assertGreaterThan(0, $this->connect($this->user('bob'), 'http://plex.example.test:80'));
    }

    // ── update ───────────────────────────────────────────────────────────────

    public function testUpdateWithANewUrlNeedsTheToken(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $sent = count($this->t->requests);

        $this->expectFailure(fn () => $this->model()->update($id, $alice, 'Den', 'https://new.example.test', null), 'matinee.token_required');
        $this->assertSame($sent, count($this->t->requests));
        $this->assertSame(self::BASE, $this->model()->get($id)['base_url']);
        $this->assertSame('Living room', $this->model()->get($id)['name']);
    }

    public function testUpdateWithANewUrlAndTokenReseals(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $before = $this->row($id);

        $this->queueOk();
        $this->model()->update($id, $alice, 'Den', 'https://new.example.test:32400', self::TOKEN2);

        $server = $this->model()->get($id);
        $this->assertSame('Den', $server['name']);
        $this->assertSame('https://new.example.test:32400', $server['base_url']);
        $this->assertNotSame($before['token_nonce'], $this->row($id)['token_nonce']);
        $this->assertSame(self::TOKEN2, $this->model()->tokenFor($server));
        $this->assertSame('https://new.example.test:32400/identity', $this->t->requests[2]['url']);
    }

    public function testUpdateRenamesWithTheStoredToken(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $this->queueOk();
        $this->model()->update($id, $alice, 'Den', 'https://plex.example.test:32400/', null);
        $this->assertSame('Den', $this->model()->get($id)['name']);
        $this->assertSame(self::TOKEN, $this->model()->tokenFor($this->model()->get($id)));
    }

    public function testUpdateOfTheUrlOrTokenFollowsTheConnectPolicy(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $this->setConfig(Settings::CONNECT_POLICY, Settings::POLICY_ADMINS);
        $before = $this->row($id);
        $sent = count($this->t->requests);

        foreach ([['https://new.example.test:32400', self::TOKEN2], [self::BASE, self::TOKEN2], ['https://new.example.test:32400', null]] as [$url, $token]) {
            $this->expectFailure(fn () => $this->model()->update($id, $alice, 'Den', $url, $token));
            $this->assertStringContainsString('at policy', $this->lastLog());
        }
        $this->assertSame($sent, count($this->t->requests));
        $this->assertSame($before, $this->row($id));

        // A rename keeps the stored address and token, so it is still the owner's to make.
        $this->queueOk();
        $this->model()->update($id, $alice, 'Den', self::BASE, null);
        $this->assertSame('Den', $this->model()->get($id)['name']);
    }

    public function testUpdateWithAMachineIdChangeNeedsTheToken(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);

        // Pinned to the stored machine, the probe stops at identity().
        $this->t->respondJson(['MediaContainer' => ['machineIdentifier' => self::OTHER_MACHINE, 'version' => '1.0']]);
        $this->expectFailure(fn () => $this->model()->update($id, $alice, 'Den', self::BASE, null), 'matinee.token_required');
        $this->assertSame(self::MACHINE, $this->model()->get($id)['machine_id']);
        $this->assertSame('Living room', $this->model()->get($id)['name']);

        $this->queueOk(self::OTHER_MACHINE);
        $this->model()->update($id, $alice, 'Den', self::BASE, self::TOKEN2);
        $this->assertSame(self::OTHER_MACHINE, $this->model()->get($id)['machine_id']);
        $this->assertSame(self::TOKEN2, $this->model()->tokenFor($this->model()->get($id)));
    }

    public function testOnlyTheOwnerMayUpdate(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        foreach ([$this->user('bob'), $this->user('root2', Role::APP_ADMIN), 9999] as $actor) {
            $this->expectForbidden(fn () => $this->model()->update($id, $actor, 'Mine', self::BASE, self::TOKEN2));
        }
        $this->assertSame('Living room', $this->model()->get($id)['name']);
    }

    public function testUpdateToAnotherOfMyServersIsADuplicate(): void
    {
        $alice = $this->user('alice');
        $this->connect($alice, 'https://a.example.test');
        $id = $this->connect($alice, 'https://b.example.test');
        $this->expectFailure(fn () => $this->model()->update($id, $alice, 'B', 'https://a.example.test:443', self::TOKEN2));
        $this->assertStringContainsString('at duplicate', $this->lastLog());
    }

    public function testADisabledServerCannotBeUpdatedByItsOwner(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $this->model()->setEnabled($id, false, $this->user('root2', Role::APP_ADMIN));
        $this->t->requests = [];

        $this->queueOk();
        $this->expectFailure(fn () => $this->model()->update($id, $alice, 'Den', self::BASE, self::TOKEN2));
        $this->assertStringContainsString('at disabled', $this->lastLog());
        $this->assertSame([], $this->t->requests);
        $this->assertSame('Living room', $this->model()->get($id)['name']);
    }

    public function testUpdateWithoutATokenPinsTheStoredMachine(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $this->t->requests = [];
        $this->factoryCalls = [];

        $this->queueOk(self::OTHER_MACHINE);
        $this->expectFailure(fn () => $this->model()->update($id, $alice, 'Den', self::BASE, null), 'matinee.token_required');
        // identity() refuses the other machine, so the stored token never reaches its sections endpoint.
        $this->assertSame([self::BASE.'/identity'], array_column($this->t->requests, 'url'));
        $this->assertSame(self::MACHINE, $this->factoryCalls[0]['machineId']);
        $this->assertSame('ok', $this->model()->get($id)['status']);
    }

    public function testUpdateCountsTowardTheAttemptLimit(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $this->setConfig(Settings::ATTEMPT_LIMIT_KEY, '1');
        $this->t->requests = [];

        $this->queueOk();
        $this->expectFailure(fn () => $this->model()->update($id, $alice, 'Den', self::BASE, null));
        $this->assertStringContainsString('at rate_limit', $this->lastLog());
        $this->assertSame([], $this->t->requests);
        $this->assertSame('Living room', $this->model()->get($id)['name']);
    }

    // ── verify ───────────────────────────────────────────────────────────────

    public function testVerifyRecordsTheStatus(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);

        $this->t->fail(new \RuntimeException('timeout'));
        $this->expectFailure(fn () => $this->model()->verify($id, $alice));
        $this->assertSame('unreachable', $this->model()->get($id)['status']);

        $this->t->fail(new UnsafeUrlException(UnsafeUrlException::PRIVATE_NETWORK, 'plex.example.test'));
        $this->expectFailure(fn () => $this->model()->verify($id, $alice));
        $this->assertSame('unreachable', $this->model()->get($id)['status']);

        $this->t->respond(401, '');
        $this->expectFailure(fn () => $this->model()->verify($id, $alice));
        $this->assertSame('reconnect', $this->model()->get($id)['status']);

        // A different server answering at the stored URL (machine id pinned): identity() refuses it.
        $this->t->respondJson(['MediaContainer' => ['machineIdentifier' => self::OTHER_MACHINE]]);
        $this->expectFailure(fn () => $this->model()->verify($id, $alice));
        $this->assertSame('reconnect', $this->model()->get($id)['status']);

        $this->db()->table('matinee_servers')->eq('id', $id)->update(['last_checked_at' => 5]);
        $this->queueOk();
        $this->assertSame('ok', $this->model()->verify($id, $alice));
        $this->assertSame('ok', $this->model()->get($id)['status']);
        $this->assertGreaterThan(5, (int) $this->model()->get($id)['last_checked_at']);
        $this->assertSame(self::MACHINE, end($this->factoryCalls)['machineId']);
    }

    public function testVerifyIsForTheOwnerOrAnAdmin(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $this->expectForbidden(fn () => $this->model()->verify($id, $this->user('bob')));
        $this->expectForbidden(fn () => $this->model()->verify(9999, $alice));

        $admin = $this->user('root2', Role::APP_ADMIN);
        $this->queueOk();
        $this->assertSame('ok', $this->model()->verify($id, $admin));
        $this->assertSame(1, $this->db()->table('matinee_attempts')->eq('user_id', $admin)->count());
    }

    // ── remove and enable ────────────────────────────────────────────────────

    public function testAKeyStoreConfigErrorLeavesTheStatusAlone(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $this->container->offsetUnset('matineeKeyStore');
        $this->container['matineeKeyStore'] = new KeyStore($this->keyDir, 'not-base64!!');
        $sent = count($this->t->requests);

        $this->expectFailure(fn () => $this->model()->verify($id, $alice));
        $this->assertSame('ok', $this->model()->get($id)['status']);
        $this->assertSame($sent, count($this->t->requests));

        $this->expectException(KeyStoreException::class);
        try {
            $this->model()->tokenFor($this->model()->get($id));
        } finally {
            $this->assertSame('ok', $this->model()->get($id)['status']);
        }
    }

    public function testAnInactiveOwnerCannotRemoveOrVerify(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $this->db()->table('users')->eq('id', $alice)->update(['is_active' => 0]);

        // Their session may still be live: the active flag is read on every call.
        $this->expectForbidden(fn () => $this->model()->remove($id, $alice));
        $this->expectForbidden(fn () => $this->model()->verify($id, $alice));
        $this->assertNotNull($this->model()->get($id));
    }

    public function testRemoveIsForTheOwnerOrAnAdmin(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $id = $this->connect($alice);

        $this->expectForbidden(fn () => $this->model()->remove($id, $bob));
        $this->assertNotNull($this->model()->get($id));

        $this->model()->remove($id, $alice);
        $this->assertNull($this->model()->get($id));

        $other = $this->connect($bob);
        $this->model()->remove($other, $this->user('root2', Role::APP_ADMIN));
        $this->assertSame(0, $this->serverRows());
    }

    public function testAdminCanDisableAndGetNeverReturnsTheTokenColumns(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $admin = $this->user('root2', Role::APP_ADMIN);

        $this->expectForbidden(fn () => $this->model()->setEnabled($id, false, $alice));
        $this->assertSame(1, (int) $this->model()->get($id)['is_enabled']);

        $this->model()->setEnabled($id, false, $admin);
        $server = $this->model()->get($id);
        $this->assertSame(0, (int) $server['is_enabled']);

        $public = ['id', 'owner_user_id', 'name', 'base_url', 'machine_id', 'is_enabled', 'is_shared', 'status', 'last_checked_at', 'created_at', 'updated_at'];
        $this->assertSame($public, array_keys($server));
        $this->assertSame([$public], array_map('array_keys', $this->model()->listForOwner($alice)));
        $this->assertSame([$public], array_map('array_keys', $this->model()->listAll()));
        $this->assertSame([], $this->model()->listForOwner($admin));
        $this->assertNull($this->model()->get(9999));

        // A disabled server hands out no token.
        $this->expectForbidden(fn () => $this->model()->tokenFor($server));
        $this->model()->setEnabled($id, true, $admin);
        $this->assertSame(self::TOKEN, $this->model()->tokenFor($this->model()->get($id)));
    }

    // ── sharing ──────────────────────────────────────────────────────────────

    public function testSetSharedIsForTheActiveOwnerOfAnEnabledServer(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $admin = $this->user('root2', Role::APP_ADMIN);
        $id = $this->connect($alice);
        $shared = fn (): int => (int) $this->model()->get($id)['is_shared'];
        $this->assertSame(0, $shared());

        $this->model()->setShared($id, $alice, true);
        $this->assertSame(1, $shared());
        $this->model()->setShared($id, $alice, true); // the wanted state: a repeat changes nothing
        $this->assertSame(1, $shared());

        // Another user, and an admin, may not flip someone's sharing; a missing id reads the same.
        $this->expectForbidden(fn () => $this->model()->setShared($id, $bob, false));
        $this->expectForbidden(fn () => $this->model()->setShared($id, $admin, false));
        $this->expectForbidden(fn () => $this->model()->setShared(9999, $alice, false));
        $this->assertSame(1, $shared());

        $this->model()->setShared($id, $alice, false);
        $this->assertSame(0, $shared());

        // A disabled server, or an owner who is no longer active, cannot be toggled.
        $this->model()->setEnabled($id, false, $admin);
        $this->expectForbidden(fn () => $this->model()->setShared($id, $alice, true));
        $this->model()->setEnabled($id, true, $admin);
        $this->db()->table('users')->eq('id', $alice)->update(['is_active' => 0]);
        $this->expectForbidden(fn () => $this->model()->setShared($id, $alice, true));
        $this->assertSame(0, $shared());
    }

    public function testListBrowsableIsOwnServersAndLiveSharedOnes(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $carol = $this->user('carol');
        $admin = $this->user('root2', Role::APP_ADMIN);
        $named = function (int $owner, string $name, string $url, int $shared, int $enabled = 1): int {
            $id = $this->connect($owner, $url);
            $this->db()->table('matinee_servers')->eq('id', $id)->update(['name' => $name, 'is_shared' => $shared, 'is_enabled' => $enabled]);
            return $id;
        };
        $alicePrivate = $named($alice, 'Den', 'https://a1.example.test:32400', 0);
        $aliceShared = $named($alice, 'Attic', 'https://a2.example.test:32400', 1);
        $bobShared = $named($bob, 'Cellar', 'https://b1.example.test:32400', 1);
        $bobPrivate = $named($bob, 'Bunker', 'https://b2.example.test:32400', 0);
        $named($bob, 'Barn', 'https://b3.example.test:32400', 1, 0); // shared but disabled
        $named($carol, 'Annex', 'https://c1.example.test:32400', 1);
        $this->db()->table('users')->eq('id', $carol)->update(['is_active' => 0]); // her shared server goes dark

        $ids = fn (int $userId): array => array_map(fn (array $s): int => (int) $s['id'], $this->model()->listBrowsable($userId));
        // Ordered by name.
        $this->assertSame([$aliceShared, $bobShared, $alicePrivate], $ids($alice));
        $this->assertSame([$aliceShared, $bobPrivate, $bobShared], $ids($bob));
        // An admin browses only what is shared, like anyone else.
        $this->assertSame([$aliceShared, $bobShared], $ids($admin));
        // An inactive user browses nothing, their own servers included.
        $this->assertSame([], $ids($carol));
        // Never a token column.
        foreach ($this->model()->listBrowsable($alice) as $row) {
            $this->assertSame(ServerModel::COLUMNS, array_keys($row));
        }
    }

    // ── tokenFor ─────────────────────────────────────────────────────────────

    public function testTamperedCiphertextMarksTheServerForReconnect(): void
    {
        $id = $this->connect($this->user('alice'));
        $ct = base64_decode($this->row($id)['token_ct']);
        $ct[0] = chr(ord($ct[0]) ^ 1);
        $this->db()->table('matinee_servers')->eq('id', $id)->update(['token_ct' => base64_encode($ct)]);

        try {
            $this->model()->tokenFor($this->model()->get($id));
            $this->fail('expected CryptoException');
        } catch (CryptoException $e) {
            $this->assertSame('token: cannot open', $e->getMessage());
        }
        $this->assertSame('reconnect', $this->model()->get($id)['status']);
    }

    public function testDbOnlyChangeOfTheEndpointBreaksTokenFor(): void
    {
        $alice = $this->user('alice');
        foreach (['base_url' => 'https://evil.example.test:32400', 'machine_id' => self::OTHER_MACHINE, 'owner_user_id' => $this->user('bob')] as $col => $value) {
            $id = $this->connect($alice, 'https://'.str_replace('_', '-', $col).'.example.test');
            $this->db()->table('matinee_servers')->eq('id', $id)->update([$col => $value]);
            try {
                $this->model()->tokenFor($this->model()->get($id));
                $this->fail("expected CryptoException after changing $col");
            } catch (CryptoException) {
            }
            $this->assertSame('reconnect', $this->model()->get($id)['status']);
        }
    }

    public function testTokenForReadsTheRowNotTheCallersArray(): void
    {
        $id = $this->connect($this->user('alice'));
        $server = $this->model()->get($id);
        $server['base_url'] = 'https://evil.example.test';
        $this->assertSame(self::TOKEN, $this->model()->tokenFor($server));
    }

    public function testARowSealedUnderTheFileKeyResealsToTheEnvKey(): void
    {
        $id = $this->connect($this->user('alice'));
        $fileKid = $this->container['matineeKeyStore']->activeKeyId();
        $this->assertSame($fileKid, $this->row($id)['key_id']);

        $env = base64_encode(random_bytes(32));
        $withEnv = new KeyStore($this->keyDir, $env);
        $this->container->offsetUnset('matineeKeyStore');
        $this->container['matineeKeyStore'] = $withEnv;
        $before = $this->row($id);

        $this->assertSame(self::TOKEN, $this->model()->tokenFor($this->model()->get($id)));
        $after = $this->row($id);
        $this->assertSame($withEnv->activeKeyId(), $after['key_id']);
        $this->assertNotSame($fileKid, $after['key_id']);
        $this->assertNotSame($before['token_ct'], $after['token_ct']);

        // Sealed under the env key now: an env-only store (no key file) opens it.
        $emptyDir = $this->keyDir.'-empty';
        $this->container->offsetUnset('matineeKeyStore');
        $this->container['matineeKeyStore'] = new KeyStore($emptyDir, $env);
        $this->assertSame(self::TOKEN, $this->model()->tokenFor($this->model()->get($id)));
        $this->assertFileDoesNotExist($emptyDir);
    }

    public function testAnInactiveOwnerGetsNoTokenOrClient(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $this->db()->table('users')->eq('id', $alice)->update(['is_active' => 0]);
        $server = $this->model()->get($id);

        $this->expectForbidden(fn () => $this->model()->tokenFor($server));
        $this->expectForbidden(fn () => $this->model()->clientFor($server));
        $this->assertSame('ok', $this->model()->get($id)['status']);

        $admin = $this->user('root2', Role::APP_ADMIN);
        $this->expectFailure(fn () => $this->model()->verify($id, $admin));
    }

    public function testClientForPinsTheMachineAndNamesTheOwner(): void
    {
        $id = $this->connect($this->user('alice'));
        $this->factoryCalls = [];
        $client = $this->model()->clientFor($this->model()->get($id));
        $this->assertInstanceOf(PlexClient::class, $client);
        $this->assertSame([['baseUrl' => self::BASE, 'machineId' => self::MACHINE, 'deviceName' => 'Matinee (alice)']], $this->factoryCalls);
    }

    // ── the client names the viewer (C9, S14) ─────────────────────────────────

    /** The device name on every request the fake recorded since the last reset of $this->t. */
    private function deviceNames(): array
    {
        return array_map(fn (array $r): string => $r['headers']['X-Plex-Device-Name'], $this->t->requests);
    }

    public function testAViewerOtherThanTheOwnerIsWhomPlexSees(): void
    {
        $id = $this->connect($this->user('alice'));
        $bob = $this->user('bob');
        $this->t = new FakeTransport();
        $this->factoryCalls = [];
        $this->t->respond(200, $this->fixture('sections'));
        $this->model()->clientFor($this->model()->get($id), $bob)->sections();
        $this->assertSame(['Matinee (bob)'], $this->deviceNames());
        $this->assertSame([['baseUrl' => self::BASE, 'machineId' => self::MACHINE, 'deviceName' => 'Matinee (bob)']], $this->factoryCalls);
    }

    public function testWithNoViewerPlexSeesTheOwner(): void
    {
        $id = $this->connect($this->user('alice'));
        $this->user('bob');
        $this->t = new FakeTransport();
        $this->t->respond(200, $this->fixture('sections'));
        $this->model()->clientFor($this->model()->get($id))->sections();
        $this->t->respond(200, $this->fixture('sections'));
        $this->model()->clientFor($this->model()->get($id), null)->sections();
        $this->assertSame(['Matinee (alice)', 'Matinee (alice)'], $this->deviceNames());
    }

    public function testAnInactiveOrMissingViewerIsForbiddenWithNoRequest(): void
    {
        $id = $this->connect($this->user('alice'));
        $bob = $this->user('bob');
        $this->db()->table('users')->eq('id', $bob)->update(['is_active' => 0]);
        $this->t = new FakeTransport();
        $this->factoryCalls = [];
        $server = $this->model()->get($id);

        $this->expectForbidden(fn () => $this->model()->clientFor($server, $bob));
        $this->expectForbidden(fn () => $this->model()->clientFor($server, $bob + 1000));
        $this->assertSame([], $this->t->requests);
        $this->assertSame([], $this->factoryCalls);
    }

    public function testAnInactiveViewerIsRefusedBeforeTheTokenIsOpened(): void
    {
        $id = $this->connect($this->user('alice'));
        $bob = $this->user('bob');
        $this->db()->table('users')->eq('id', $bob)->update(['is_active' => 0]);
        $this->db()->table('matinee_servers')->eq('id', $id)->update(['token_ct' => 'garbage']);
        $before = $this->db()->table('matinee_servers')->eq('id', $id)->findOne();
        $this->expectForbidden(fn () => $this->model()->clientFor($this->model()->get($id), $bob));
        $this->assertSame($before, $this->db()->table('matinee_servers')->eq('id', $id)->findOne());
    }

    public function testTheViewersNameIsFiltered(): void
    {
        $id = $this->connect($this->user('alice'));
        $bob = $this->user('bob');
        $this->db()->table('users')->eq('id', $bob)->update(['username' => "b\r\nX-Evil: (o)b<>"]);
        $this->t = new FakeTransport();
        $this->t->respond(200, $this->fixture('sections'));
        $this->model()->clientFor($this->model()->get($id), $bob)->sections();
        $this->assertSame(['Matinee (bX-Evil ob)'], $this->deviceNames());
    }

    // The owner's own probe stays owner-named, even when an admin runs it.
    public function testVerifyStillSendsTheOwnersName(): void
    {
        $id = $this->connect($this->user('alice'));
        $admin = $this->user('root2', Role::APP_ADMIN);
        $this->t = new FakeTransport();
        $this->queueOk();
        $this->assertSame('ok', $this->model()->verify($id, $admin));
        $this->assertNotEmpty($this->t->requests);
        $this->assertSame(array_fill(0, count($this->t->requests), 'Matinee (alice)'), $this->deviceNames());
    }

    public function testAConcurrentResealIsNotOverwritten(): void
    {
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $row = $this->row($id);
        $env = random_bytes(32);
        $withEnv = new KeyStore($this->keyDir, base64_encode($env));

        // Another request re-seals the row under the env key just as this one is about to write its own re-seal.
        $raced = null;
        $this->container->offsetUnset('matineeKeyStore');
        $this->container['matineeKeyStore'] = $this->container->factory(function () use ($withEnv, $env, $id, $row, &$raced) {
            $frames = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function');
            if ($raced === null && in_array('writeToken', $frames, true)) {
                $subkey = Subkeys::token($env);
                $raced = (new TokenBox($subkey))->seal(self::TOKEN, $id, (int) $row['owner_user_id'], $row['base_url'], $row['machine_id']);
                $this->db()->table('matinee_servers')->eq('id', $id)
                    ->update(['token_nonce' => $raced['nonce'], 'token_ct' => $raced['ct'], 'key_id' => KeyStore::keyIdFor($env)]);
            }
            return $withEnv;
        });

        $this->assertSame(self::TOKEN, $this->model()->tokenFor($this->model()->get($id)));
        $this->assertNotNull($raced);
        $after = $this->row($id);
        $this->assertSame($raced['ct'], $after['token_ct']);
        $this->assertSame($raced['nonce'], $after['token_nonce']);
        $this->assertSame(KeyStore::keyIdFor($env), $after['key_id']);
    }

    public function testTheClientIdExistsBeforeAnyClientIsBuilt(): void
    {
        $settings = new Settings($this->container);
        $this->assertFalse($this->container['configModel']->exists(Settings::CLIENT_ID));
        $seen = [];
        $inner = $this->container->raw('matineePlexClientFactory');
        $this->container->offsetUnset('matineePlexClientFactory');
        $this->container['matineePlexClientFactory'] = $this->container->protect(
            function (string $baseUrl, #[\SensitiveParameter] string $token, ?string $machineId, string $deviceName) use ($inner, $settings, &$seen): PlexClient {
                // The PDO is looked up per call: holding it would keep the connection open past tearDown.
                $seen[] = [$settings->clientId(), $this->db()->getConnection()->inTransaction()];
                return $inner($baseUrl, $token, $machineId, $deviceName);
            }
        );

        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $clientId = $settings->clientId();
        $this->assertMatchesRegularExpression(self::UUID, $clientId);
        $this->queueOk();
        $this->model()->verify($id, $alice);
        $this->queueOk();
        $this->model()->update($id, $alice, 'Den', self::BASE, null);
        $this->model()->clientFor($this->model()->get($id));

        // Created by the first connect, before its client was built and outside any transaction; never replaced.
        $this->assertSame(array_fill(0, 4, [$clientId, false]), $seen);
    }

    public function testClientForWritesNothingInsideACallersTransaction(): void
    {
        $id = $this->connect($this->user('alice'));
        $bob = $this->user('bob');
        $db = $this->db();

        $db->startTransaction();
        $db->table('matinee_attempts')->insert(['user_id' => $bob, 'created_at' => time()]);
        $this->model()->clientFor($this->model()->get($id));
        // A config save would have committed (closeTransaction) the caller's transaction.
        $this->assertTrue($db->getConnection()->inTransaction());
        $db->cancelTransaction();
        $this->assertSame(0, $db->table('matinee_attempts')->eq('user_id', $bob)->count());
    }

    // ── secure-name discovery (https://<IPv4>:port → plex.direct) ───────────────

    private const HASH = '0123456789abcdef0123456789abcdef';
    private const IP_BASE = 'https://192.168.1.86:32400';
    private const SECURE = 'https://192-168-1-86.0123456789abcdef0123456789abcdef.plex.direct:32400';
    private const SECURE_HOST = '192-168-1-86.0123456789abcdef0123456789abcdef.plex.direct';

    /** The admin's private target for the LAN server, and DNS for its plex.direct name (to $ips). */
    private function lan(string $targets = '192.168.1.86:32400', array $ips = ['192.168.1.86']): void
    {
        $this->setConfig(UrlGuard::PRIVATE_TARGETS_KEY, $targets);
        $this->container['memoryCache']->flush();
        defined('MATINEE_TESTING') || define('MATINEE_TESTING', true);
        UrlGuard::setResolverForTesting(fn (string $host): array => $host === self::SECURE_HOST ? $ips : []);
    }

    /** @return list<string> every URL a token was sent to */
    private function tokenUrls(): array
    {
        return array_map(static fn (array $r): string => $r['url'], $this->t->requests);
    }

    public function testAnHttpsIpAddressIsStoredAsItsPlexDirectName(): void
    {
        $this->lan();
        $alice = $this->user('alice');
        $this->t->discovers(self::SECURE);

        $id = $this->connect($alice, self::IP_BASE);

        $this->assertSame([self::IP_BASE], $this->t->discoveries, 'one discovery, of the typed address');
        $this->assertSame(self::SECURE, $this->model()->get($id)['base_url']);
        $this->assertSame(self::SECURE, $this->factoryCalls[0]['baseUrl']);
        $this->assertSame([self::SECURE.'/identity', self::SECURE.'/library/sections'], $this->tokenUrls());
        foreach ($this->t->requests as $request) {
            $this->assertStringNotContainsString('192.168.1.86', $request['url'], 'the token never goes to the IP form');
        }
        // The token is sealed to the plex.direct URL and opens with it.
        $this->assertSame(self::TOKEN, $this->model()->tokenFor($this->model()->get($id)));
        foreach ($this->log->lines as $line) {
            $this->assertStringNotContainsString(self::TOKEN, $line);
        }
    }

    public function testAnHttpsIpWithoutAPortKeepsPort443(): void
    {
        $secure443 = 'https://192-168-1-86.'.self::HASH.'.plex.direct:443';
        $this->setConfig(UrlGuard::PRIVATE_TARGETS_KEY, '192.168.1.86:443');
        $this->container['memoryCache']->flush();
        defined('MATINEE_TESTING') || define('MATINEE_TESTING', true);
        UrlGuard::setResolverForTesting(fn (string $host): array => $host === self::SECURE_HOST ? ['192.168.1.86'] : []);
        $this->t->discovers($secure443);

        $id = $this->connect($this->user('alice'), 'https://192.168.1.86');

        $this->assertSame(['https://192.168.1.86'], $this->t->discoveries);
        $this->assertSame($secure443, $this->model()->get($id)['base_url']);
    }

    /** @dataProvider notASecureName */
    public function testNoPlexDirectNameFailsGenericallyWithoutATokenRequest(string|\Throwable|null $answer): void
    {
        $this->lan();
        $alice = $this->user('alice');
        $this->t->discovers($answer);
        $this->queueOk();

        $this->expectFailure(fn () => $this->model()->connect($alice, 'Living room', self::IP_BASE, self::TOKEN));

        $this->assertSame([self::IP_BASE], $this->t->discoveries);
        $this->assertSame([], $this->t->requests, 'no request carried the token');
        $this->assertSame([], $this->factoryCalls, 'no client was built');
        $this->assertSame(0, $this->serverRows());
        $this->assertStringContainsString('connect failed at discover (host 192.168.1.86', $this->lastLog());
    }

    public static function notASecureName(): array
    {
        $h = '0123456789abcdef0123456789abcdef';
        return [
            'no plex.direct certificate'   => [null],
            'a non-Plex host'              => ['https://plex.example.com:32400'],
            'another IP label'             => ['https://10-0-0-5.'.$h.'.plex.direct:32400'],
            'another port'                 => ['https://192-168-1-86.'.$h.'.plex.direct:443'],
            'http'                         => ['http://192-168-1-86.'.$h.'.plex.direct:32400'],
            'an uppercase hash'            => ['https://192-168-1-86.'.strtoupper($h).'.plex.direct:32400'],
            'a short hash'                 => ['https://192-168-1-86.'.substr($h, 1).'.plex.direct:32400'],
            'a suffix after plex.direct'   => ['https://192-168-1-86.'.$h.'.plex.direct.evil.test:32400'],
            'the typed IP back'            => [self::IP_BASE],
            'an unreachable server'        => [PlexException::unreachable()],
            'a transport error'            => [new \RuntimeException('curl said '.self::TOKEN)],
        ];
    }

    public function testAGuardRefusalOfTheTypedAddressMeansNoDiscovery(): void
    {
        $alice = $this->user('alice');
        // No private target at all, then a target that names another port.
        foreach (['' => 'private_network', '192.168.1.86:8443' => 'port_not_allowed'] as $targets => $reason) {
            $this->lan((string) $targets);
            $this->expectFailure(fn () => $this->model()->connect($alice, 'Living room', self::IP_BASE, self::TOKEN));
            $this->assertStringContainsString('connect failed at discover (host 192.168.1.86, '.$reason, $this->lastLog());
        }
        $this->assertSame([], $this->t->discoveries);
        $this->assertSame([], $this->t->requests);
        $this->assertSame(0, $this->serverRows());
    }

    /** @dataProvider rewrittenRefusals */
    public function testTheRewrittenNameIsGuardedAndPinnedToTheTypedIp(string $targets, array $ips, string $allowed, string $reason): void
    {
        $this->lan($targets, $ips);
        $this->setConfig(UrlGuard::ALLOWED_HOSTS_KEY, $allowed);
        $this->container['memoryCache']->flush();
        $alice = $this->user('alice');
        $this->t->discovers(self::SECURE);
        $this->queueOk();

        $this->expectFailure(fn () => $this->model()->connect($alice, 'Living room', self::IP_BASE, self::TOKEN));

        $this->assertSame([self::IP_BASE], $this->t->discoveries);
        $this->assertSame([], $this->t->requests, 'no request carried the token');
        $this->assertSame(0, $this->serverRows());
        $this->assertStringContainsString('connect failed at discover (host 192.168.1.86, '.$reason, $this->lastLog());
    }

    public static function rewrittenRefusals(): array
    {
        return [
            'resolves to another LAN IP'        => ['192.168.1.0/24:32400', ['192.168.1.87'], '', 'pinned elsewhere'],
            'resolves to the IP and another'    => ['192.168.1.0/24:32400', ['192.168.1.87', '192.168.1.86'], '', 'pinned elsewhere'],
            'resolves to an untargeted IP'      => ['192.168.1.86:32400', ['10.9.9.9'], '', 'private_network'],
            'does not resolve'                  => ['192.168.1.86:32400', [], '', 'unresolvable'],
            'not on the host allow-list'        => ['192.168.1.86:32400', ['192.168.1.86'], '192.168.1.86', 'host_not_allowed'],
        ];
    }

    public function testHostnameHttpAndIpv6AddressesSkipDiscovery(): void
    {
        $this->lan();
        $alice = $this->user('alice');
        $this->setConfig(Settings::MAX_SERVERS_KEY, '5');
        foreach ([self::BASE, 'http://192.168.1.86:32400', 'https://[fd00::5]:32400', self::SECURE] as $url) {
            $id = $this->connect($alice, $url);
            $this->assertSame($url, $this->model()->get($id)['base_url']);
        }
        $this->assertSame([], $this->t->discoveries);
    }

    public function testTheIpFormOfAStoredPlexDirectServerIsADuplicate(): void
    {
        $this->lan();
        $alice = $this->user('alice');
        $this->connect($alice, self::SECURE);
        $sent = count($this->t->requests);
        $this->t->discovers(self::SECURE);

        $this->expectFailure(fn () => $this->model()->connect($alice, 'Living room', self::IP_BASE, self::TOKEN));

        $this->assertStringContainsString('at duplicate', $this->lastLog());
        $this->assertSame($sent, count($this->t->requests));
        $this->assertSame(1, $this->serverRows());
    }

    public function testUpdateToAnHttpsIpStoresThePlexDirectName(): void
    {
        $this->lan();
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $sent = count($this->t->requests);
        $this->t->discovers(self::SECURE);
        $this->queueOk();

        $this->model()->update($id, $alice, 'Den', self::IP_BASE, self::TOKEN2);

        $this->assertSame([self::IP_BASE], $this->t->discoveries);
        $server = $this->model()->get($id);
        $this->assertSame(self::SECURE, $server['base_url']);
        $this->assertSame(self::TOKEN2, $this->model()->tokenFor($server));
        $this->assertSame([self::SECURE.'/identity', self::SECURE.'/library/sections'], array_slice($this->tokenUrls(), $sent, 2));
    }

    public function testAFailedDiscoveryOnUpdateChangesNothing(): void
    {
        $this->lan();
        $alice = $this->user('alice');
        $id = $this->connect($alice);
        $before = $this->row($id);
        $sent = count($this->t->requests);
        $this->t->discovers(null);

        $this->expectFailure(fn () => $this->model()->update($id, $alice, 'Den', self::IP_BASE, self::TOKEN2));

        $this->assertSame($sent, count($this->t->requests));
        $this->assertSame($before, $this->row($id));
        $this->assertStringContainsString('update failed at discover (host 192.168.1.86', $this->lastLog());
    }

    public function testAnUnchangedPlexDirectAddressIsNotRediscovered(): void
    {
        $this->lan();
        $alice = $this->user('alice');
        $this->t->discovers(self::SECURE);
        $id = $this->connect($alice, self::IP_BASE);

        $this->queueOk();
        $this->model()->update($id, $alice, 'Den', self::SECURE, null);

        $this->assertCount(1, $this->t->discoveries);
        $this->assertSame('Den', $this->model()->get($id)['name']);
        $this->assertSame(self::SECURE, $this->model()->get($id)['base_url']);
    }

    // ── settings ─────────────────────────────────────────────────────────────

    public function testSettingsDefaultsAndClientId(): void
    {
        $settings = new Settings($this->container);
        $this->assertSame(Settings::POLICY_EVERYONE, $settings->connectPolicy());
        $this->assertSame(10, $settings->attemptLimit());
        $this->assertSame(3, $settings->maxServers());
        $this->assertSame(600, Settings::ATTEMPT_WINDOW);

        $this->assertSame('', $settings->clientId());
        $this->assertFalse($this->container['configModel']->exists(Settings::CLIENT_ID));
        $id = $settings->ensureClientId();
        $this->assertMatchesRegularExpression(self::UUID, $id);
        $this->assertSame($id, (new Settings($this->container))->ensureClientId());
        $this->assertSame($id, $settings->clientId());

        $this->setConfig(Settings::CONNECT_POLICY, 'bogus');
        $this->setConfig(Settings::MAX_SERVERS_KEY, '0');
        $this->setConfig(Settings::ATTEMPT_LIMIT_KEY, 'lots');
        $this->assertSame(Settings::POLICY_EVERYONE, $settings->connectPolicy());
        $this->assertSame(3, $settings->maxServers());
        $this->assertSame(10, $settings->attemptLimit());
        $this->setConfig(Settings::MAX_SERVERS_KEY, '5');
        $this->assertSame(5, $settings->maxServers());
    }
}
