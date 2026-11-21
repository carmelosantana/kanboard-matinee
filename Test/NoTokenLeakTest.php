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
    private const BRW = 'MatineeBrowseController';
    private const PLAY = 'MatineePlayController';
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
        $this->releaseContainer();
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

    /** Secure-name discovery: an https IP address rewritten, refused, unreachable, or failing with the token in the message. */
    public function testEverySecureNameDiscoveryPath(): void
    {
        $secure = 'https://192-168-1-86.0123456789abcdef0123456789abcdef.plex.direct:32400';
        $this->setConfig('matinee_private_targets', '192.168.1.86:32400');
        defined('MATINEE_TESTING') || define('MATINEE_TESTING', true);
        \Kanboard\Plugin\Matinee\Http\UrlGuard::setResolverForTesting(fn (string $host): array => str_ends_with($host, '.plex.direct') ? ['192.168.1.86'] : []);
        $connect = fn (string $url = 'https://192.168.1.86:32400') => fn () => $this->call(self::SRV, 'save', [], ['name' => 'Den', 'url' => $url, 'token' => self::TOKEN]);
        try {
            $this->capture($connect(), fn () => $this->t->discovers(null));
            $this->capture($connect(), fn () => $this->t->discovers('https://plex.example.com:32400'));
            $this->capture($connect(), fn () => $this->t->discovers(new \RuntimeException('handshake with '.self::TOKEN)));
            $this->capture($connect(), fn () => $this->t->discovers($secure) && $this->t->respond(401, self::TOKEN));
            $this->capture($connect('https://192.168.1.86:8443'));
            $this->capture($connect(), fn () => $this->t->discovers($secure) && $this->queueOk());
            $this->assertSame('Server connected.', $this->flash('success'));
            $this->capture(fn () => $this->get(self::SRV, 'index'));
            $this->assertStringContainsString('plex.direct', implode("\n", $this->seen));
        } finally {
            \Kanboard\Plugin\Matinee\Http\UrlGuard::setResolverForTesting(null);
        }
        $this->assertNoTokenAnywhere();
    }

    /**
     * P2 Task 4: every browse page and the art endpoint, as the owner, a shared user and an outsider, on success and on
     * every failure (an upstream body, a status or an exception message holding the token), headers and bodies.
     */
    public function testEveryBrowsePageAndArtResponse(): void
    {
        $sections = fn () => $this->t->respond(200, (string) file_get_contents(__DIR__.'/fixtures/plex/sections.json'));
        $listing = fn () => $sections() && $this->t->respond(200, (string) file_get_contents(__DIR__.'/fixtures/plex/children-section.json'));
        $jpeg = fn () => $this->t->respond(200, "\xFF\xD8fake-jpeg", ['content-type' => 'image/jpeg']);
        $signer = $this->container['matineeRefSigner'];
        $this->container['matineeServerModel']->setShared($this->server, $this->owner, true);
        $friend = $this->user('fern');

        foreach ([$this->owner, $friend, $this->outsider] as $userId) {
            $this->loginAs($userId);
            $browse = $signer->sign($userId, $this->server, '1', 'browse', '/library/sections/1/all', 3600);
            $art = $signer->sign($userId, $this->server, '1', 'art', '/library/metadata/101/thumb/1700000000', 3600);
            $this->capture(fn () => $this->get(self::BRW, 'index'));
            $this->capture(fn () => $this->get(self::BRW, 'server', ['server_id' => $this->server]), $sections);
            $this->capture(fn () => $this->get(self::BRW, 'listing', ['ref' => $browse]), $listing);
            $this->capture(fn () => $this->get(self::BRW, 'listing', ['ref' => $browse, 'start' => '60']), $listing);
            $this->capture(fn () => $this->get(self::BRW, 'art', ['ref' => $art, 'size' => '144']), $jpeg);
            // Failures: the token in an exception message, an upstream error body and a non-image body.
            $this->capture(fn () => $this->get(self::BRW, 'server', ['server_id' => $this->server]), fn () => $this->t->fail(new \RuntimeException('curl said '.self::TOKEN)));
            $this->capture(fn () => $this->get(self::BRW, 'listing', ['ref' => $browse]), fn () => $sections() && $this->t->respond(500, '<html>'.self::TOKEN.'</html>'));
            $this->capture(fn () => $this->get(self::BRW, 'art', ['ref' => $art, 'size' => '512']), fn () => $this->t->respond(401, self::TOKEN));
            $this->capture(fn () => $this->get(self::BRW, 'art', ['ref' => $art, 'size' => '144']), fn () => $this->t->respond(200, self::TOKEN, ['content-type' => 'text/html']));
            $this->capture(fn () => $this->get(self::BRW, 'art', ['ref' => self::TOKEN, 'size' => '144']));
            $this->seen[] = $this->container['helper']->hook->render('template:dashboard:sidebar', ['user' => $this->container['userModel']->getById($userId)]);
        }
        $this->container['matineeServerModel']->setShared($this->server, $this->owner, false);
        $this->loginAs($friend);
        $this->capture(fn () => $this->get(self::BRW, 'server', ['server_id' => $this->server]));

        $this->assertSame(31, $this->pages);
        $all = implode("\n", $this->seen);
        // The sweep looked at real content: sections, a listing and the art bytes.
        foreach (['TV Shows', 'Fake Show', "\xFF\xD8fake-jpeg"] as $shown) {
            $this->assertStringContainsString($shown, $all);
        }
        $this->assertNoTokenAnywhere();
    }

    /** P3 Task 10: every media response, streamed or refused: status, headers, body and log lines. */
    public function testEveryMediaResponse(): void
    {
        $signer = $this->container['matineeRefSigner'];
        $this->container->offsetUnset('matineeShutdown');
        $this->container['matineeShutdown'] = $this->container->protect(static function (callable $fn): void {
        });
        $this->container['matineeServerModel']->setShared($this->server, $this->owner, true);
        $friend = $this->user('fern');
        $part = '/library/parts/5001/1700000000/file.mp3';
        $item = '/library/metadata/3003';
        $media = function (string $ref, ?string $range = null): MatineeTestResponse {
            $r = $this->call(self::PLAY, 'media', ['ref' => $ref], [], false, 'GET', $range === null ? [] : ['HTTP_RANGE' => $range]);
            $this->seen[] = 'sink '.$this->sinkStatus.' '.json_encode($this->sinkHeaders).' '.json_encode($this->sinkRemoved).' '.$this->sinkBody;
            $this->seen[] = 'status '.$r->code();
            return $r;
        };

        foreach ([$this->owner, $friend, $this->outsider] as $userId) {
            $this->loginAs($userId);
            $direct = $signer->sign($userId, $this->server, '3', 'media', $part, 28800);
            $transcode = $signer->sign($userId, $this->server, '3', 'media', $item, 28800);
            $this->capture(fn () => $media($direct, 'bytes=0-'), fn () => $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-9/10'], ['0123456789']));
            $this->capture(fn () => $media($transcode), fn () => $this->t->streams(200, ['Content-Type' => 'audio/mpeg'], ['ID3-bytes']));
            // Failures: an upstream 416, an error body carrying the token, a 401, a transport message, a bad type, an
            // overlong window, and a bad ref.
            $this->capture(fn () => $media($direct, 'bytes=100-'), fn () => $this->t->streams(416, ['Content-Range' => 'bytes */50'], [self::TOKEN]));
            $this->capture(fn () => $media($direct), fn () => $this->t->streams(500, ['Content-Type' => 'text/html'], ['<html>'.self::TOKEN.'</html>']));
            $this->capture(fn () => $media($transcode), fn () => $this->t->streams(401, ['Content-Type' => 'text/plain'], [self::TOKEN]));
            $this->capture(fn () => $media($direct), fn () => $this->t->fail(new \RuntimeException('curl said '.self::TOKEN)));
            $this->capture(fn () => $media($direct), fn () => $this->t->streams(206, ['Content-Type' => 'text/html', 'Content-Range' => 'bytes 0-9/10'], [self::TOKEN]));
            $this->capture(fn () => $media($direct, 'bytes=0-9'), fn () => $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-9/10'], ['0123456789', self::TOKEN]));
            $this->capture(fn () => $media(self::TOKEN));
        }

        $this->assertSame(27, $this->pages);
        $all = implode("\n", $this->seen);
        // The sweep looked at real streams and real refusals.
        foreach (['0123456789', 'ID3-bytes', 'sink 206', 'status 403', 'status 416', 'status 502', 'stream ended early'] as $shown) {
            $this->assertStringContainsString($shown, $all."\n".implode("\n", $this->log->lines));
        }
        $this->assertNoTokenAnywhere();
    }

    /** P3 Task 15: every hls start, proxy, ping and stop response, served or refused: status, headers, body and log. */
    public function testEveryHlsResponse(): void
    {
        $signer = $this->container['matineeRefSigner'];
        $transcodes = $this->container['matineeTranscodeModel'];
        $this->container->offsetUnset('matineeShutdown');
        $this->container['matineeShutdown'] = $this->container->protect(static function (callable $fn): void {
        });
        $this->container['matineeServerModel']->setShared($this->server, $this->owner, true);
        $friend = $this->user('fern');
        $playlist = ['Content-Type' => 'application/vnd.apple.mpegurl'];
        $child = fn (string $session, string $rest): string => '/video/:/transcode/universal/session/'.$session.'/'.$rest;
        $master = fn (string $session, string $uri = ''): string => "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=8000000\n".($uri ?: 'session/'.$session.'/base/index.m3u8')."\n";
        $keep = function (MatineeTestResponse $r): MatineeTestResponse {
            $this->seen[] = 'sink '.$this->sinkStatus.' '.json_encode($this->sinkHeaders).' '.json_encode($this->sinkRemoved).' '.$this->sinkBody;
            $this->seen[] = 'status '.$r->code();
            return $r;
        };
        $start = fn (string $ref, string $session) => fn () => $keep($this->call(self::PLAY, 'hlsStart', ['ref' => $ref, 'session' => $session], [], false, 'GET'));
        $proxy = fn (string $ref, string $u) => fn () => $keep($this->call(self::PLAY, 'hlsProxy', ['ref' => $ref, 'u' => $u], [], false, 'GET'));
        $post = fn (string $action, string $session, ?string $token = null) => fn () => $keep($this->call(self::PLAY, $action, [], ['session' => $session, 'csrf_token' => $token ?? $this->container['token']->getReusableCSRFToken()], false));

        foreach ([$this->owner, $friend, $this->outsider] as $userId) {
            $this->loginAs($userId);
            ['session' => $session] = $transcodes->claim($userId, $this->server, 'hls', time());
            $ref = $signer->sign($userId, $this->server, '1', 'hls', '/library/metadata/7', 28800);
            $this->capture($start($ref, $session), fn () => $this->t->respond(200, $master($session), $playlist));
            $this->capture($proxy($ref, $child($session, 'base/index.m3u8')), fn () => $this->t->respond(200, (string) file_get_contents(__DIR__.'/fixtures/plex/hls-index.m3u8'), $playlist));
            $this->capture($proxy($ref, $child($session, 'base/00000.ts')), fn () => $this->t->streams(200, ['Content-Type' => 'video/mp2t'], ['ts-bytes']));
            $this->capture($post('hlsPing', $session));
            // Failures: an error body, a playlist naming the token, a transport message, a 401, a segment that runs
            // past its cap, a u carrying the token, a ref that is the token, and a bad CSRF token.
            $this->capture($start($ref, $session), fn () => $this->t->respond(500, '<html>'.self::TOKEN.'</html>', ['Content-Type' => 'text/html']));
            $this->capture($start($ref, $session), fn () => $this->t->respond(200, $master($session, 'session/'.$session.'/base/index.m3u8?X-Plex-Token='.self::TOKEN), $playlist));
            $this->capture($start($ref, $session), fn () => $this->t->fail(new \RuntimeException('curl said '.self::TOKEN)));
            $this->capture($proxy($ref, $child($session, 'base/index.m3u8')), fn () => $this->t->respond(401, self::TOKEN));
            $this->capture($proxy($ref, $child($session, 'base/00001.ts')), fn () => $this->t->streams(500, ['Content-Type' => 'text/html'], [self::TOKEN]));
            $this->sinkAborted = static function (): bool {
                throw new \RuntimeException('client sink said '.self::TOKEN);
            };
            $this->capture($proxy($ref, $child($session, 'base/00002.ts')), fn () => $this->t->streams(200, ['Content-Type' => 'video/mp2t'], ['abc', self::TOKEN]));
            $this->sinkAborted = static fn (): bool => false;
            $this->capture($proxy($ref, $child($session, 'base/x.ts?X-Plex-Token='.self::TOKEN)));
            $this->capture($proxy(self::TOKEN, $child($session, 'base/00000.ts')));
            $this->capture($post('hlsPing', $session, self::TOKEN));
            $this->capture($post('hlsStop', self::TOKEN));
            $this->capture($post('hlsStop', $session), fn () => $this->t->fail(new \RuntimeException('stop said '.self::TOKEN)));
            $this->capture($post('hlsStop', $session));
            $this->capture($post('hlsPing', $session));
        }

        $this->assertSame(51, $this->pages);
        $all = implode("\n", $this->seen)."\n".implode("\n", $this->log->lines);
        // The sweep looked at real playlists, segments and refusals.
        foreach (['action=hlsProxy', 'ts-bytes', 'sink 200', 'status 204', 'status 403', 'status 502', 'transcode stop failed', 'stream ended early'] as $shown) {
            $this->assertStringContainsString($shown, $all);
        }
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
