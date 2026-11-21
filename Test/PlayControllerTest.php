<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/support/ControllerHarness.php';

use KanboardTests\units\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Matinee\Controller\MatineePlayController;
use Kanboard\Plugin\Matinee\Crypto\RefSigner;
use Kanboard\Plugin\Matinee\Crypto\Subkeys;
use Kanboard\Plugin\Matinee\Model\TranscodeModel;

/** Counts closeSession() instead of ending a session (C3d), and records the seams in the order they run. */
class MatineePlayControllerSpy extends MatineePlayController
{
    public static int $closed = 0;

    /** @var list<string> */
    public static array $events = [];

    protected function closeSession(): void
    {
        self::$closed++;
        self::$events[] = 'close';
    }

    protected function ignoreUserAbort(): void
    {
        self::$events[] = 'ignore';
    }
}

/**
 * The audio endpoint, `matinee/media` (plan P3 Task 10; spec §7, §11 S9, S11; C1b, C3d, C3e, C3f, C4c). Plex is
 * FakeTransport; the real ResponseSink is wired to the harness's recorders, so nothing is sent.
 */
class PlayControllerTest extends Base
{
    use MatineeControllerHarness;

    private const PLAY = 'MatineePlayController';
    private const MP3_PART = '/library/parts/5001/1700000000/file.mp3';
    private const ITEM = '/library/metadata/3003';
    /** C3e, in the order ResponseSink sends them. */
    private const FIXED = [
        'X-Content-Type-Options: nosniff',
        "Content-Security-Policy: default-src 'none'; sandbox",
        'Content-Disposition: inline',
        'Cross-Origin-Resource-Policy: same-origin',
        'Referrer-Policy: same-origin',
        'Cache-Control: private, no-store',
    ];

    private int $owner;
    private int $friend;
    private int $outsider;
    private int $server;
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMatinee();
        $this->now = 1_800_000_000;
        $this->container->offsetUnset('matineeRefSigner');
        $this->container['matineeRefSigner'] = fn ($c): RefSigner => new RefSigner(Subkeys::refs($c['matineeKeyStore']->rootKey()), fn (): int => $this->now);
        $this->container->offsetUnset('matineeClock');
        $this->container['matineeClock'] = $this->container->protect(fn (): int => $this->now);
        $this->container->offsetUnset('matineeShutdown');
        $this->container['matineeShutdown'] = $this->container->protect(static function (callable $fn): void {
        });
        $this->owner = $this->user('olive');
        $this->friend = $this->user('fred');
        $this->outsider = $this->user('oscar');
        $this->server = $this->server($this->owner);
        $this->t = new \Kanboard\Plugin\Matinee\Test\FakeTransport();
        $this->loginAs($this->owner);
        MatineePlayControllerSpy::$closed = 0;
        MatineePlayControllerSpy::$events = [];
    }

    protected function tearDown(): void
    {
        $this->cleanKeyDir();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function ref(int $userId, string $kind = 'media', string $r = self::MP3_PART, int $ttl = 28800): string
    {
        return $this->container['matineeRefSigner']->sign($userId, $this->server, '3', $kind, $r, $ttl);
    }

    /** GET matinee/media as the signed-in user, through the spy, with an optional Range header. */
    private function media(mixed $ref, ?string $range = null): MatineeTestResponse
    {
        return $this->call(self::PLAY, 'media', ['ref' => $ref], [], false, 'GET', $range === null ? [] : ['HTTP_RANGE' => $range], MatineePlayControllerSpy::class);
    }

    /** matinee/media with another method, as the spy. */
    private function mediaWith(string $method, mixed $ref): MatineeTestResponse
    {
        return $this->call(self::PLAY, 'media', ['ref' => $ref], [], false, $method, [], MatineePlayControllerSpy::class);
    }

    /** POST matinee/keepalive as the spy, with $token as the posted csrf_token (none when null). */
    private function keepalive(?string $token, string $method = 'POST'): MatineeTestResponse
    {
        return $this->call(self::PLAY, 'keepalive', [], $token === null ? [] : ['csrf_token' => $token], false, $method, [], MatineePlayControllerSpy::class);
    }

    private function queuePart(array $chunks = ['0123456789'], string $range = 'bytes 0-9/10'): void
    {
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => $range], $chunks);
    }

    private function share(bool $on = true): void
    {
        $this->container['matineeServerModel']->setShared($this->server, $this->owner, $on);
    }

    /** An empty refusal sent through Kanboard's response; the sink never opened. */
    private function assertRefused(int $status, MatineeTestResponse $r, string $label = ''): void
    {
        $this->assertSame($status, $r->code(), $label);
        $this->assertSame('', $r->body(), $label);
        $this->assertSame('nosniff', $r->headers()['X-Content-Type-Options'] ?? null, $label);
        $this->assertSame('no-store', $r->headers()['Cache-Control'] ?? null, $label);
        $this->assertSame('same-origin', $r->headers()['Referrer-Policy'] ?? null, $label);
        $this->assertArrayNotHasKey('Content-Type', $r->headers(), $label);
        $this->assertNull($this->sinkStatus, $label);
        $this->assertSame([], $this->sinkHeaders, $label);
        $this->assertSame('', $this->sinkBody, $label);
    }

    /** @return list<string> the public actions the controller declares */
    private static function actions(): array
    {
        $out = [];
        foreach ((new \ReflectionClass(MatineePlayController::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if (! $m->isStatic() && ! $m->isConstructor() && str_starts_with($m->getDeclaringClass()->getName(), 'Kanboard\\Plugin\\Matinee\\')) {
                $out[] = $m->getName();
            }
        }
        sort($out);
        return $out;
    }

    // ── access ───────────────────────────────────────────────────────────────

    public function testEveryActionIsForSignedInUsersAndAnonymousUsersAreRefused(): void
    {
        $map = $this->container['applicationAccessMap'];
        $auth = $this->container['applicationAuthorization'];
        $this->assertSame(['keepalive', 'media'], self::actions());
        $rows = array_values(array_filter(\Kanboard\Plugin\Matinee\Plugin::ACL, fn (array $row): bool => $row[0] === self::PLAY));
        $this->assertCount(1, $rows);
        $this->assertSame([self::actions(), Role::APP_USER], [$rows[0][1], $rows[0][2]]);
        foreach (self::actions() as $action) {
            $this->assertSame($map->getRoleHierarchy(Role::APP_USER), $map->getRoles(self::PLAY, $action), $action);
            $this->assertTrue($auth->isAllowed(self::PLAY, $action, Role::APP_USER), $action);
            $this->assertFalse($auth->isAllowed(self::PLAY, $action, Role::APP_PUBLIC), $action);
        }
    }

    public function testTheSessionSeamIsSessionAbort(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../Controller/MatineePlayController.php');
        $this->assertMatchesRegularExpression('/function closeSession\(\): void\s*\{[^}]*session_abort\(\);/s', $src);
        $this->assertStringNotContainsString('session_write_close', $src);
    }

    public function testTheAbortSeamIsIgnoreUserAbortTrue(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../Controller/MatineePlayController.php');
        $this->assertMatchesRegularExpression('/function ignoreUserAbort\(\): void\s*\{\s*ignore_user_abort\(true\);\s*\}/s', $src);
        $this->assertSame(1, substr_count($src, 'ignore_user_abort('), 'only the seam calls it');
    }

    /** m1, C3f: the abort seam runs before the model does anything (its first step resolves the ref signer). */
    public function testIgnoreUserAbortRunsBeforeTheModel(): void
    {
        $signer = $this->container->raw('matineeRefSigner');
        $this->container->offsetUnset('matineeRefSigner');
        $this->container['matineeRefSigner'] = $this->container->factory(function ($c) use ($signer) {
            MatineePlayControllerSpy::$events[] = 'model';
            return $signer($c);
        });
        $ref = $this->ref($this->owner);
        MatineePlayControllerSpy::$events = [];
        $this->queuePart();
        $this->media($ref);
        $this->assertSame(['ignore', 'model', 'close'], MatineePlayControllerSpy::$events);

        $this->loginAs($this->outsider);
        $ref = $this->ref($this->outsider);
        MatineePlayControllerSpy::$events = [];
        $this->media($ref);
        $this->assertSame(['ignore', 'model'], MatineePlayControllerSpy::$events, 'a refusal too');
    }

    // ── methods (m2) ─────────────────────────────────────────────────────────

    /** media serves GET only: anything else is an empty 405 before the model runs (no claim, no Plex, no close). */
    public function testMediaRefusesEveryOtherMethodWithAnEmpty405(): void
    {
        foreach (['HEAD', 'POST', 'PUT', 'OPTIONS'] as $method) {
            foreach ([$this->ref($this->owner), $this->ref($this->owner, 'media', self::ITEM)] as $ref) {
                MatineePlayControllerSpy::$events = [];
                $r = $this->mediaWith($method, $ref);
                $this->assertRefused(405, $r, $method);
                $this->assertSame('GET', $r->headers()['Allow'] ?? null, $method);
                $this->assertSame([], MatineePlayControllerSpy::$events, $method);
            }
        }
        $this->assertSame([], $this->t->requests);
        $this->assertSame(0, MatineePlayControllerSpy::$closed);
        $this->assertSame(0, $this->container['db']->table(TranscodeModel::TABLE)->count());
    }

    // ── keepalive (M1) ───────────────────────────────────────────────────────

    public function testKeepaliveWithTheReusableTokenIsAnEmpty204AndWritesTheSession(): void
    {
        $token = $this->container['token']->getReusableCSRFToken();
        foreach ([1, 2] as $n) {
            $r = $this->keepalive($token);
            $this->assertSame(204, $r->code(), "call $n");
            $this->assertSame('', $r->body(), "call $n");
            $this->assertArrayNotHasKey('Content-Type', $r->headers());
            $this->assertSame('no-store', $r->headers()['Cache-Control'] ?? null);
        }
        $this->assertSame(0, MatineePlayControllerSpy::$closed, 'the session is written normally');
        $this->assertSame([], MatineePlayControllerSpy::$events);
        $this->assertSame([], $this->t->requests);
    }

    public function testKeepaliveWithoutTheReusableTokenIsAnEmpty403(): void
    {
        $cases = [
            'none'       => null,
            'bad'        => 'not-a-token',
            'single-use' => $this->container['token']->getCSRFToken(),
        ];
        foreach ($cases as $label => $token) {
            $this->assertRefused(403, $this->keepalive($token), $label);
        }
        $token = $this->container['token']->getReusableCSRFToken();
        session_set('user', []);
        $this->assertRefused(403, $this->keepalive($token), 'signed out');
    }

    public function testKeepaliveRefusesEveryOtherMethodWithAnEmpty405(): void
    {
        $token = $this->container['token']->getReusableCSRFToken();
        foreach (['GET', 'HEAD', 'PUT'] as $method) {
            $r = $this->keepalive($token, $method);
            $this->assertRefused(405, $r, $method);
            $this->assertSame('POST', $r->headers()['Allow'] ?? null, $method);
        }
    }

    // ── success ──────────────────────────────────────────────────────────────

    public function testTheOwnerAndASharedUserGetA206WithExactlyTheStreamHeaders(): void
    {
        $this->share();
        foreach ([$this->owner, $this->friend] as $userId) {
            $this->loginAs($userId);
            $this->t = new \Kanboard\Plugin\Matinee\Test\FakeTransport();
            $this->queuePart(['01234', '56789']);
            MatineePlayControllerSpy::$closed = 0;
            $r = $this->media($this->ref($userId), 'bytes=0-');

            $this->assertSame(206, $this->sinkStatus);
            $this->assertSame(array_merge([
                'Content-Type: audio/mpeg',
                'Content-Range: bytes 0-9/10',
                'Content-Length: 10',
                'Accept-Ranges: bytes',
            ], self::FIXED), $this->sinkHeaders);
            $this->assertSame(['Pragma', 'Expires', 'X-Powered-By'], $this->sinkRemoved);
            $this->assertSame('0123456789', $this->sinkBody);
            $this->assertContains('Content-Length: '.strlen($this->sinkBody), $this->sinkHeaders);
            // Kanboard's response sent nothing of its own.
            $this->assertSame(200, $r->code());
            $this->assertSame('', $r->body());
            $this->assertSame(1, MatineePlayControllerSpy::$closed);
        }
    }

    /** H1: whatever Plex sends, the body is never longer than the Content-Length the browser was given. */
    public function testTheBodyIsCutAtTheContentLength(): void
    {
        $this->queuePart([str_repeat('a', 1000)], 'bytes 0-499/5000');
        $r = $this->media($this->ref($this->owner), 'bytes=0-999');
        $this->assertContains('Content-Length: 500', $this->sinkHeaders);
        $this->assertSame(500, strlen($this->sinkBody));
        $this->assertSame('', $r->body());
        $this->assertStringContainsString('stream ended early', implode("\n", $this->log->lines));
    }

    public function testTheRangeHeaderReachesPlex(): void
    {
        $this->queuePart(['x'], 'bytes 5-14/100');
        $this->media($this->ref($this->owner), 'bytes=5-14');
        $this->assertSame('bytes=5-14', $this->t->requests[0]['headers']['Range']);
        $this->assertContains('Content-Range: bytes 5-14/100', $this->sinkHeaders);
    }

    public function testNoRangeAsksForTheFirstWindow(): void
    {
        $this->queuePart();
        $this->media($this->ref($this->owner));
        $this->assertSame('bytes=0-8388607', $this->t->requests[0]['headers']['Range']);
    }

    // ── refusals ─────────────────────────────────────────────────────────────

    public function testAnOutsiderOnAPrivateServerGetsAnEmpty403(): void
    {
        $this->loginAs($this->outsider);
        $this->assertRefused(403, $this->media($this->ref($this->outsider)));
        $this->assertSame([], $this->t->requests);
        $this->assertSame(0, MatineePlayControllerSpy::$closed);
    }

    public function testBadRefsGetAnEmpty403(): void
    {
        $good = $this->ref($this->owner);
        $forged = $good;
        $forged[2] = $forged[2] === 'A' ? 'B' : 'A';
        $cases = [
            'forged'  => $forged,
            'hls'     => $this->ref($this->owner, 'hls', '/library/metadata/101'),
            'browse'  => $this->ref($this->owner, 'browse', '/library/sections/3/all', 3600),
            'empty'   => '',
            'array'   => ['x'],
            'expired' => $good,
        ];
        foreach ($cases as $label => $ref) {
            if ($label === 'expired') {
                $this->now += 28800;
            }
            $this->assertRefused(403, $this->media($ref), $label);
        }
        $this->assertSame([], $this->t->requests);
        $this->assertSame(0, MatineePlayControllerSpy::$closed);
    }

    // ── error statuses ───────────────────────────────────────────────────────

    public function testAnUpstream416IsAnEmpty416WithItsTotal(): void
    {
        $this->t->streams(416, ['Content-Range' => 'bytes */50', 'Content-Type' => 'text/html'], ['UPSTREAM-BODY']);
        $r = $this->media($this->ref($this->owner), 'bytes=100-');
        $this->assertRefused(416, $r);
        $this->assertSame('bytes */50', $r->headers()['Content-Range'] ?? null);
    }

    public function testAnUpstreamErrorIsAnEmpty502(): void
    {
        $this->t->streams(500, ['Content-Type' => 'text/html'], ['<html>UPSTREAM-BODY</html>']);
        $this->assertRefused(502, $this->media($this->ref($this->owner)));
    }

    public function testATranscodeAtTheCapIsA503WithRetryAfter(): void
    {
        foreach (['00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002'] as $session) {
            $this->container['db']->table(TranscodeModel::TABLE)->insert([
                'server_id' => $this->server, 'user_id' => $this->owner, 'session' => $session, 'kind' => 'hls',
                'created_at' => $this->now, 'last_seen_at' => $this->now, 'stopped' => 0,
            ]);
        }
        $r = $this->media($this->ref($this->owner, 'media', self::ITEM));
        $this->assertRefused(503, $r);
        $this->assertSame('30', $r->headers()['Retry-After'] ?? null);
        $this->assertSame([], $this->t->requests);
    }

    // ── transcodes ───────────────────────────────────────────────────────────

    public function testATranscodedTrackStreamsWithNoLength(): void
    {
        $this->t->streams(200, ['Content-Type' => 'audio/mpeg', 'Content-Length' => '99'], ['ID3', 'more']);
        $this->media($this->ref($this->owner, 'media', self::ITEM), 'bytes=0-');
        $this->assertSame(200, $this->sinkStatus);
        $this->assertSame(array_merge(['Content-Type: audio/mpeg', 'Accept-Ranges: none'], self::FIXED), $this->sinkHeaders);
        $this->assertSame('ID3more', $this->sinkBody);
        $this->assertSame(1, (int) $this->container['db']->table(TranscodeModel::TABLE)->findOneColumn('stopped'));
    }

    public function testAClientAbortEndsTheTranscodeAndStopsItsRow(): void
    {
        $this->sinkAborted = fn (): bool => $this->sinkBody !== '';
        $this->t->streams(200, ['Content-Type' => 'audio/mpeg'], ['first', 'second', 'third']);
        $r = $this->media($this->ref($this->owner, 'media', self::ITEM));
        $this->assertSame('first', $this->sinkBody);
        $this->assertSame(1, (int) $this->container['db']->table(TranscodeModel::TABLE)->findOneColumn('stopped'));
        $this->assertSame('', $r->body());
        $this->assertSame(200, $r->code());
    }
}
