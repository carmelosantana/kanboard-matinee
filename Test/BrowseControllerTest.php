<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/support/ControllerHarness.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Matinee\Controller\MatineeBrowseController;
use Kanboard\Plugin\Matinee\Crypto\KeyStore;
use Kanboard\Plugin\Matinee\Crypto\RefSigner;
use Kanboard\Plugin\Matinee\Crypto\Subkeys;
use Kanboard\Plugin\Matinee\Http\PlexException;
use Kanboard\Plugin\Matinee\Http\PlexPaths;

/**
 * The browse pages and the art endpoint (plan P2 Task 4; rulings B1, B4, B5; spec §11 S9, S11). Plex is FakeTransport
 * behind the client factory; refs are signed on a test clock; responses are recorded, never sent.
 */
class BrowseControllerTest extends Base
{
    use MatineeControllerHarness;

    private const BRW = 'MatineeBrowseController';
    private const JPEG = "\xFF\xD8\xFF\xE0fake-jpeg-bytes";
    private const THUMB = '/library/metadata/101/thumb/1700000000';
    private const UNREACHABLE = 'Could not reach that server.';
    private const CSS = 'plugins/Matinee/Assets/css/matinee.css';

    private int $owner;
    private int $friend;
    private int $outsider;
    private int $server;
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMatinee();
        $this->now = time();
        $this->useSigner();
        $this->owner = $this->user('olive');
        $this->friend = $this->user('fred');
        $this->outsider = $this->user('oscar');
        $this->server = $this->server($this->owner);
        $this->t = new \Kanboard\Plugin\Matinee\Test\FakeTransport();
        $this->loginAs($this->owner);
    }

    protected function tearDown(): void
    {
        $this->cleanKeyDir();
        parent::tearDown();
        $this->releaseContainer();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** The container's signer, rebuilt over the current key store on the test clock. */
    private function useSigner(): void
    {
        $this->container->offsetUnset('matineeRefSigner');
        $this->container['matineeRefSigner'] = fn ($c): RefSigner => new RefSigner(Subkeys::refs($c['matineeKeyStore']->rootKey()), fn (): int => $this->now);
    }

    private function sign(int $userId, string $kind, string $path, string $section = '1'): string
    {
        return $this->container['matineeRefSigner']->sign($userId, $this->server, $section, $kind, $path, 3600);
    }

    private function browseRef(int $userId, string $section = '1'): string
    {
        return $this->sign($userId, 'browse', PlexPaths::sectionAll($section), $section);
    }

    private function artRef(int $userId, string $path = self::THUMB): string
    {
        return $this->sign($userId, 'art', $path);
    }

    private function share(): void
    {
        $this->container['matineeServerModel']->setShared($this->server, $this->owner, true);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/plex/'.$name.'.json');
    }

    private function queueListing(string $children = 'children-section'): void
    {
        $this->queueSections();
        $this->t->respond(200, $this->fixture($children));
    }

    private function listing(string $ref, ?string $start = null): MatineeTestResponse
    {
        return $this->get(self::BRW, 'listing', ['ref' => $ref] + ($start === null ? [] : ['start' => $start]));
    }

    private function art(string $ref, string $size = '144'): MatineeTestResponse
    {
        return $this->get(self::BRW, 'art', ['ref' => $ref, 'size' => $size]);
    }

    /** $ref with one body character changed: its signature no longer matches. */
    private static function tamper(string $ref): string
    {
        $ref[2] = $ref[2] === 'A' ? 'B' : 'A';
        return $ref;
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

    /** An art refusal: the status, an empty body, never HTML, and nothing that would let the browser cache it as art. */
    private function assertArtRefused(int $status, MatineeTestResponse $r, string $label = ''): void
    {
        $this->assertSame($status, $r->code(), $label);
        $this->assertSame('', $r->body(), $label);
        $this->assertArrayNotHasKey('Content-Disposition', $r->headers(), $label);
        $this->assertStringNotContainsString('image/', (string) ($r->headers()['Content-Type'] ?? ''), $label);
        $this->assertSame('no-store', $r->headers()['Cache-Control'] ?? null, $label);
        $this->assertSame('nosniff', $r->headers()['X-Content-Type-Options'] ?? null, $label);
    }

    /** @return list<string> the public actions the controller declares */
    private static function actions(): array
    {
        $out = [];
        foreach ((new \ReflectionClass(MatineeBrowseController::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
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
        $this->assertSame(['art', 'index', 'listing', 'server'], self::actions());
        // Core falls back to APP_USER for an action the map lacks, so the role checks below cannot see a missing
        // entry: Plugin::ACL's row must name exactly the controller's actions.
        $rows = array_values(array_filter(\Kanboard\Plugin\Matinee\Plugin::ACL, fn (array $row): bool => $row[0] === self::BRW));
        $this->assertCount(1, $rows);
        $acl = $rows[0][1];
        sort($acl);
        $this->assertSame(self::actions(), $acl);
        $this->assertSame(Role::APP_USER, $rows[0][2]);
        foreach (self::actions() as $action) {
            $this->assertSame($map->getRoleHierarchy(Role::APP_USER), $map->getRoles(self::BRW, $action), $action);
            $this->assertTrue($auth->isAllowed(self::BRW, $action, Role::APP_USER), $action);
            $this->assertFalse($auth->isAllowed(self::BRW, $action, Role::APP_PUBLIC), $action);
        }
    }

    public function testTheControllerRefusesWhenTheExtensionsAreMissing(): void
    {
        $this->get(self::BRW, 'index'); // a fresh recorded response
        $this->forbidden(fn () => new class($this->container) extends MatineeBrowseController {
            protected static function supported(): bool { return false; }
        });
        $this->assertSame('same-origin', $this->container['response']->headers()['Referrer-Policy'] ?? null);
    }

    public function testTheIndexListsWhatEachUserMayBrowse(): void
    {
        $this->queueOk(self::OTHER_MACHINE);
        $this->container['matineeServerModel']->connect($this->outsider, 'Oscar den', 'https://den.example.test:32400', self::TOKEN);

        $body = $this->get(self::BRW, 'index')->body();
        $this->assertStringContainsString('Living room', $body);
        $this->assertStringNotContainsString('Oscar den', $body);
        $this->assertStringContainsString('server_id='.$this->server, $body);
        $this->assertStringNotContainsString('plex.example.test', $body, 'pages never show the address');

        $this->loginAs($this->friend);
        $body = $this->get(self::BRW, 'index')->body();
        $this->assertStringNotContainsString('Living room', $body, 'private');
        $this->assertStringContainsString('No Plex server is shared with you yet.', $body);

        $this->share();
        $this->assertStringContainsString('Living room', $this->get(self::BRW, 'index')->body());
    }

    public function testTheServerPageListsItsSectionsAsBrowseLinks(): void
    {
        $this->queueSections();
        $body = $this->get(self::BRW, 'server', ['server_id' => $this->server])->body();
        foreach (['Movies', 'TV Shows', 'Music', 'Family Photos', 'Twenty digits'] as $title) {
            $this->assertStringContainsString($title, $body);
        }
        $this->assertSame(6, preg_match_all('#href="[^"]*action=listing[^"]*ref=([A-Za-z0-9_.-]+)#', $body, $m));
        $this->assertSame('/library/sections/1/all', $this->container['matineeRefSigner']->verify($m[1][0], $this->owner, 'browse')['r']);
        $this->assertStringNotContainsString('/library/', $body);

        // A shared user gets it too; an outsider on a private server, or a missing server, is 403.
        $this->share();
        $this->loginAs($this->friend);
        $this->queueSections();
        $this->assertStringContainsString('TV Shows', $this->get(self::BRW, 'server', ['server_id' => $this->server])->body());
        $this->container['matineeServerModel']->setShared($this->server, $this->owner, false);
        $this->forbidden(fn () => $this->get(self::BRW, 'server', ['server_id' => $this->server]), 'private');
        $this->loginAs($this->owner);
        $this->forbidden(fn () => $this->get(self::BRW, 'server', ['server_id' => $this->server + 9]), 'missing');
        $this->assertSame([], array_slice($this->t->requests, 2), 'no Plex call for a refusal');
    }

    public function testAPlexFailureIsTheOneFixedMessage(): void
    {
        $this->t->fail(new \RuntimeException('boom '.self::TOKEN));
        $r = $this->get(self::BRW, 'server', ['server_id' => $this->server]);
        $this->assertSame(502, $r->code());
        $this->assertStringContainsString(self::UNREACHABLE, $r->body());
        $this->assertStringNotContainsString('boom', $r->body());

        $this->t->respond(401, '');
        $r = $this->listing($this->browseRef($this->owner));
        $this->assertSame(502, $r->code());
        $this->assertStringContainsString(self::UNREACHABLE, $r->body());
    }

    /** A key store that will not load (BrowseModel maps it to the connect failure) is the same page, and a 502 for art: never a 500. */
    public function testAKeyStoreFailureIsTheConnectFailurePageAndA502ForArt(): void
    {
        $ref = $this->browseRef($this->owner);
        $art = $this->artRef($this->owner);
        $this->container['matineeKeyStore'] = new KeyStore($this->keyDir, base64_encode(random_bytes(16)));
        $this->useSigner();

        foreach ([
            'server' => fn () => $this->get(self::BRW, 'server', ['server_id' => $this->server]),
            'listing' => fn () => $this->listing($ref),
        ] as $label => $page) {
            $r = $page();
            $this->assertSame(502, $r->code(), $label);
            $this->assertStringContainsString(self::UNREACHABLE, $r->body(), $label);
        }
        $this->assertArtRefused(502, $this->art($art));
        $this->assertSame([], $this->t->requests);
    }

    // ── listing ──────────────────────────────────────────────────────────────

    public function testTheListingIsAPosterGridOfMatineeLinksOnly(): void
    {
        $this->queueListing();
        $r = $this->listing($this->browseRef($this->owner));
        $body = $r->body();
        $this->assertSame(200, $r->code());

        foreach (['Fake Movie One', 'Fake Show', 'Season 1', 'Pilot', 'Fake Album', 'Fake Artist', '1999'] as $text) {
            $this->assertStringContainsString($text, $body);
        }
        // Breadcrumb: the server's page, then the library.
        $this->assertMatchesRegularExpression('#<a href="[^"]*action=server[^"]*server_id='.$this->server.'[^"]*"[^>]*>Living room</a>#', $body);

        // Posters: lazy, 144 px, through Matinee's art action with a signed art ref.
        $this->assertSame(2, preg_match_all('#<img [^>]*>#', $body, $imgs));
        foreach ($imgs[0] as $img) {
            $this->assertMatchesRegularExpression('#^<img [^>]*loading="lazy"#', $img);
            $this->assertMatchesRegularExpression('#src="[^"?]*\?controller=MatineeBrowseController&amp;action=art&amp;plugin=Matinee&amp;ref=[A-Za-z0-9_.-]+&amp;size=144"#', $img);
        }
        preg_match('#ref=([A-Za-z0-9_.-]+)&amp;size=144#', $imgs[0][0], $m);
        $this->assertSame(['sv' => $this->server, 's' => '1', 'k' => 'art', 'r' => self::THUMB], $this->container['matineeRefSigner']->verify($m[1], $this->owner, 'art'));

        // The show and the season link to their own listing.
        $this->assertSame(2, preg_match_all('#href="[^"?]*\?controller=MatineeBrowseController&amp;action=listing&amp;plugin=Matinee&amp;ref=([A-Za-z0-9_.-]+)"#', $body, $links));
        $this->assertSame('/library/metadata/102/children', $this->container['matineeRefSigner']->verify($links[1][0], $this->owner, 'browse')['r']);

        // Never a Plex path, host or token-shaped thing.
        foreach (['/library/', 'plex.example.test', 'evil.example.test', 'X-Plex', 'UPSTREAM-SUMMARY', '/data/movies'] as $raw) {
            $this->assertStringNotContainsString($raw, $body);
        }
        $this->assertStringContainsString(self::CSS, $body);
    }

    public function testListingAccess(): void
    {
        // The shared user browses with their own ref.
        $this->share();
        $this->loginAs($this->friend);
        $this->queueListing();
        $this->assertStringContainsString('Fake Show', $this->listing($this->browseRef($this->friend))->body());

        // Another user's ref, a private server, a forged, expired or wrong-kind ref, a missing ref: 403, Plex untouched.
        $friendRef = $this->browseRef($this->friend);
        $this->t = new \Kanboard\Plugin\Matinee\Test\FakeTransport();
        $this->loginAs($this->outsider);
        $this->forbidden(fn () => $this->listing($friendRef), 'another user');
        $this->loginAs($this->friend);
        $this->container['matineeServerModel']->setShared($this->server, $this->owner, false);
        $this->forbidden(fn () => $this->listing($friendRef), 'unshared');
        $this->loginAs($this->owner);
        $ref = $this->browseRef($this->owner);
        $this->forbidden(fn () => $this->listing(self::tamper($ref)), 'forged');
        $this->forbidden(fn () => $this->listing($this->artRef($this->owner)), 'art ref as browse');
        $this->forbidden(fn () => $this->listing(''), 'no ref');
        $this->forbidden(fn () => $this->get(self::BRW, 'listing', ['ref' => [$ref]]), 'array ref');
        $this->now += 3601;
        $this->forbidden(fn () => $this->listing($ref), 'expired');
        $this->assertSame([], $this->t->requests);
    }

    public function testHostileTitlesAreEscaped(): void
    {
        $this->queueListing('children-hostile');
        $body = $this->listing($this->browseRef($this->owner))->body();
        foreach (["<script>alert('t')", '<img src=x', '<b>gp', '<Night>', '<i>Movies'] as $raw) {
            $this->assertStringNotContainsString($raw, $body);
        }
        $this->assertStringContainsString('&lt;script&gt;alert(&#039;t&#039;)&lt;/script&gt;', $body);
        $this->assertStringContainsString('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $body);
        // Core's text->e() leaves an existing entity as is (no double encoding): it renders as text, never as markup.
        $this->assertStringContainsString('&lt;b&gt;gp&lt;/b&gt;&amp; · ', $body);
        $this->assertStringContainsString('Tom &amp; Jerry&#039;s &quot;Big&quot; &lt;Night&gt;', $body);
    }

    public function testHostileSectionTitlesAreEscapedOnTheServerPage(): void
    {
        $this->t->respondJson(['MediaContainer' => ['Directory' => [['key' => '1', 'type' => 'movie', 'title' => '<script>x</script>']]]]);
        $body = $this->get(self::BRW, 'server', ['server_id' => $this->server])->body();
        $this->assertStringNotContainsString('<script>x', $body);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $body);
    }

    /** ServerModel stores any name without control characters: every page that shows it escapes it. */
    public function testAHostileServerNameIsEscapedOnEveryPage(): void
    {
        $name = '<script>n</script>"><img src=x onerror=1>';
        $this->queueOk(); // update re-checks the server
        $this->container['matineeServerModel']->update($this->server, $this->owner, $name, self::BASE, null);
        $this->share();
        $this->loginAs($this->friend);
        $this->t = new \Kanboard\Plugin\Matinee\Test\FakeTransport();
        $this->queueSections();
        $this->queueListing();
        $pages = [
            'index' => $this->get(self::BRW, 'index')->body(),
            'server' => $this->get(self::BRW, 'server', ['server_id' => $this->server])->body(),
            'listing' => $this->listing($this->browseRef($this->friend))->body(),
        ];
        foreach ($pages as $label => $body) {
            $this->assertStringNotContainsString('<script>n', $body, $label);
            $this->assertStringNotContainsString('<img src=x', $body, $label);
            $this->assertStringContainsString('&lt;script&gt;n&lt;/script&gt;&quot;&gt;&lt;img src=x onerror=1&gt;', $body, $label);
        }
    }

    /** The last crumb is the live section's title (from the sections fetch B3 already makes), escaped. */
    public function testTheListingCrumbIsTheSectionTitleEscaped(): void
    {
        $sections = fn (string $title): array => ['MediaContainer' => ['Directory' => [['key' => '1', 'type' => 'movie', 'title' => $title]]]];

        $this->queueListing();
        $body = $this->listing($this->browseRef($this->owner))->body();
        $this->assertMatchesRegularExpression('#›</span>\s*Movies\s*</h2>#', $body);
        $this->assertCount(2, $this->t->requests, 'no extra Plex request for the title');

        $this->t = new \Kanboard\Plugin\Matinee\Test\FakeTransport();
        $this->t->respondJson($sections('<i>Films</i>"><img src=x onerror=2>'));
        $this->t->respond(200, $this->fixture('children-section'));
        $body = $this->listing($this->browseRef($this->owner))->body();
        $this->assertStringNotContainsString('<i>Films', $body);
        $this->assertStringNotContainsString('<img src=x onerror=2', $body);
        $this->assertMatchesRegularExpression('#›</span>\s*&lt;i&gt;Films&lt;/i&gt;&quot;&gt;&lt;img src=x onerror=2&gt;\s*</h2>#', $body);
        $this->assertCount(2, $this->t->requests, 'no extra Plex request for the title');

        // A section with no title falls back to "Library".
        $this->t = new \Kanboard\Plugin\Matinee\Test\FakeTransport();
        $this->t->respondJson($sections(''));
        $this->t->respond(200, $this->fixture('children-section'));
        $this->assertMatchesRegularExpression('#›</span>\s*Library\s*</h2>#', $this->listing($this->browseRef($this->owner))->body());
    }

    // ── paging ───────────────────────────────────────────────────────────────

    /** A start past the end (a stale link to a library that shrank) shows no count and no Previous, only a way back. */
    public function testAStartPastTheEndLinksBackToTheFirstPage(): void
    {
        $ref = $this->browseRef($this->owner);
        foreach (['250', '300', '1000000'] as $start) {
            $this->t = new \Kanboard\Plugin\Matinee\Test\FakeTransport();
            $this->queueSections();
            $this->t->respondJson(['MediaContainer' => ['totalSize' => 250, 'librarySectionID' => 1, 'Metadata' => []]]);
            $r = $this->listing($ref, $start);
            $body = $r->body();
            $this->assertSame(200, $r->code(), $start);
            $this->assertStringContainsString('Nothing to show here.', $body, $start);
            $this->assertStringNotContainsString('matinee-page-count', $body, $start);
            $this->assertStringNotContainsString(' of 250', $body, $start);
            $this->assertStringNotContainsString('matinee-page-prev', $body, $start);
            $this->assertStringNotContainsString('matinee-page-next', $body, $start);
            $this->assertMatchesRegularExpression('#<a href="[^"?]*\?controller=MatineeBrowseController&amp;action=listing&amp;plugin=Matinee&amp;ref='.preg_quote($ref, '#').'&amp;start=0"[^>]*class="matinee-page-first"#', $body, $start);
        }
        // The last real page keeps its count and Previous, and has no way-back link.
        $this->t = new \Kanboard\Plugin\Matinee\Test\FakeTransport();
        $this->queueListing();
        $body = $this->listing($ref, '240')->body();
        $this->assertStringContainsString('241–250 of 250', $body);
        $this->assertStringContainsString('matinee-page-prev', $body);
        $this->assertStringNotContainsString('matinee-page-first', $body);
    }

    public function testPagingLinksCarryTheRefAndStartAndNeverAPlexPath(): void
    {
        $ref = $this->browseRef($this->owner);
        $page = function (?string $start) use ($ref): array {
            $this->t = new \Kanboard\Plugin\Matinee\Test\FakeTransport();
            $this->queueListing();
            $body = $this->listing($ref, $start)->body();
            preg_match_all('#<a href="[^"?]*(\?[^"]*)"[^>]*class="matinee-page-(prev|next)"#', $body, $m, PREG_SET_ORDER);
            $links = [];
            foreach ($m as [, $href, $rel]) {
                $links[$rel] = $href;
            }
            $this->assertStringNotContainsString('/library/', $body);
            return $links;
        };
        $href = fn (int $start): string => '?controller=MatineeBrowseController&amp;action=listing&amp;plugin=Matinee&amp;ref='.$ref.'&amp;start='.$start;

        $this->assertSame(['next' => $href(60)], $page(null));
        $this->assertStringContainsString('X-Plex-Container-Start=0&X-Plex-Container-Size=60', $this->t->requests[1]['url']);
        $this->assertSame(['prev' => $href(0), 'next' => $href(120)], $page('60'));
        $this->assertStringContainsString('X-Plex-Container-Start=60&', $this->t->requests[1]['url']);
        // 250 items: 240 is the last page; an odd start steps back to 0 at most.
        $this->assertSame(['prev' => $href(120), 'next' => $href(240)], $page('180'));
        $this->assertSame(['prev' => $href(180)], $page('240'));
        $this->assertSame(['prev' => $href(0), 'next' => $href(90)], $page('30'));

        // A total that is an exact multiple of the page: the page ending on it has no next.
        $exact = function (string $start) use ($ref): string {
            $this->t = new \Kanboard\Plugin\Matinee\Test\FakeTransport();
            $this->queueSections();
            $this->t->respondJson(['MediaContainer' => ['totalSize' => 120, 'librarySectionID' => 1, 'Metadata' => [['ratingKey' => '1', 'type' => 'movie', 'title' => 'Only']]]]);
            return $this->listing($ref, $start)->body();
        };
        $this->assertStringContainsString('class="matinee-page-next"', $exact('0'));
        $this->assertStringNotContainsString('class="matinee-page-next"', $exact('60'));

        $this->t = new \Kanboard\Plugin\Matinee\Test\FakeTransport();
        foreach (['-1', 'abc', '1e3', '1000001', ' 60'] as $bad) {
            $this->forbidden(fn () => $this->listing($ref, $bad), $bad);
        }
        $this->assertSame([], $this->t->requests);
    }

    // ── art ──────────────────────────────────────────────────────────────────

    public function testArtIsTheUpstreamBytesWithExactlyTheS11Headers(): void
    {
        foreach (['144', '512'] as $size) {
            $this->t->respond(200, self::JPEG, ['content-type' => 'image/jpeg; charset=binary']);
            $r = $this->art($this->artRef($this->owner), $size);
            $this->assertSame(200, $r->code());
            $this->assertSame(self::JPEG, $r->body());
            $this->assertSame([
                'Cache-Control' => 'private, max-age=3600',
                'Content-Disposition' => 'inline',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Content-Type' => 'image/jpeg',
                'Cross-Origin-Resource-Policy' => 'same-origin',
                'Referrer-Policy' => 'same-origin',
                'X-Content-Type-Options' => 'nosniff',
            ], (function (array $h): array { ksort($h); return $h; })($r->headers()), $size);
            $this->assertStringContainsString('width='.$size.'&height='.$size, end($this->t->requests)['url']);
        }
        foreach (['image/png', 'image/webp'] as $type) {
            $this->t->respond(200, 'bytes', ['content-type' => $type]);
            $this->assertSame($type, $this->art($this->artRef($this->owner))->headers()['Content-Type']);
        }
    }

    public function testArtForASharedUserAndRefusalsAreEmpty403s(): void
    {
        $this->share();
        $this->loginAs($this->friend);
        $this->t->respond(200, self::JPEG, ['content-type' => 'image/jpeg']);
        $this->assertSame(self::JPEG, $this->art($this->artRef($this->friend))->body());

        $this->t = new \Kanboard\Plugin\Matinee\Test\FakeTransport();
        $friendRef = $this->artRef($this->friend);
        $ownerRef = $this->artRef($this->owner);
        $this->assertArtRefused(403, $this->art($ownerRef), 'another user\'s ref');
        $this->assertArtRefused(403, $this->art(self::tamper($friendRef)), 'forged');
        $this->assertArtRefused(403, $this->art($this->browseRef($this->friend)), 'browse ref as art');
        $this->assertArtRefused(403, $this->art(''), 'no ref');
        $this->assertArtRefused(403, $this->get(self::BRW, 'art', ['ref' => [$friendRef], 'size' => '144']), 'array ref');
        foreach (['1080', '0', '', '144abc', ' 144', '144.0', '-144'] as $size) {
            $this->assertArtRefused(403, $this->art($friendRef, $size), 'size '.$size);
        }
        $this->assertArtRefused(403, $this->get(self::BRW, 'art', ['ref' => $friendRef]), 'no size');

        $this->container['matineeServerModel']->setShared($this->server, $this->owner, false);
        $this->assertArtRefused(403, $this->art($friendRef), 'unshared');
        $this->loginAs($this->owner);
        $this->now += 3601;
        $this->assertArtRefused(403, $this->art($ownerRef), 'expired');
        $this->assertSame([], $this->t->requests);
    }

    /** An app admin who is not the owner is no one special on a private server (MatineePolicy::canBrowse): every page refuses, Plex untouched. */
    public function testAnAdminWhoIsNotTheOwnerCannotBrowseAPrivateServer(): void
    {
        $admin = $this->user('ada', Role::APP_ADMIN);
        $this->loginAs($admin);

        $body = $this->get(self::BRW, 'index')->body();
        $this->assertStringNotContainsString('Living room', $body);
        $this->assertStringNotContainsString('server_id='.$this->server, $body);
        $this->assertStringContainsString('No Plex server is shared with you yet.', $body);

        $this->forbidden(fn () => $this->get(self::BRW, 'server', ['server_id' => $this->server]), 'server');
        $this->forbidden(fn () => $this->listing($this->browseRef($admin)), 'listing');
        $this->assertArtRefused(403, $this->art($this->artRef($admin)), 'art');
        $this->assertSame([], $this->t->requests);
    }

    public function testABadUpstreamIsAnEmpty502(): void
    {
        $ref = $this->artRef($this->owner);
        $this->t->respond(200, '<svg onload="alert(1)"/>', ['content-type' => 'image/svg+xml']);
        $this->t->respond(200, '<html>'.self::TOKEN.'</html>', ['content-type' => 'text/html']);
        $this->t->respond(200, 'x');
        $this->t->respond(404, '<html>not found</html>', ['content-type' => 'image/jpeg']);
        $this->t->respond(500, self::TOKEN, ['content-type' => 'image/jpeg']);
        $this->t->fail(PlexException::unreachable());
        for ($i = 0; $i < 6; $i++) {
            $this->assertArtRefused(502, $this->art($ref), (string) $i);
        }
    }

    /** The controller re-checks the type it puts in Content-Type, whatever the model hands it. */
    public function testTheControllerSendsOnlyAnAllowedImageType(): void
    {
        $this->container->offsetUnset('matineeBrowseModel');
        $this->container['matineeBrowseModel'] = new class {
            public string $type = 'image/svg+xml';
            public function art(int $userId, string $ref, int $size): array
            {
                return ['type' => $this->type, 'body' => '<svg/>'];
            }
        };
        foreach (['image/svg+xml', 'text/html', 'image/jpeg; x', 'IMAGE/JPEG', ''] as $type) {
            $this->container['matineeBrowseModel']->type = $type;
            $this->assertArtRefused(502, $this->art('anything'), $type);
        }
    }

    // ── hooks and headers ────────────────────────────────────────────────────

    public function testTheDashboardSidebarLinksToMedia(): void
    {
        $html = $this->container['helper']->hook->render('template:dashboard:sidebar', ['user' => $this->container['userModel']->getById($this->owner)]);
        $this->assertMatchesRegularExpression('#<a href="[^"]*controller=MatineeBrowseController[^"]*action=index[^"]*"[^>]*>Media</a>#', $html);
    }

    public function testBrowsePagesUseTheStylesheetAndSendReferrerPolicy(): void
    {
        $this->queueSections();
        $this->queueListing();
        $this->t->respond(200, self::JPEG, ['content-type' => 'image/jpeg']);
        $responses = [
            'index' => $this->get(self::BRW, 'index'),
            'server' => $this->get(self::BRW, 'server', ['server_id' => $this->server]),
            'listing' => $this->listing($this->browseRef($this->owner)),
        ];
        foreach ($responses as $label => $r) {
            $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="[^"]*'.preg_quote(self::CSS, '#').'\?\d+"#', $r->body(), $label);
        }
        $this->assertFileExists(__DIR__.'/../Assets/css/matinee.css');
        $responses['art'] = $this->art($this->artRef($this->owner));
        $responses['refused art'] = $this->art('nope');
        foreach ($responses as $label => $r) {
            $this->assertSame('same-origin', $r->headers()['Referrer-Policy'] ?? null, $label);
        }
    }

    public function testTheStylesheetAndTemplatesHaveNoInlineScriptOrStyle(): void
    {
        $files = array_merge(glob(__DIR__.'/../Template/browse/*.php'), [__DIR__.'/../Template/dashboard/sidebar.php']);
        $this->assertGreaterThanOrEqual(4, count($files));
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('#<script|<style|\sstyle=|\son[a-z]+=#i', $src, basename($file));
        }
    }
}
