<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/support/ControllerHarness.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Matinee\Controller\MatineePlayController;
use Kanboard\Plugin\Matinee\Crypto\RefSigner;
use Kanboard\Plugin\Matinee\Crypto\Subkeys;
use Kanboard\Plugin\Matinee\Model\MatineeBusyException;
use Kanboard\Plugin\Matinee\Model\MatineeConnectException;
use Kanboard\Plugin\Matinee\Model\MatineeForbiddenException;
use Kanboard\Plugin\Matinee\Model\MatineeRangeException;
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
        $this->releaseContainer();
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
        $this->assertSame(['hlsPing', 'hlsProxy', 'hlsStart', 'hlsStop', 'keepalive', 'media', 'play'], self::actions());
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

    // ── HLS (Task 15; C2d, C2g, C3d, C3f, C4c, C4d, C5d, C6b) ────────────────

    private const MOVIE = '/library/metadata/7';
    private const PLAYLIST = 'Content-Type: application/vnd.apple.mpegurl';
    private const PLAYLIST_TYPE = ['Content-Type' => 'application/vnd.apple.mpegurl'];
    /** C2d: a rewritten URI, in the style of P2's regexes (BrowseControllerTest's art src). */
    private const PROXY_URI = '#^/[^?]*\?controller=MatineePlayController&action=hlsProxy&plugin=Matinee&ref=[A-Za-z0-9_.-]+&u=%2Fvideo%2F%3A%2Ftranscode%2Funiversal%2Fsession%2F[0-9a-f-]{36}%2F[^"\s]+$#';

    private function hlsRef(int $userId): string
    {
        return $this->ref($userId, 'hls', self::MOVIE);
    }

    /** A live hls row of $userId's on the server: [id, session]. */
    private function claim(int $userId, string $kind = 'hls'): array
    {
        ['id' => $id, 'session' => $session] = $this->container['matineeTranscodeModel']->claim($userId, $this->server, $kind, $this->now);
        return [(int) $id, $session];
    }

    private function row(int $id): array
    {
        return $this->container['db']->table(TranscodeModel::TABLE)->eq('id', $id)->findOne();
    }

    private static function child(string $session, string $rest = 'base/index.m3u8'): string
    {
        return '/video/:/transcode/universal/session/'.$session.'/'.$rest;
    }

    private static function master(string $session): string
    {
        return "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-STREAM-INF:PROGRAM-ID=1,BANDWIDTH=8000000,RESOLUTION=1920x1080,CODECS=\"avc1.640028,mp4a.40.2\"\n"
            .'session/'.$session."/base/index.m3u8\n";
    }

    private function hlsStart(mixed $ref, mixed $session, string $method = 'GET'): MatineeTestResponse
    {
        return $this->call(self::PLAY, 'hlsStart', ['ref' => $ref, 'session' => $session], [], false, $method, [], MatineePlayControllerSpy::class);
    }

    private function hlsProxy(mixed $ref, mixed $u, string $method = 'GET'): MatineeTestResponse
    {
        return $this->call(self::PLAY, 'hlsProxy', ['ref' => $ref, 'u' => $u], [], false, $method, [], MatineePlayControllerSpy::class);
    }

    /** POST matinee/hls/ping or stop with the session and $token (none when null) as form fields. */
    private function sessionPost(string $action, mixed $session, ?string $token, string $method = 'POST'): MatineeTestResponse
    {
        $post = ['session' => $session] + ($token === null ? [] : ['csrf_token' => $token]);
        return $this->call(self::PLAY, $action, [], $post, false, $method, [], MatineePlayControllerSpy::class);
    }

    private function reusable(): string
    {
        return $this->container['token']->getReusableCSRFToken();
    }

    /** @return list<string> the URI lines of a playlist body */
    private static function uris(string $body): array
    {
        return array_values(array_filter(explode("\n", $body), static fn (string $l): bool => $l !== '' && $l[0] !== '#'));
    }

    /** A playlist sent through the sink: 200, the one content type, the C3e set, nothing from upstream. */
    private function assertPlaylist(MatineeTestResponse $r): void
    {
        $this->assertSame(200, $this->sinkStatus);
        $this->assertSame(array_merge([self::PLAYLIST], self::FIXED), $this->sinkHeaders);
        $this->assertSame(['Pragma', 'Expires', 'X-Powered-By'], $this->sinkRemoved);
        $this->assertSame('', $r->body());
        $this->assertSame(200, $r->code());
    }

    public function testHlsStartAnswersTheRewrittenMasterWithExactlyThePlaylistHeaders(): void
    {
        [$id, $session] = $this->claim($this->owner);
        $this->now += 60;
        $this->t->respond(200, self::master($session), self::PLAYLIST_TYPE + ['X-Upstream' => 'leak']);
        $r = $this->hlsStart($this->hlsRef($this->owner), $session);

        $this->assertPlaylist($r);
        $uris = self::uris($this->sinkBody);
        $this->assertCount(1, $uris);
        foreach ($uris as $uri) {
            $this->assertMatchesRegularExpression(self::PROXY_URI, $uri);
        }
        $this->assertStringContainsString('&u='.rawurlencode(self::child($session)), $uris[0]);
        foreach (explode("\n", $this->sinkBody) as $line) {
            foreach (['/library/', 'X-Plex-Token', 'plex.example.test', '&amp;', self::TOKEN] as $bad) {
                $this->assertStringNotContainsString($bad, $line);
            }
        }
        $this->assertStringStartsWith("#EXTM3U\n", $this->sinkBody);
        $this->assertSame($this->now, (int) $this->row($id)['last_seen_at'], 'the master heartbeats');
        $this->assertStringStartsWith(self::BASE.'/video/:/transcode/universal/start.m3u8?', $this->t->requests[0]['url']);
        $this->assertSame(1, MatineePlayControllerSpy::$closed);
    }

    /** C3d, C3f: the abort seam runs first; the session closes once the model has authorized, before any Plex call. */
    public function testHlsStartAndProxyCloseTheSessionAfterAuthorization(): void
    {
        $signer = $this->container->raw('matineeRefSigner');
        $this->container->offsetUnset('matineeRefSigner');
        $this->container['matineeRefSigner'] = $this->container->factory(function ($c) use ($signer) {
            MatineePlayControllerSpy::$events[] = 'model';
            return $signer($c);
        });
        [, $session] = $this->claim($this->owner);
        $ref = $this->hlsRef($this->owner);

        $cases = [
            'start' => fn () => $this->hlsStart($ref, $session),
            'proxy' => fn () => $this->hlsProxy($ref, self::child($session, 'base/00000.ts')),
        ];
        foreach ($cases as $label => $request) {
            $this->t = new \Kanboard\Plugin\Matinee\Test\FakeTransport();
            $label === 'start'
                ? $this->t->respond(200, self::master($session), self::PLAYLIST_TYPE)
                : $this->t->streams(200, ['Content-Type' => 'video/mp2t'], ['seg']);
            MatineePlayControllerSpy::$events = [];
            $request();
            $this->assertSame(['ignore', 'model', 'close'], MatineePlayControllerSpy::$events, $label);
        }

        // A refusal never closes the session.
        $this->loginAs($this->outsider);
        $ref = $this->ref($this->outsider, 'hls', self::MOVIE);
        foreach ([fn () => $this->hlsStart($ref, $session), fn () => $this->hlsProxy($ref, self::child($session, 'base/00000.ts'))] as $i => $request) {
            MatineePlayControllerSpy::$events = [];
            $this->assertRefused(403, $request(), (string) $i);
            $this->assertSame(['ignore', 'model'], MatineePlayControllerSpy::$events);
        }
    }

    public function testHlsStartAndProxyServeGetOnly(): void
    {
        [, $session] = $this->claim($this->owner);
        $ref = $this->hlsRef($this->owner);
        foreach (['HEAD', 'POST', 'PUT'] as $method) {
            foreach ([$this->hlsStart($ref, $session, $method), $this->hlsProxy($ref, self::child($session), $method)] as $r) {
                $this->assertRefused(405, $r, $method);
                $this->assertSame('GET', $r->headers()['Allow'] ?? null, $method);
            }
        }
        $this->assertSame([], MatineePlayControllerSpy::$events);
        $this->assertSame([], $this->t->requests);
    }

    public function testHlsStartRefusesWithAnEmpty403(): void
    {
        $this->share();
        [, $mine] = $this->claim($this->owner);
        [$stoppedId, $stopped] = $this->claim($this->owner);
        $this->container['matineeTranscodeModel']->stop($stoppedId);
        [, $freds] = $this->claim($this->friend);
        $cases = [
            'another user\'s session' => [$this->hlsRef($this->owner), $freds],
            'a stopped session'       => [$this->hlsRef($this->owner), $stopped],
            'an unknown session'      => [$this->hlsRef($this->owner), '00000000-0000-4000-8000-000000000009'],
            'a media ref'             => [$this->ref($this->owner, 'media', self::MOVIE), $mine],
            'a forged ref'            => ['x'.$this->hlsRef($this->owner), $mine],
            'an array ref'            => [['x'], $mine],
            'an array session'        => [$this->hlsRef($this->owner), [$mine]],
        ];
        foreach ($cases as $label => [$ref, $session]) {
            $this->assertRefused(403, $this->hlsStart($ref, $session), $label);
        }
        $this->assertSame([], $this->t->requests);
        $this->assertSame(0, MatineePlayControllerSpy::$closed);
    }

    public function testHlsStartUpstreamFailuresAreAnEmpty502(): void
    {
        [, $session] = $this->claim($this->owner);
        $this->t->respond(500, '<html>UPSTREAM-BODY</html>', ['Content-Type' => 'text/html'])
            ->respond(200, self::master('0f1e2d3c-4b5a-4987-8a6b-5c4d3e2f1a0b'), self::PLAYLIST_TYPE)
            ->fail(new \RuntimeException('curl said no'));
        foreach (['500', 'a foreign session', 'an exception'] as $label) {
            $this->assertRefused(502, $this->hlsStart($this->hlsRef($this->owner), $session), $label);
        }
    }

    public function testHlsProxyAnswersAChildPlaylistLikeStart(): void
    {
        [, $session] = $this->claim($this->owner);
        $this->t->respond(200, (string) file_get_contents(__DIR__.'/fixtures/plex/hls-index.m3u8'), self::PLAYLIST_TYPE);
        $r = $this->hlsProxy($this->hlsRef($this->owner), self::child($session).'?x=1');

        $this->assertPlaylist($r);
        $uris = self::uris($this->sinkBody);
        $this->assertCount(3, $uris);
        foreach ($uris as $uri) {
            $this->assertMatchesRegularExpression(self::PROXY_URI, $uri);
            $this->assertStringNotContainsString('&amp;', $uri);
        }
        $this->assertStringEndsWith('&u='.rawurlencode(self::child($session, 'base/00002.ts?x=1')), $uris[2]);
        $this->assertSame([self::BASE.self::child($session).'?x=1'], array_column($this->t->requests, 'url'));
        $this->assertSame(1, MatineePlayControllerSpy::$closed);
    }

    public function testHlsProxyStreamsASegmentWithTheStreamHeaders(): void
    {
        [$id, $session] = $this->claim($this->owner);
        $this->now += 30;
        $this->t->streams(200, ['Content-Type' => 'video/MP2T; charset=x', 'Content-Length' => '7', 'Set-Cookie' => 'a=b', 'Server' => 'Plex'], ['seg', 'ment!']);
        $r = $this->hlsProxy($this->hlsRef($this->owner), self::child($session, 'base/00000.ts'));

        $this->assertSame(200, $this->sinkStatus);
        $this->assertSame(array_merge(['Content-Type: video/mp2t', 'Content-Length: 7'], self::FIXED), $this->sinkHeaders);
        $this->assertSame(['Pragma', 'Expires', 'X-Powered-By'], $this->sinkRemoved);
        $this->assertSame('segment!', $this->sinkBody);
        $this->assertSame('', $r->body());
        $this->assertSame($this->now, (int) $this->row($id)['last_seen_at']);
        $this->assertSame(1, MatineePlayControllerSpy::$closed);
    }

    /** @dataProvider badChildren */
    public function testHlsProxyRefusesAChildFailingS2WithNoPlexRequest(string $rest): void
    {
        [, $session] = $this->claim($this->owner);
        $this->assertRefused(403, $this->hlsProxy($this->hlsRef($this->owner), str_replace('{s}', $session, $rest)));
        $this->assertSame([], $this->t->requests);
        $this->assertSame(0, MatineePlayControllerSpy::$closed);
    }

    public function badChildren(): array
    {
        $base = '/video/:/transcode/universal/session/{s}/';
        return [
            'an item path'    => ['/library/metadata/7'],
            'dot segments'    => [$base.'base/../../library/x.ts'],
            'percent'         => [$base.'base/a%2e.ts'],
            'an at sign'      => [$base.'base/a@b.ts'],
            'a backslash'     => [$base.'base\\x.ts'],
            'a token'         => [$base.'base/x.ts?X-Plex-Token=t'],
            'an X-Plex key'   => [$base.'base/x.ts?X-Plex-Device-Name=me'],
            'an absolute url' => ['https://evil.test'.$base.'base/x.ts'],
            'a scheme'        => ['file://'.$base.'base/x.ts'],
            'a double slash'  => [$base.'base//x.ts'],
            'a bad extension' => [$base.'base/x.mp4'],
            'empty'           => [''],
        ];
    }

    public function testHlsProxyRefusesAnotherUsersSessionAndAnArrayU(): void
    {
        $this->share();
        [, $freds] = $this->claim($this->friend);
        [, $mine] = $this->claim($this->owner);
        $this->assertRefused(403, $this->hlsProxy($this->hlsRef($this->owner), self::child($freds, 'base/00000.ts')));
        $this->assertRefused(403, $this->hlsProxy($this->hlsRef($this->owner), [self::child($mine, 'base/00000.ts')]));
        $this->assertSame([], $this->t->requests);
    }

    public function testHlsProxyUpstreamErrorsAreAnEmpty502(): void
    {
        [, $session] = $this->claim($this->owner);
        $this->t->streams(500, ['Content-Type' => 'text/html'], ['<html>UPSTREAM-BODY</html>']);
        $this->assertRefused(502, $this->hlsProxy($this->hlsRef($this->owner), self::child($session, 'base/00000.ts')));
        $this->t->respond(500, '<html>UPSTREAM-BODY</html>', ['Content-Type' => 'text/html']);
        $this->assertRefused(502, $this->hlsProxy($this->hlsRef($this->owner), self::child($session)));
    }

    /** C3g: once the segment's response opened, a failure appends nothing, and Kanboard's response sends nothing. */
    public function testASegmentThatDiesAfterOpeningSendsNothingMore(): void
    {
        [, $session] = $this->claim($this->owner);
        $this->sinkAborted = static function (): bool {
            throw new \RuntimeException('the client sink broke');
        };
        $this->t->streams(200, ['Content-Type' => 'video/mp2t'], ['ab', 'cdef']);
        $r = $this->hlsProxy($this->hlsRef($this->owner), self::child($session, 'base/00000.ts'));
        $this->assertSame(200, $this->sinkStatus);
        $this->assertSame('ab', $this->sinkBody);
        $this->assertSame(['warning Matinee: stream ended early (server '.$this->server.', plex.unreachable)'], $this->log->lines);
        $this->assertSame('', $r->body());
        $this->assertSame(200, $r->code());
        $this->assertArrayNotHasKey('Cache-Control', $r->headers());
    }

    /**
     * C3g, the guard itself: a model that opens the sink and then refuses (it doesn't today, so a double stands in)
     * gets nothing more from Kanboard's response: no refusal status and no refusal header.
     */
    public function testARefusalAfterTheSinkOpenedSendsNothingMore(): void
    {
        $sink = $this->container->raw('matineeResponseSink');
        $this->container->offsetUnset('matineeResponseSink');
        $shared = null;
        // One sink per call, so the double opens the one the controller holds.
        $this->container['matineeResponseSink'] = $this->container->factory(function ($c) use ($sink, &$shared) {
            return $shared ??= $sink($c);
        });
        $double = new class {
            public ?\Throwable $throw = null;
            public ?\Closure $sink = null;
            private function openThenThrow(): never
            {
                ($this->sink)()->open(200, ['content-type' => 'video/mp2t']);
                throw $this->throw;
            }
            public function hlsMaster(int $userId, string $ref, string $session, \Closure $proxyUrl, \Closure $onAuthorized): string
            {
                $this->openThenThrow();
            }
            public function hlsChild(int $userId, string $ref, string $u, object $sink, \Closure $proxyUrl, \Closure $onAuthorized): ?string
            {
                $this->openThenThrow();
            }
            public function maybeSweep(): void
            {
            }
        };
        $double->sink = fn () => $this->container['matineeResponseSink'];
        $this->container->offsetUnset('matineePlaybackModel');
        $this->container['matineePlaybackModel'] = $double;

        $refusals = [
            'forbidden' => new MatineeForbiddenException(),
            'busy' => new MatineeBusyException(),
            'range' => new MatineeRangeException(50),
            'connect' => new MatineeConnectException(),
        ];
        foreach ($refusals as $label => $e) {
            foreach (['start' => fn () => $this->hlsStart('ref', 'session'), 'proxy' => fn () => $this->hlsProxy('ref', 'u')] as $action => $request) {
                $shared = null;
                $double->throw = $e;
                $r = $request();
                $this->assertSame(200, $this->sinkStatus, "$action $label");
                $this->assertSame(200, $r->code(), "$action $label");
                $this->assertSame('', $r->body(), "$action $label");
                foreach (['Cache-Control', 'Retry-After', 'Content-Range', 'X-Content-Type-Options'] as $header) {
                    $this->assertArrayNotHasKey($header, $r->headers(), "$action $label $header");
                }
            }
        }
    }

    // ── ping and stop (C2g, C5d, C6b) ────────────────────────────────────────

    public function testPingAnswers204AndRefreshesTheRow(): void
    {
        [$id, $session] = $this->claim($this->owner);
        $this->now += 90;
        $r = $this->sessionPost('hlsPing', $session, $this->reusable());
        $this->assertSame(204, $r->code());
        $this->assertSame('', $r->body());
        $this->assertSame('no-store', $r->headers()['Cache-Control'] ?? null);
        $this->assertArrayNotHasKey('Content-Type', $r->headers());
        $this->assertSame($this->now, (int) $this->row($id)['last_seen_at']);
        $this->assertSame(0, MatineePlayControllerSpy::$closed, 'ping writes the session normally');
        $this->assertSame([], $this->t->requests);
    }

    public function testPingRefusesAStoppedUnknownOrUnbrowsableSession(): void
    {
        $this->share();
        [$stoppedId, $stopped] = $this->claim($this->owner);
        $this->container['matineeTranscodeModel']->stop($stoppedId);
        $this->assertRefused(403, $this->sessionPost('hlsPing', $stopped, $this->reusable()), 'stopped');
        $this->assertRefused(403, $this->sessionPost('hlsPing', '00000000-0000-4000-8000-000000000009', $this->reusable()), 'unknown');
        $this->assertRefused(403, $this->sessionPost('hlsPing', ['x'], $this->reusable()), 'array');

        $this->loginAs($this->friend);
        [$id, $freds] = $this->claim($this->friend);
        $this->share(false);
        $this->assertRefused(403, $this->sessionPost('hlsPing', $freds, $this->reusable()), 'unshared');
        $this->assertSame(1, (int) $this->row($id)['stopped'], 'an unbrowsable row is stopped');
    }

    public function testPingAndStopNeedTheReusableToken(): void
    {
        [$id, $session] = $this->claim($this->owner);
        foreach (['hlsPing', 'hlsStop'] as $action) {
            foreach (['none' => null, 'bad' => 'not-a-token', 'single-use' => $this->container['token']->getCSRFToken()] as $label => $token) {
                $this->assertRefused(403, $this->sessionPost($action, $session, $token), "$action $label");
            }
        }
        $this->assertSame([0, $this->now], [(int) $this->row($id)['stopped'], (int) $this->row($id)['last_seen_at']]);
        $this->assertSame([], $this->t->requests);
    }

    /** Addendum (Tasks 8–10): a state change is POST only; any other method is an empty 405 with `Allow: POST`. */
    public function testPingAndStopRefuseEveryOtherMethodWithAnEmpty405(): void
    {
        [$id, $session] = $this->claim($this->owner);
        foreach (['hlsPing', 'hlsStop'] as $action) {
            foreach (['GET', 'HEAD', 'PUT'] as $method) {
                $r = $this->sessionPost($action, $session, $this->reusable(), $method);
                $this->assertRefused(405, $r, "$action $method");
                $this->assertSame('POST', $r->headers()['Allow'] ?? null);
            }
        }
        $this->assertSame(0, (int) $this->row($id)['stopped']);
        $this->assertSame([], $this->t->requests);
    }

    public function testStopWorksTwiceWithTheReusableTokenAndStopsTheRowOnce(): void
    {
        [$id, $session] = $this->claim($this->owner);
        $token = $this->reusable();
        $this->t->respond(200, '');
        foreach ([1, 2] as $n) {
            $r = $this->sessionPost('hlsStop', $session, $token);
            $this->assertSame(204, $r->code(), "call $n");
            $this->assertSame('', $r->body(), "call $n");
            $this->assertSame('no-store', $r->headers()['Cache-Control'] ?? null);
        }
        $this->assertSame(1, (int) $this->row($id)['stopped']);
        $this->assertSame([self::BASE.'/video/:/transcode/universal/stop?session='.$session], array_column($this->t->requests, 'url'));
        $this->assertSame(0, MatineePlayControllerSpy::$closed);
    }

    public function testStopRefusesAnotherUsersOrAnUnknownSession(): void
    {
        $this->share();
        [$id, $freds] = $this->claim($this->friend);
        $this->assertRefused(403, $this->sessionPost('hlsStop', $freds, $this->reusable()));
        $this->assertRefused(403, $this->sessionPost('hlsStop', '00000000-0000-4000-8000-000000000009', $this->reusable()));
        $this->assertSame(0, (int) $this->row($id)['stopped']);
        $this->assertSame([], $this->t->requests);
    }

    public function testAFailedPlexStopIsStillA204(): void
    {
        [$id, $session] = $this->claim($this->owner);
        $this->t->fail(new \RuntimeException('timed out'));
        $this->assertSame(204, $this->sessionPost('hlsStop', $session, $this->reusable())->code());
        $this->assertSame(1, (int) $this->row($id)['stopped']);
    }

    // ── the 1-in-10 sweep (C4d), rolled when a Matinee controller is built ────

    private int $rolls = 0;

    /** Stubs the dice (counting rolls) and leaves one stale mp3 row the sweep would stop without asking Plex. */
    private function stubDice(bool|\Throwable $answer): int
    {
        $this->rolls = 0;
        $this->container->offsetUnset('matineeSweepDice');
        $this->container['matineeSweepDice'] = $this->container->protect(function () use ($answer): bool {
            $this->rolls++;
            if ($answer instanceof \Throwable) {
                throw $answer;
            }
            return $answer;
        });
        [$id] = $this->claim($this->owner, 'mp3');
        $this->container['db']->table(TranscodeModel::TABLE)->eq('id', $id)->update(['last_seen_at' => $this->now - 600]);
        return $id;
    }

    /**
     * A GET to $controller::$action through core's Runner (sessions aside), as production serves it: app.bootstrap,
     * then authentication, 2FA and the ACL, and only then the controller.
     */
    private function serve(string $controller, string $action, string $plugin = 'Matinee'): MatineeTestResponse
    {
        unset($this->container['sessionManager'], $this->container['request'], $this->container['response']);
        $this->container['sessionManager'] = new class($this->container) extends \Kanboard\Core\Session\SessionManager {
            public function open() {}
        };
        $this->container['request'] = new \Kanboard\Core\Http\Request($this->container, ['REQUEST_METHOD' => 'GET'],
            ['controller' => $controller, 'action' => $action, 'plugin' => $plugin], [], [], []);
        $this->container['response'] = $response = new MatineeTestResponse($this->container);
        $this->container['router']->dispatch();
        (new \Kanboard\Core\Controller\Runner($this->container))->execute();
        return $response;
    }

    public function testASignedInMatineePageRollsTheDiceAndSweeps(): void
    {
        $id = $this->stubDice(true);
        $r = $this->serve('MatineeBrowseController', 'index');
        $this->assertSame(200, $r->code());
        $this->assertSame(1, $this->rolls);
        $this->assertSame(1, (int) $this->row($id)['stopped']);
        $this->assertSame([], $this->t->requests);
    }

    /** m1, m7: the frequent actions never roll, in any case PHP's method dispatch accepts. */
    public function testTheSkippedActionsNeverRollInAnyCase(): void
    {
        $id = $this->stubDice(true);
        foreach (['media', 'Media', 'MEDIA', 'hlsProxy', 'HLSPROXY', 'hlsproxy', 'hlsPing', 'HLSPING', 'keepalive',
            'KeepAlive', 'hlsStart', 'HLSSTART', 'hlsstart'] as $action) {
            $this->serve(self::PLAY, $action);
            $this->assertSame(0, $this->rolls, $action);
        }
        $this->assertSame(0, (int) $this->row($id)['stopped']);

        // Stop, the player page and the browse pages are not skipped.
        foreach (['hlsStop', 'HLSSTOP'] as $action) {
            $this->serve(self::PLAY, $action);
        }
        $this->serve('MatineeBrowseController', 'INDEX');
        $this->assertSame(3, $this->rolls);
    }

    /** m2: whatever stops a request before a Matinee controller is built stops the sweep too. */
    public function testNoRollBeforeEveryMiddlewareHasPassed(): void
    {
        $id = $this->stubDice(true);

        // Anonymous: the authentication middleware redirects to the login page.
        session_set('user', []);
        $this->serve('MatineeBrowseController', 'index');
        $this->assertSame(0, $this->rolls, 'anonymous');

        // A session still waiting for its 2FA code: the post-authentication middleware redirects to the code page.
        $pending = (int) $this->container['userModel']->create(['username' => 'tina', 'role' => Role::APP_USER, 'twofactor_activated' => 1, 'twofactor_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->container['userSession']->initialize($this->container['userModel']->getById($pending));
        $this->assertTrue($this->container['userSession']->isLogged());
        $r = $this->serve('MatineeBrowseController', 'index');
        $this->assertStringContainsString('TwoFactorController', $r->headers()['Location'] ?? '', '2FA');
        $this->assertSame(0, $this->rolls, '2FA pending');

        // A disabled user's leftover session: the session check ends it.
        $this->loginAs($this->friend);
        $this->container['userModel']->disable($this->friend);
        $r = $this->serve('MatineeBrowseController', 'index');
        $this->assertStringContainsString('AuthController', $r->headers()['Location'] ?? '', 'disabled');
        $this->assertSame(0, $this->rolls, 'disabled user');

        // An unknown controller under plugin=Matinee: core finds no class to build.
        $this->loginAs($this->owner);
        try {
            $this->serve('MatineeNopeController', 'index');
            $this->fail('expected Controller not found');
        } catch (\RuntimeException $e) {
            $this->assertSame('Controller not found', $e->getMessage());
        }
        $this->assertSame(0, $this->rolls, 'unknown controller');
        $this->assertSame(0, (int) $this->row($id)['stopped']);

        // The same signed-in owner on a real page does roll.
        $this->serve('MatineeBrowseController', 'index');
        $this->assertSame(1, $this->rolls);

        // Built directly, past core: a controller that refuses for want of sodium or curl, or one with nobody signed
        // in, still never rolls.
        try {
            new class($this->container) extends \Kanboard\Plugin\Matinee\Controller\MatineeBrowseController {
                protected static function supported(): bool { return false; }
            };
            $this->fail('expected forbidden');
        } catch (AccessForbiddenException) {
        }
        session_set('user', []);
        new \Kanboard\Plugin\Matinee\Controller\MatineeBrowseController($this->container);
        $this->assertSame(1, $this->rolls, 'unsupported or anonymous');
    }

    /** m4: a failed sweep never breaks the page, and leaves one log line naming only the exception's class. */
    public function testAThrowingSweepNeverBreaksThePageAndLogsOneLine(): void
    {
        $this->assertSame('Matinee: sweep failed (%s)', \Kanboard\Plugin\Matinee\Controller\MatineeBaseController::SWEEP_FAILED_LOG);
        // A namespaced class, so its short name and its full name differ.
        $this->stubDice(new AccessForbiddenException('dice broke '.self::TOKEN));
        $before = count($this->log->lines);
        $r = $this->serve('MatineeBrowseController', 'index');
        $this->assertSame(1, $this->rolls);
        $this->assertSame(200, $r->code());
        $this->assertStringContainsString('Living room', $r->body());
        $this->assertSame(['warning Matinee: sweep failed (AccessForbiddenException)'], array_slice($this->log->lines, $before));
    }

    // ── the player page (Task 18; C2c, C5b, C6) ──────────────────────────────

    private const AVI_MOVIE = '/library/metadata/2229';
    /** The live sections: movies in 1 and music in 3, where the item fixtures live. */
    private const LIVE = '{"MediaContainer":{"Directory":[{"key":"1","type":"movie","title":"Movies"},{"key":"3","type":"artist","title":"Music"}]}}';
    private const BUSY = 'This server is busy right now. Try again in a minute.';
    private const CANNOT_PLAY = 'This item could not be played.';
    private const UNSUPPORTED = 'Your browser cannot play this video.';

    /** P2's regex style: a query-form Matinee URL in an attribute, capturing the ref. */
    private static function attrUrl(string $attr, string $action, string $extra = ''): string
    {
        return '#'.$attr.'="[^"?]*\?controller=MatineePlayController&amp;action='.$action.'&amp;plugin=Matinee'.$extra.'"#';
    }

    private function play(mixed $ref): MatineeTestResponse
    {
        return $this->call(self::PLAY, 'play', ['ref' => $ref], [], false, 'GET', [], MatineePlayControllerSpy::class);
    }

    /** Queues the live sections, then the item fixture (or the given item JSON). */
    private function queueItem(string $fixtureOrJson): void
    {
        $json = str_starts_with($fixtureOrJson, '{') ? $fixtureOrJson : (string) file_get_contents(__DIR__.'/fixtures/plex/'.$fixtureOrJson.'.json');
        $this->t->respond(200, self::LIVE)->respond(200, $json);
    }

    /** An hls ref to the avi movie, in section 1 where its fixture lives. */
    private function movieRef(int $userId): string
    {
        return $this->container['matineeRefSigner']->sign($userId, $this->server, '1', 'hls', self::AVI_MOVIE, 28800);
    }

    private function moviePage(): MatineeTestResponse
    {
        $this->queueItem('item-movie-avi');
        return $this->play($this->movieRef($this->owner));
    }

    public function testTheAudioPageSourcesTheDirectPartOrTheTranscode(): void
    {
        foreach (['item-track-mp3' => '/library/parts/5001/1700000000/file.mp3', 'item-track-h263' => '/library/metadata/3003'] as $fixture => $r) {
            $this->queueItem($fixture);
            $ref = $this->ref($this->owner, 'media', '/library/metadata/'.($fixture === 'item-track-mp3' ? '3001' : '3003'));
            $resp = $this->play($ref);
            $body = $resp->body();
            $this->assertSame(200, $resp->code(), $fixture);
            $this->assertSame(1, preg_match_all('#<audio [^>]*>#', $body, $tags), $fixture);
            $tag = $tags[0][0];
            $this->assertMatchesRegularExpression('#^<audio controls preload="metadata" class="matinee-player" data-matinee-kind="audio"\s#', $tag);
            $this->assertSame(1, preg_match(self::attrUrl('src', 'media', '&amp;ref=([A-Za-z0-9_.-]+)'), $tag, $m), $fixture);
            $this->assertSame(['sv' => $this->server, 's' => '3', 'k' => 'media', 'r' => $r], $this->container['matineeRefSigner']->verify($m[1], $this->owner, 'media'));
            $this->assertMatchesRegularExpression(self::attrUrl('data-matinee-keepalive', 'keepalive'), $tag);
            $this->assertSame(1, preg_match('#data-matinee-csrf="([^"]+)"#', $tag, $c));
            $this->assertTrue($this->container['token']->validateReusableCSRFToken($c[1]));
            $this->assertStringContainsString('data-matinee-error="'.self::CANNOT_PLAY.'"', $tag);
            $this->assertStringNotContainsString('<video', $body);
            $this->assertStringContainsString('<p class="matinee-player-message" hidden></p>', $body);
            $this->assertStringContainsString('Fake Artist · Fake Album', $body);
            $this->assertStringNotContainsString('/library/', $body);
        }
    }

    public function testTheMoviePageCarriesItsClaimedSessionAndTheReusableToken(): void
    {
        $r = $this->moviePage();
        $body = $r->body();
        $this->assertSame(200, $r->code());
        $rows = $this->container['db']->table(TranscodeModel::TABLE)->findAll();
        $this->assertCount(1, $rows);
        $this->assertSame(1, preg_match_all('#<video [^>]*>#', $body, $tags));
        $tag = $tags[0][0];
        $this->assertMatchesRegularExpression('#^<video controls playsinline class="matinee-player" data-matinee-kind="hls"\s#', $tag);
        $this->assertStringContainsString('data-matinee-session="'.$rows[0]['session'].'"', $tag);
        $this->assertSame(1, preg_match('#data-matinee-csrf="([^"]+)"#', $tag, $c));
        $this->assertTrue($this->container['token']->validateReusableCSRFToken($c[1]));
        $this->assertSame(1, preg_match(self::attrUrl('data-matinee-src', 'hlsStart', '&amp;ref=([A-Za-z0-9_.-]+)&amp;session=([0-9a-f-]{36})'), $tag, $m));
        $this->assertSame(['sv' => $this->server, 's' => '1', 'k' => 'hls', 'r' => self::AVI_MOVIE], $this->container['matineeRefSigner']->verify($m[1], $this->owner, 'hls'));
        $this->assertSame($rows[0]['session'], $m[2]);
        $this->assertMatchesRegularExpression(self::attrUrl('data-matinee-ping', 'hlsPing'), $tag);
        $this->assertMatchesRegularExpression(self::attrUrl('data-matinee-stop', 'hlsStop'), $tag);
        $this->assertMatchesRegularExpression(self::attrUrl('data-matinee-keepalive', 'keepalive'), $tag);
        $this->assertStringContainsString('data-matinee-error="'.self::CANNOT_PLAY.'"', $tag);
        $this->assertStringContainsString('data-matinee-unsupported="'.self::UNSUPPORTED.'"', $tag);
        $this->assertStringContainsString('<h2 class="matinee-player-title">Fake Old Movie</h2>', $body);
        $this->assertMatchesRegularExpression('#<a href="[^"]*action=server[^"]*server_id='.$this->server.'[^"]*"[^>]*>Living room</a>#', $body);
        $this->assertMatchesRegularExpression('#<title>\s*Media\s*</title>#', $body);
        foreach (['/library/', 'plex.example.test', 'X-Plex', '/data/movies', self::TOKEN] as $raw) {
            $this->assertStringNotContainsString($raw, $body);
        }
    }

    public function testAHostileTitleAndSubtitleAreEscaped(): void
    {
        $item = json_decode((string) file_get_contents(__DIR__.'/fixtures/plex/item-track-mp3.json'), true);
        $item['MediaContainer']['Metadata'][0]['title'] = '<img src=x onerror=alert(1)>"';
        $item['MediaContainer']['Metadata'][0]['parentTitle'] = '<b>bold</b>';
        $this->queueItem((string) json_encode($item));
        $body = $this->play($this->ref($this->owner, 'media', '/library/metadata/3001'))->body();
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;&quot;', $body);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $body);
        $this->assertStringNotContainsString('<img src=x', $body);
        $this->assertStringNotContainsString('<b>bold', $body);
    }

    public function testThePlayerPagesCarryNoInlineCode(): void
    {
        $pages = [$this->moviePage()->body()];
        $this->queueItem('item-track-mp3');
        $pages[] = $this->play($this->ref($this->owner, 'media', '/library/metadata/3001'))->body();
        foreach ($pages as $i => $body) {
            $this->assertStringContainsString('class="matinee-player"', $body);
            preg_match_all('#<script\b[^>]*>#i', $body, $scripts);
            foreach ($scripts[0] as $script) {
                $this->assertStringContainsString(' src="', $script, "page $i");
            }
            // Core's layout draws avatars with style=; Matinee's own markup (the crumbs to the message line) has none.
            $this->assertSame(1, preg_match('#<h2 class="matinee-crumbs">.*<p class="matinee-player-message" hidden></p>#s', $body, $own), "page $i");
            $this->assertDoesNotMatchRegularExpression('#\son[a-z]+\s*=#i', $own[0], "page $i");
            $this->assertDoesNotMatchRegularExpression('#\sstyle\s*=|<script|<style#i', $own[0], "page $i");
        }
        foreach (glob(__DIR__.'/../Template/play/*.php') as $file) {
            $this->assertDoesNotMatchRegularExpression('#<script|<style|\sstyle=|\son[a-z]+=#i', (string) file_get_contents($file), basename($file));
        }
    }

    public function testAnOutsiderGetsCore403AndBadRefsToo(): void
    {
        $this->loginAs($this->outsider);
        foreach ([$this->movieRef($this->outsider), $this->movieRef($this->owner), 'nope', ['a']] as $i => $ref) {
            try {
                $this->play($ref);
                $this->fail("expected forbidden #$i");
            } catch (AccessForbiddenException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->t->requests);
        $this->assertSame([], $this->container['db']->table(TranscodeModel::TABLE)->findAll());
    }

    public function testAMovieAtTheCapIsA503BusyPage(): void
    {
        foreach (['00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002'] as $session) {
            $this->container['db']->table(TranscodeModel::TABLE)->insert([
                'server_id' => $this->server, 'user_id' => $this->owner, 'session' => $session, 'kind' => 'hls',
                'created_at' => $this->now, 'last_seen_at' => $this->now, 'stopped' => 0,
            ]);
        }
        $r = $this->moviePage();
        $this->assertSame(503, $r->code());
        $this->assertStringContainsString('<p class="alert alert-error">'.self::BUSY.'</p>', $r->body());
        $this->assertStringNotContainsString('<video', $r->body());
        $this->assertCount(2, $this->container['db']->table(TranscodeModel::TABLE)->findAll());
    }

    public function testAPlexFailureIsTheFixedFailurePageWithA502(): void
    {
        $this->t->respond(500, '<html>'.self::TOKEN.'</html>');
        $r = $this->play($this->movieRef($this->owner));
        $this->assertSame(502, $r->code());
        $this->assertStringContainsString('Could not reach that server.', $r->body());
        $this->assertStringNotContainsString(self::TOKEN, $r->body());
    }
}
