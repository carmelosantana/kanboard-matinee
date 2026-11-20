<?php

require_once __DIR__ . '/support/FakeTransport.php';

use Kanboard\Plugin\Matinee\Http\PlexClient;
use Kanboard\Plugin\Matinee\Http\PlexException;
use Kanboard\Plugin\Matinee\Http\PlexResponse;
use Kanboard\Plugin\Matinee\Http\UnsafeUrlException;
use Kanboard\Plugin\Matinee\Test\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * PlexClient (spec §3, §11 S9, S11, S13, S14): headers, whitelisted parsing of /identity, /library/sections and
 * browse listings (children), proxied art (art), and fixed, scrubbed errors. No network: every byte comes from FakeTransport.
 */
class PlexClientTest extends TestCase
{
    private const TOKEN = 'test-token-NOT-REAL-0000';
    private const BASE = 'https://plex.example.test:32400';
    private const CLIENT_ID = 'c0ffee00-1111-4222-8333-444455556666';
    private const MACHINE = '0123456789abcdef0123456789abcdef01234567';

    private FakeTransport $t;

    protected function setUp(): void
    {
        $this->t = new FakeTransport();
    }

    private function client(?string $machineId = null, string $base = self::BASE): PlexClient
    {
        return new PlexClient($base, self::TOKEN, self::CLIENT_ID, $this->t, $machineId, 'Matinee (alice)');
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/plex/' . $name . '.json');
    }

    private function expectPlexError(callable $fn, string $reason): PlexException
    {
        try {
            $fn();
        } catch (PlexException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($reason, $e->getMessage());
            $this->assertMessageIsClean($e);
            return $e;
        }
        $this->fail("expected PlexException $reason");
    }

    private function assertMessageIsClean(\Throwable $e): void
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            $m = $x->getMessage();
            $this->assertStringNotContainsString(self::TOKEN, $m);
            $this->assertStringNotContainsString('?', $m);
            $this->assertStringNotContainsString('plex.example.test', $m);
            $this->assertStringNotContainsString('/library', $m);
            $this->assertStringNotContainsString('UPSTREAM-BODY', $m);
        }
    }

    // ── requests and headers ─────────────────────────────────────────────────

    public function testIdentityRequestsTheIdentityPathWithTheTokenOnlyInTheHeader(): void
    {
        $this->t->respond(200, $this->fixture('identity'));
        $this->client()->identity();

        $this->assertCount(1, $this->t->requests);
        [$req] = $this->t->requests;
        $this->assertSame(self::BASE . '/identity', $req['url']);
        $this->assertStringNotContainsStringIgnoringCase('token', $req['url']);
        $this->assertStringNotContainsString('?', $req['url']);
        $this->assertSame(self::TOKEN, $req['headers']['X-Plex-Token']);
    }

    public function testSendsTheFixedHeaderSet(): void
    {
        $this->t->respond(200, $this->fixture('sections'));
        $this->client()->sections();

        [$req] = $this->t->requests;
        $this->assertSame(self::BASE . '/library/sections', $req['url']);
        $this->assertSame([
            'X-Plex-Token'             => self::TOKEN,
            'X-Plex-Client-Identifier' => self::CLIENT_ID,
            'X-Plex-Product'           => 'Matinee',
            'X-Plex-Device-Name'       => 'Matinee (alice)',
            'Accept'                   => 'application/json',
        ], $req['headers']);
    }

    public function testTheTokenAppearsInExactlyOneHeaderAndNoUrl(): void
    {
        $this->t->respond(200, $this->fixture('identity'))->respond(200, $this->fixture('sections'));
        $c = $this->client();
        $c->identity();
        $c->sections();

        foreach ($this->t->requests as $req) {
            $this->assertStringNotContainsString(self::TOKEN, $req['url']);
            $hits = array_keys(array_filter($req['headers'], fn ($v) => str_contains((string) $v, self::TOKEN)));
            $this->assertSame(['X-Plex-Token'], $hits);
        }
    }

    public function testTrailingSlashesOnTheBaseUrlAreTrimmed(): void
    {
        $this->t->respond(200, $this->fixture('identity'));
        $this->client(null, self::BASE . '//')->identity();
        $this->assertSame(self::BASE . '/identity', $this->t->requests[0]['url']);
    }

    public function testTheDeviceNameDefaultsToMatinee(): void
    {
        $this->t->respond(200, $this->fixture('identity'));
        (new PlexClient(self::BASE, self::TOKEN, self::CLIENT_ID, $this->t))->identity();
        $this->assertSame('Matinee', $this->t->requests[0]['headers']['X-Plex-Device-Name']);
    }

    /** @dataProvider badBaseUrls */
    public function testABaseUrlWithAQueryOrFragmentIsRefused(string $base): void
    {
        try {
            new PlexClient($base, self::TOKEN, self::CLIENT_ID, $this->t);
            $this->fail('expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertMessageIsClean($e);
        }
        $this->assertSame([], $this->t->requests);
    }

    public function badBaseUrls(): array
    {
        return [
            'query'    => [self::BASE . '/?X-Plex-Token=' . self::TOKEN],
            'fragment' => [self::BASE . '/#x'],
        ];
    }

    /** @dataProvider headerInjection */
    public function testHeaderValuesWithControlCharactersAreRefused(string $token, string $clientId, string $device): void
    {
        try {
            new PlexClient(self::BASE, $token, $clientId, $this->t, null, $device);
            $this->fail('expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertMessageIsClean($e);
            $this->assertStringNotContainsString('evil', $e->getMessage());
        }
    }

    public function headerInjection(): array
    {
        return [
            'token CRLF'     => [self::TOKEN . "\r\nX-evil: 1", self::CLIENT_ID, 'Matinee'],
            'token NUL'      => [self::TOKEN . "\0evil", self::CLIENT_ID, 'Matinee'],
            'empty token'    => ['', self::CLIENT_ID, 'Matinee'],
            'client id LF'   => [self::TOKEN, "id\nX-evil: 1", 'Matinee'],
            'device name CR' => [self::TOKEN, self::CLIENT_ID, "Matinee\rX-evil: 1"],
        ];
    }

    /**
     * Only deviceName()'s output or the bare default is a device name (S14).
     *
     * @dataProvider badDeviceNames
     */
    public function testADeviceNameNotShapedLikeDeviceNamesOutputIsRefused(string $device): void
    {
        try {
            new PlexClient(self::BASE, self::TOKEN, self::CLIENT_ID, $this->t, null, $device);
            $this->fail('expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertMessageIsClean($e);
            $this->assertStringNotContainsString('Evil', $e->getMessage());
        }
    }

    public function badDeviceNames(): array
    {
        return [
            'NEL (C1 control)'  => ["Evil\xc2\x85"],
            'LF in parentheses' => ["Matinee (a\nb)"],
            'other product'     => ['Evil'],
            'empty'             => [''],
            'empty parentheses' => ['Matinee ()'],
            'trailing newline'  => ["Matinee\n"],
            'too long'          => ['Matinee (' . str_repeat('x', 41) . ')'],
            'unicode'           => ['Matinee (José)'],
        ];
    }

    /** @dataProvider deviceNames */
    public function testEveryDeviceNameOutputIsAccepted(string $username, int $id, string $expected): void
    {
        $this->t->respond(200, $this->fixture('identity'));
        (new PlexClient(self::BASE, self::TOKEN, self::CLIENT_ID, $this->t, null, PlexClient::deviceName($username, $id)))->identity();
        $this->assertSame($expected, $this->t->requests[0]['headers']['X-Plex-Device-Name']);
    }

    // ── identity() ───────────────────────────────────────────────────────────

    public function testIdentityReturnsOnlyTheWhitelistedFields(): void
    {
        $this->t->respond(200, $this->fixture('identity'));
        $this->assertSame(
            ['machineIdentifier' => self::MACHINE, 'version' => '1.40.2.8395-c67dce28e'],
            $this->client()->identity(),
        );
    }

    /** @dataProvider badMachineIds */
    public function testIdentityWithAnInsaneMachineIdentifierIsBadJson(mixed $id): void
    {
        $this->t->respondJson(['MediaContainer' => ['machineIdentifier' => $id, 'version' => '1.0']]);
        $this->expectPlexError(fn () => $this->client()->identity(), PlexException::BAD_JSON);
    }

    public function badMachineIds(): array
    {
        return [
            'missing'   => [null],
            'empty'     => [''],
            'pipe'      => ['abc|def'],
            'colon'     => ['abc:def'],
            'space'     => ['abc def'],
            'too long'  => [str_repeat('a', 65)],
            'not str'   => [['x']],
            'number'    => [12345],
        ];
    }

    public function testIdentityAcceptsA64CharMachineIdentifier(): void
    {
        $id = str_repeat('a', 63) . '-';
        $this->t->respondJson(['MediaContainer' => ['machineIdentifier' => $id, 'version' => '1.0']]);
        $this->assertSame($id, $this->client()->identity()['machineIdentifier']);
    }

    /** @dataProvider versions */
    public function testIdentityVersionIsFilteredOrBlanked(mixed $raw, string $expected): void
    {
        $this->t->respondJson(['MediaContainer' => ['machineIdentifier' => self::MACHINE, 'version' => $raw]]);
        $this->assertSame($expected, $this->client()->identity()['version']);
    }

    public function versions(): array
    {
        return [
            'normal'     => ['1.40.2.8395-c67dce28e', '1.40.2.8395-c67dce28e'],
            'missing'    => [null, ''],
            'array'      => [['1'], ''],
            'markup'     => ['<script>', ''],
            'too long'   => [str_repeat('1', 65), ''],
        ];
    }

    public function testIdentityWithoutAMediaContainerIsBadJson(): void
    {
        $this->t->respondJson(['nope' => true]);
        $this->expectPlexError(fn () => $this->client()->identity(), PlexException::BAD_JSON);
    }

    public function testIdentityFromADifferentServerThanThePinnedOneIsServerChanged(): void
    {
        $this->t->respond(200, $this->fixture('identity'));
        $this->expectPlexError(fn () => $this->client('ffff')->identity(), PlexException::SERVER_CHANGED);
    }

    public function testIdentityFromThePinnedServerPasses(): void
    {
        $this->t->respond(200, $this->fixture('identity'));
        $this->assertSame(self::MACHINE, $this->client(self::MACHINE)->identity()['machineIdentifier']);
    }

    // ── sections() ───────────────────────────────────────────────────────────

    public function testSectionsAreFilteredByTypeAndKey(): void
    {
        $this->t->respond(200, $this->fixture('sections'));
        $this->assertSame([
            ['key' => '1', 'title' => 'Movies', 'type' => 'movie'],
            ['key' => '2', 'title' => 'TV Shows', 'type' => 'show'],
            ['key' => '5', 'title' => 'Music', 'type' => 'artist'],
            ['key' => '9', 'title' => 'Family Photos', 'type' => 'photo'],
            ['key' => '12', 'title' => '42', 'type' => 'movie'],
            ['key' => '99999999999999999999', 'title' => 'Twenty digits', 'type' => 'show'],
        ], $this->client()->sections());
    }

    public function testSectionTitlesAreCappedAt200Characters(): void
    {
        $title = str_repeat('é', 250);
        $this->t->respondJson(['MediaContainer' => ['Directory' => [['key' => '1', 'type' => 'movie', 'title' => $title]]]]);
        $out = $this->client()->sections();
        $this->assertSame(200, mb_strlen($out[0]['title'], 'UTF-8'));
        $this->assertSame(mb_substr($title, 0, 200, 'UTF-8'), $out[0]['title']);
    }

    /** @dataProvider emptyDirectories */
    public function testSectionsWithNoDirectoryListIsEmpty(array $container): void
    {
        $this->t->respondJson(['MediaContainer' => $container]);
        $this->assertSame([], $this->client()->sections());
    }

    public function emptyDirectories(): array
    {
        return [
            'missing'   => [['size' => 0]],
            'not list'  => [['Directory' => 'x']],
            'empty'     => [['Directory' => []]],
        ];
    }

    public function testSectionsWithAMismatchedMachineIdentifierIsServerChanged(): void
    {
        $this->t->respond(200, $this->fixture('sections-with-machine'));
        $this->expectPlexError(fn () => $this->client('someone-else')->sections(), PlexException::SERVER_CHANGED);
    }

    public function testSectionsWithTheMatchingMachineIdentifierPasses(): void
    {
        $this->t->respond(200, $this->fixture('sections-with-machine'));
        $this->assertCount(1, $this->client(self::MACHINE)->sections());
    }

    public function testSectionsWithoutAMachineIdentifierPassesEvenWhenPinned(): void
    {
        $this->t->respond(200, $this->fixture('sections'));
        $this->assertCount(6, $this->client(self::MACHINE)->sections());
    }

    public function testAnUnpinnedClientIgnoresTheContainerMachineIdentifier(): void
    {
        $this->t->respond(200, $this->fixture('sections-with-machine'));
        $this->assertCount(1, $this->client()->sections());
    }

    // ── errors ───────────────────────────────────────────────────────────────

    /** @dataProvider statuses */
    public function testStatusMapping(int $status, string $reason): void
    {
        $this->t->respond($status, 'UPSTREAM-BODY ' . self::TOKEN . ' ' . self::BASE . '/x?X-Plex-Token=' . self::TOKEN);
        $e = $this->expectPlexError(fn () => $this->client()->sections(), $reason);
        $this->assertSame($status, $e->status);
    }

    public function statuses(): array
    {
        return [
            '401'        => [401, PlexException::TOKEN_REJECTED],
            '403'        => [403, PlexException::HTTP_ERROR],
            '404'        => [404, PlexException::HTTP_ERROR],
            '500'        => [500, PlexException::HTTP_ERROR],
            '302'        => [302, PlexException::HTTP_ERROR],
            '100'        => [100, PlexException::HTTP_ERROR],
            'status 0'   => [0, PlexException::HTTP_ERROR],
        ];
    }

    public function test401OnIdentityIsTokenRejectedToo(): void
    {
        $this->t->respond(401, '');
        $this->expectPlexError(fn () => $this->client()->identity(), PlexException::TOKEN_REJECTED);
    }

    public function testNoExceptionCarriesTheUpstreamBodyOrAPreviousException(): void
    {
        $this->t->respond(500, 'UPSTREAM-BODY');
        $e = $this->expectPlexError(fn () => $this->client()->sections(), PlexException::HTTP_ERROR);
        $this->assertNull($e->getPrevious());
        $this->assertStringNotContainsString('UPSTREAM-BODY', print_r($e, true));
    }

    /** @dataProvider junkBodies */
    public function testJunkBodiesAreBadJson(string $body): void
    {
        $this->t->respond(200, $body);
        $this->expectPlexError(fn () => $this->client()->sections(), PlexException::BAD_JSON);
    }

    public function junkBodies(): array
    {
        return [
            'html'         => ['<html>UPSTREAM-BODY</html>'],
            'empty'        => [''],
            'truncated'    => ['{"MediaContainer": {'],
            'scalar'       => ['"UPSTREAM-BODY"'],
            'number'       => ['42'],
            'list'         => ['[1,2]'],
            'container str' => ['{"MediaContainer": "x"}'],
            'bad utf-8'    => ["{\"MediaContainer\": {\"title\": \"\xff\"}}"],
        ];
    }

    /** MediaContainer → Directory → [0] → nested objects: 28 levels of nesting fit depth 32, 29 do not. */
    private function nestedSections(int $levels): string
    {
        $inner = str_repeat('{"a":', $levels) . '1' . str_repeat('}', $levels);
        return '{"MediaContainer":{"Directory":[' . $inner . ']}}';
    }

    public function testDepth32IsAccepted(): void
    {
        $this->t->respond(200, $this->nestedSections(28));
        $this->assertSame([], $this->client()->sections());
    }

    public function testPastDepth32IsBadJson(): void
    {
        $this->t->respond(200, $this->nestedSections(29));
        $this->expectPlexError(fn () => $this->client()->sections(), PlexException::BAD_JSON);
    }

    public function testABodyOver2MiBIsTooLarge(): void
    {
        $pad = str_repeat(' ', 2 * 1024 * 1024 - strlen($this->fixture('sections')) + 1);
        $this->t->respond(200, $this->fixture('sections') . $pad);
        $this->expectPlexError(fn () => $this->client()->sections(), PlexException::TOO_LARGE);
    }

    public function testABodyOfExactly2MiBIsAccepted(): void
    {
        $body = $this->fixture('sections');
        $this->t->respond(200, $body . str_repeat(' ', 2 * 1024 * 1024 - strlen($body)));
        $this->assertCount(6, $this->client()->sections());
    }

    public function testATransportFailureIsUnreachableWithNothingChained(): void
    {
        $this->t->fail(new \RuntimeException('connect to ' . self::BASE . '/identity?X-Plex-Token=' . self::TOKEN . ' failed'));
        $e = $this->expectPlexError(fn () => $this->client()->identity(), PlexException::UNREACHABLE);
        $this->assertNull($e->getPrevious());
    }

    public function testAPlexExceptionFromTheTransportPassesThrough(): void
    {
        $this->t->fail(PlexException::tooLarge());
        $this->expectPlexError(fn () => $this->client()->identity(), PlexException::TOO_LARGE);
    }

    public function testAGuardRefusalFromTheTransportPassesThrough(): void
    {
        $this->t->fail(new UnsafeUrlException(UnsafeUrlException::PRIVATE_NETWORK, 'plex.example.test'));
        $this->expectException(UnsafeUrlException::class);
        $this->client()->identity();
    }

    /** Error throwables (TypeError, ValueError, …) are reported like any other transport failure. */
    public function testATransportErrorIsUnreachableWithNothingChained(): void
    {
        $this->t->fail(new \TypeError('x ?X-Plex-Token=' . self::TOKEN));
        $e = $this->expectPlexError(fn () => $this->client()->identity(), PlexException::UNREACHABLE);
        $this->assertNull($e->getPrevious());
    }

    /**
     * With argument capture switched on (production php.ini often has `zend.exception_ignore_args=On`, which
     * would make this pass vacuously), the token argument in the constructor frame is a SensitiveParameterValue.
     */
    public function testTheTokenIsHiddenFromStackTraces(): void
    {
        $was = ini_set('zend.exception_ignore_args', '0');
        try {
            new PlexClient(self::BASE . '/?q', self::TOKEN, self::CLIENT_ID, $this->t);
            $this->fail('expected refusal');
        } catch (\InvalidArgumentException $e) {
            $frame = $e->getTrace()[0];
            $this->assertSame('__construct', $frame['function']);
            $this->assertArrayHasKey('args', $frame);
            $this->assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][1]);
            $this->assertStringNotContainsString(self::TOKEN, print_r($frame['args'], true));
            $this->assertStringNotContainsString(self::TOKEN, $e->getTraceAsString());
        } finally {
            ini_set('zend.exception_ignore_args', (string) $was);
        }
    }

    public function testTheTokenIsNotInVarExportOrAnArrayCast(): void
    {
        $c = $this->client(self::MACHINE);
        $this->t->respond(200, $this->fixture('identity'));
        $c->identity();
        $this->t->requests = []; // the double's own log holds the headers; the client must not
        $this->assertStringNotContainsString(self::TOKEN, var_export($c, true));
        $this->assertStringNotContainsString(self::TOKEN, print_r((array) $c, true));
        $this->assertStringNotContainsString(self::TOKEN, var_export((array) $c, true));
        $this->assertStringNotContainsString(self::TOKEN, print_r(get_object_vars($c), true));
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($c));
        ob_start();
        var_dump((array) $c);
        $this->assertStringNotContainsString(self::TOKEN, (string) ob_get_clean());
    }

    public function testAClientCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);
        serialize($this->client());
    }

    public function testAClientCannotBeUnserialized(): void
    {
        $this->expectException(\LogicException::class);
        unserialize('O:' . strlen(PlexClient::class) . ':"' . PlexClient::class . '":0:{}');
    }

    public function testAClientCannotBeCloned(): void
    {
        $this->expectException(\Error::class);
        $c = $this->client();
        clone $c;
    }

    public function testDebugOutputRedactsTheToken(): void
    {
        $c = $this->client(self::MACHINE);
        ob_start();
        var_dump($c);
        $dump = ob_get_clean() . print_r($c, true);
        $this->assertStringNotContainsString(self::TOKEN, $dump);
        $this->assertStringContainsString('[redacted]', $dump);
        $this->assertStringContainsString(self::MACHINE, $dump);
    }

    public function testEveryReasonHasAFixedMessageAndAStatusOnlyWhereGiven(): void
    {
        $all = [
            PlexException::unreachable(),
            PlexException::httpError(503),
            PlexException::tokenRejected(),
            PlexException::badJson(),
            PlexException::serverChanged(),
            PlexException::tooLarge(),
            PlexException::tokenInUri(),
            PlexException::badType(),
        ];
        $this->assertSame(
            ['plex.unreachable', 'plex.http_error', 'plex.token_rejected', 'plex.bad_json', 'plex.server_changed', 'plex.too_large', 'plex.token_in_uri', 'plex.bad_type'],
            array_map(fn (PlexException $e) => $e->getMessage(), $all),
        );
        $this->assertSame([0, 503, 401, 0, 0, 0, 0, 0], array_map(fn (PlexException $e) => $e->status, $all));
    }

    // ── children() (spec §3, S9, S13; ruling B4) ─────────────────────────────

    /** Every field of a whitelisted item, with nulls/'' for what Plex left out or got wrong. */
    private function item(string $rk, string $type, array $over = []): array
    {
        return array_merge([
            'ratingKey' => $rk, 'type' => $type, 'title' => '', 'parentTitle' => '', 'grandparentTitle' => '',
            'year' => null, 'index' => null, 'duration' => null, 'leafCount' => null, 'thumb' => null, 'key' => null,
        ], $over);
    }

    public function testChildrenRequestsThePathWithOnlyThePagingParamsAndTheTokenOnlyInTheHeader(): void
    {
        $this->t->respond(200, $this->fixture('children-section'));
        $this->client()->children('/library/sections/1/all', 120, 60);

        $this->assertCount(1, $this->t->requests);
        [$req] = $this->t->requests;
        $this->assertSame(
            self::BASE . '/library/sections/1/all?X-Plex-Container-Start=120&X-Plex-Container-Size=60',
            $req['url'],
        );
        $this->assertStringNotContainsString(self::TOKEN, $req['url']);
        $this->assertStringNotContainsStringIgnoringCase('token', $req['url']);
        $hits = array_keys(array_filter($req['headers'], fn ($v) => str_contains((string) $v, self::TOKEN)));
        $this->assertSame(['X-Plex-Token'], $hits);
    }

    public function testChildrenOfAMetadataPath(): void
    {
        $this->t->respond(200, $this->fixture('children-no-section'));
        $this->client()->children('/library/metadata/102/children', 0, 1);
        $this->assertSame(
            self::BASE . '/library/metadata/102/children?X-Plex-Container-Start=0&X-Plex-Container-Size=1',
            $this->t->requests[0]['url'],
        );
    }

    public function testChildrenItemsAreWhitelisted(): void
    {
        $this->t->respond(200, $this->fixture('children-section'));
        $out = $this->client()->children('/library/sections/1/all', 0, 60);

        $this->assertSame('1', $out['section']);
        $this->assertSame(250, $out['total']);
        $this->assertSame([
            $this->item('101', 'movie', ['title' => 'Fake Movie One', 'year' => 1999, 'duration' => 5400000,
                'thumb' => '/library/metadata/101/thumb/1700000000']),
            $this->item('102', 'show', ['title' => 'Fake Show', 'year' => 2004, 'leafCount' => 22,
                'thumb' => '/library/metadata/102/thumb/1700000001', 'key' => '/library/metadata/102/children']),
            $this->item('103', 'season', ['title' => 'Season 1', 'parentTitle' => 'Fake Show', 'index' => 1,
                'leafCount' => 10, 'key' => '/library/metadata/103/children']),
            $this->item('104', 'episode', ['title' => 'Pilot', 'parentTitle' => 'Season 1',
                'grandparentTitle' => 'Fake Show', 'index' => 1, 'duration' => 1800000]),
            $this->item('105', 'artist', ['title' => '42']),
            $this->item('106', 'album', ['title' => 'Fake Album', 'parentTitle' => 'Fake Artist']),
            $this->item('107', 'track', ['title' => 'Fake Track']),
            $this->item('108', 'photo', ['title' => 'Fake Photo']),
        ], $out['items']);
        $this->assertStringNotContainsString('UPSTREAM-SUMMARY', print_r($out, true));
        $this->assertStringNotContainsString('/data/movies', print_r($out, true));
    }

    /** @dataProvider badArtOrKeyShapes */
    public function testAThumbOrKeyWithABadShapeIsDropped(mixed $value): void
    {
        $this->t->respondJson(['MediaContainer' => ['librarySectionID' => '1', 'Metadata' => [
            ['ratingKey' => '1', 'type' => 'movie', 'title' => 'x', 'thumb' => $value, 'key' => $value],
        ]]]);
        $item = $this->client()->children('/library/sections/1/all', 0, 60)['items'][0];
        $this->assertNull($item['thumb']);
        $this->assertNull($item['key']);
    }

    public function badArtOrKeyShapes(): array
    {
        return [
            'absolute url'    => ['https://evil.example.test/library/metadata/1/thumb/2'],
            'protocol-rel'    => ['//evil.example.test/library/metadata/1/thumb/2'],
            'dot dot'         => ['/library/metadata/1/../../x'],
            'encoded dot'     => ['/library/metadata/%2e%2e/thumb/2'],
            'query'           => ['/library/metadata/1/thumb/2?X-Plex-Token=abc'],
            'trailing slash'  => ['/library/metadata/1/thumb/2/'],
            'trailing LF'     => ["/library/metadata/1/thumb/2\n"],
            'int'             => [12],
            'array'           => [['/library/metadata/1/thumb/2']],
            'null'            => [null],
        ];
    }

    public function testAThumbIsNeverKeptAsAKeyNorAKeyAsAThumb(): void
    {
        $this->t->respondJson(['MediaContainer' => ['Metadata' => [
            ['ratingKey' => '1', 'type' => 'show', 'thumb' => '/library/metadata/1/children', 'key' => '/library/metadata/1/thumb/2'],
        ]]]);
        $item = $this->client()->children('/library/metadata/1/children', 0, 60)['items'][0];
        $this->assertNull($item['thumb']);
        $this->assertNull($item['key']);
    }

    public function testChildrenTitlesAreCappedAt200Characters(): void
    {
        $long = str_repeat('é', 250);
        $this->t->respondJson(['MediaContainer' => ['Metadata' => [
            ['ratingKey' => '1', 'type' => 'episode', 'title' => $long, 'parentTitle' => $long, 'grandparentTitle' => $long],
        ]]]);
        $item = $this->client()->children('/library/metadata/1/children', 0, 60)['items'][0];
        foreach (['title', 'parentTitle', 'grandparentTitle'] as $f) {
            $this->assertSame(mb_substr($long, 0, 200, 'UTF-8'), $item[$f], $f);
        }
    }

    public function testChildrenKeepsAtMostThePageSize(): void
    {
        $meta = [];
        for ($i = 1; $i <= 15; $i++) {
            $meta[] = ['ratingKey' => (string) $i, 'type' => 'movie'];
        }
        $this->t->respondJson(['MediaContainer' => ['Metadata' => $meta]]);
        $out = $this->client()->children('/library/sections/1/all', 0, 10);
        $this->assertSame(array_map('strval', range(1, 10)), array_column($out['items'], 'ratingKey'));
        $this->assertSame(10, $out['total']);
    }

    /** @dataProvider unsafeTitles */
    public function testChildrenTitlesLoseControlAndFormatCharacters(string $raw, string $expected): void
    {
        $this->t->respondJson(['MediaContainer' => ['Metadata' => [
            ['ratingKey' => '1', 'type' => 'episode', 'title' => $raw, 'parentTitle' => $raw, 'grandparentTitle' => $raw],
        ]]]);
        $item = $this->client()->children('/library/metadata/1/children', 0, 60)['items'][0];
        foreach (['title', 'parentTitle', 'grandparentTitle'] as $f) {
            $this->assertSame($expected, $item[$f], $f);
        }
    }

    /** @dataProvider unsafeTitles */
    public function testSectionTitlesLoseControlAndFormatCharacters(string $raw, string $expected): void
    {
        $this->t->respondJson(['MediaContainer' => ['Directory' => [['key' => '1', 'type' => 'movie', 'title' => $raw]]]]);
        $this->assertSame($expected, $this->client()->sections()[0]['title']);
    }

    public function unsafeTitles(): array
    {
        return [
            'rtl override'    => ["evil\u{202E}gpj.exe", 'evilgpj.exe'],
            'zero-width'      => ["Fake\u{200B}Movie", 'FakeMovie'],
            'tab and newline' => ["Fake\tMovie\r\nTwo", 'FakeMovieTwo'],
            'nul and del'     => ["a\x00b\x7Fc", 'abc'],
            'plain unicode'   => ['Amélie — 東京', 'Amélie — 東京'],
        ];
    }

    /** JSON cannot carry invalid UTF-8 (json_decode refuses it), so the title filter is checked directly. */
    public function testATitleThatIsNotValidUtf8BecomesEmpty(): void
    {
        $text = new \ReflectionMethod(PlexClient::class, 'text');
        $this->assertSame('', $text->invoke(null, "Fake\xC3\x28Movie"));
        $this->assertSame('', $text->invoke(null, "\xFF\xFE"));
        $this->assertSame('ok', $text->invoke(null, 'ok'));
    }

    /** A php.ini arg_separator.output of ';' must not change the URL Plex sees. */
    public function testQueryStringsUseAmpersandWhateverArgSeparatorOutputSays(): void
    {
        $was = ini_set('arg_separator.output', ';');
        try {
            $this->t->respond(200, $this->fixture('children-section'));
            $this->client()->children('/library/sections/1/all', 120, 60);
            $this->t->respond(200, self::PNG, ['Content-Type' => 'image/png']);
            $this->client()->art('/library/metadata/101/thumb/1700000000', 144);
        } finally {
            ini_set('arg_separator.output', $was);
        }
        $this->assertSame(
            self::BASE . '/library/sections/1/all?X-Plex-Container-Start=120&X-Plex-Container-Size=60',
            $this->t->requests[0]['url'],
        );
        $this->assertSame(
            self::BASE . '/photo/:/transcode?width=144&height=144&minSize=1&upscale=0'
                . '&url=%2Flibrary%2Fmetadata%2F101%2Fthumb%2F1700000000',
            $this->t->requests[1]['url'],
        );
    }

    public function testSectionIsNullWhenLibrarySectionIdIsAbsent(): void
    {
        $this->t->respond(200, $this->fixture('children-no-section'));
        $out = $this->client()->children('/library/metadata/102/children', 0, 60);
        $this->assertNull($out['section']);
        $this->assertCount(2, $out['items']);
    }

    /** @dataProvider sectionIds */
    public function testSectionIsStringifiedOrNull(mixed $raw, ?string $expected): void
    {
        $this->t->respondJson(['MediaContainer' => ['librarySectionID' => $raw, 'Metadata' => []]]);
        $this->assertSame($expected, $this->client()->children('/library/sections/1/all', 0, 60)['section']);
    }

    public function sectionIds(): array
    {
        return [
            'int'        => [7, '7'],
            'string'     => ['12', '12'],
            'null'       => [null, null],
            'negative'   => [-1, null],
            'float'      => [1.5, null],
            'bool'       => [true, null],
            'path'       => ['/library/sections/1', null],
            'trailing LF' => ["1\n", null],
            '21 digits'  => ['123456789012345678901', null],
            'array'      => [['1'], null],
        ];
    }

    /** @dataProvider totals */
    public function testTotalIsTotalSizeElseStartPlusWhatCameBack(mixed $raw, int $start, int $expected): void
    {
        $mc = ['Metadata' => [['ratingKey' => '1', 'type' => 'movie'], ['ratingKey' => 'x', 'type' => 'movie']]];
        if ($raw !== 'absent') {
            $mc['totalSize'] = $raw;
        }
        $this->t->respondJson(['MediaContainer' => $mc]);
        $this->assertSame($expected, $this->client()->children('/library/sections/1/all', $start, 60)['total']);
    }

    public function totals(): array
    {
        return [
            'int'          => [250, 0, 250],
            'digit string' => ['250', 0, 250],
            'zero'         => [0, 0, 0],
            'absent'       => ['absent', 0, 2],
            'absent paged' => ['absent', 60, 62],
            'negative'     => [-3, 0, 2],
            'float'        => [2.5, 0, 2],
            'junk'         => ['lots', 0, 2],
            'huge'         => ['99999999999999999999', 0, 2],
        ];
    }

    /** @dataProvider emptyMetadata */
    public function testChildrenWithNoMetadataListIsEmpty(array $mc): void
    {
        $this->t->respondJson(['MediaContainer' => $mc]);
        $out = $this->client()->children('/library/sections/1/all', 0, 60);
        $this->assertSame([], $out['items']);
        $this->assertSame(0, $out['total']);
    }

    public function emptyMetadata(): array
    {
        return [
            'missing'  => [['size' => 0]],
            'not list' => [['Metadata' => ['a' => ['ratingKey' => '1', 'type' => 'movie']]]],
            'string'   => [['Metadata' => 'x']],
            'empty'    => [['Metadata' => []]],
        ];
    }

    /** @dataProvider badChildrenArgs */
    public function testChildrenRefusesBadArgumentsWithNoRequest(string $path, int $start, int $size): void
    {
        try {
            $this->client()->children($path, $start, $size);
            $this->fail('expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertMessageIsClean($e);
            $this->assertStringNotContainsString('evil', $e->getMessage());
        }
        $this->assertSame([], $this->t->requests);
    }

    public function badChildrenArgs(): array
    {
        return [
            'art path'      => ['/library/metadata/1/thumb/2', 0, 60],
            'metadata leaf' => ['/library/metadata/1', 0, 60],
            'sections list' => ['/library/sections', 0, 60],
            'query'         => ['/library/sections/1/all?X-Plex-Token=evil', 0, 60],
            'dot dot'       => ['/library/sections/1/../../evil', 0, 60],
            'absolute'      => ['https://evil.example.test/library/sections/1/all', 0, 60],
            'empty'         => ['', 0, 60],
            'size 0'        => ['/library/sections/1/all', 0, 0],
            'size 201'      => ['/library/sections/1/all', 0, 201],
            'size negative' => ['/library/sections/1/all', 0, -1],
            'start negative' => ['/library/sections/1/all', -1, 60],
        ];
    }

    public function testChildrenAcceptsTheSizeBounds(): void
    {
        $this->t->respondJson(['MediaContainer' => []])->respondJson(['MediaContainer' => []]);
        $c = $this->client();
        $c->children('/library/sections/1/all', 0, 1);
        $c->children('/library/sections/1/all', 1000000, 200);
        $this->assertStringEndsWith('Start=1000000&X-Plex-Container-Size=200', $this->t->requests[1]['url']);
    }

    public function testChildrenFromADifferentServerThanThePinnedOneIsServerChanged(): void
    {
        $this->t->respond(200, $this->fixture('children-section'));
        $this->expectPlexError(fn () => $this->client('ffff')->children('/library/sections/1/all', 0, 60), PlexException::SERVER_CHANGED);
    }

    public function testChildren401IsTokenRejected(): void
    {
        $this->t->respond(401, 'UPSTREAM-BODY');
        $this->expectPlexError(fn () => $this->client()->children('/library/sections/1/all', 0, 60), PlexException::TOKEN_REJECTED);
    }

    public function testChildrenJunkIsBadJson(): void
    {
        $this->t->respond(200, '<html>UPSTREAM-BODY</html>');
        $this->expectPlexError(fn () => $this->client()->children('/library/sections/1/all', 0, 60), PlexException::BAD_JSON);
    }

    // ── art() (spec §3, S11; rulings B1, B6) ─────────────────────────────────

    private const PNG = "\x89PNG\r\n\x1a\nUPSTREAM-BYTES";

    public function testArtRequestsTheVerandaTranscodeUrlWithTheTokenOnlyInTheHeader(): void
    {
        $this->t->respond(200, self::PNG, ['Content-Type' => 'image/png']);
        $this->client()->art('/library/metadata/101/thumb/1700000000', 144);

        $this->assertCount(1, $this->t->requests);
        [$req] = $this->t->requests;
        $this->assertSame(
            self::BASE . '/photo/:/transcode?width=144&height=144&minSize=1&upscale=0'
                . '&url=%2Flibrary%2Fmetadata%2F101%2Fthumb%2F1700000000',
            $req['url'],
        );
        $this->assertStringNotContainsString(self::TOKEN, $req['url']);
        $hits = array_keys(array_filter($req['headers'], fn ($v) => str_contains((string) $v, self::TOKEN)));
        $this->assertSame(['X-Plex-Token'], $hits);
    }

    public function testArtAt512(): void
    {
        $this->t->respond(200, self::PNG, ['Content-Type' => 'image/png']);
        $this->client()->art('/library/metadata/1/art/2', 512);
        $this->assertStringContainsString('?width=512&height=512&', $this->t->requests[0]['url']);
    }

    /** @dataProvider imageTypes */
    public function testArtReturnsTheRawBodyAndTheNormalisedType(string $header, string $type): void
    {
        $this->t->respond(200, self::PNG, ['Content-Type' => $header]);
        $this->assertSame(['type' => $type, 'body' => self::PNG], $this->client()->art('/library/metadata/1/thumb/2', 144));
    }

    public function imageTypes(): array
    {
        return [
            'jpeg'        => ['image/jpeg', 'image/jpeg'],
            'png'         => ['image/png', 'image/png'],
            'webp'        => ['image/webp', 'image/webp'],
            'upper case'  => ['IMAGE/JPEG', 'image/jpeg'],
            'parameters'  => ['image/png; charset=binary', 'image/png'],
            'spaces'      => [' image/webp ;q=1', 'image/webp'],
        ];
    }

    /** A JSON-looking image body is not decoded, and a pinned client does not inspect it. */
    public function testArtDoesNotDecodeTheBodyOrCheckTheMachine(): void
    {
        $body = (string) json_encode(['MediaContainer' => ['machineIdentifier' => 'someone-else']]);
        $this->t->respond(200, $body, ['Content-Type' => 'image/jpeg']);
        $this->assertSame($body, $this->client(self::MACHINE)->art('/library/metadata/1/thumb/2', 144)['body']);
    }

    /** @dataProvider badImageTypes */
    public function testArtWithANonImageTypeIsBadType(?string $header): void
    {
        $this->t->respond(200, '<svg onload="UPSTREAM-BODY"/>', $header === null ? [] : ['Content-Type' => $header]);
        $this->expectPlexError(fn () => $this->client()->art('/library/metadata/1/thumb/2', 144), PlexException::BAD_TYPE);
    }

    public function badImageTypes(): array
    {
        return [
            'svg'        => ['image/svg+xml'],
            'html'       => ['text/html'],
            'missing'    => [null],
            'empty'      => [''],
            'json'       => ['application/json'],
            'gif'        => ['image/gif'],
            'prefix'     => ['image/pngx'],
            'jpeg,html'  => ['image/jpeg, text/html'],
        ];
    }

    /** @dataProvider badArtArgs */
    public function testArtRefusesBadArgumentsWithNoRequest(string $path, int $size): void
    {
        try {
            $this->client()->art($path, $size);
            $this->fail('expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertMessageIsClean($e);
            $this->assertStringNotContainsString('evil', $e->getMessage());
        }
        $this->assertSame([], $this->t->requests);
    }

    public function badArtArgs(): array
    {
        return [
            'browse path' => ['/library/sections/1/all', 144],
            'query'       => ['/library/metadata/1/thumb/2?X-Plex-Token=evil', 144],
            'absolute'    => ['https://evil.example.test/library/metadata/1/thumb/2', 144],
            'dot dot'     => ['/library/metadata/1/thumb/../../evil', 144],
            'empty'       => ['', 144],
            'size 1080'   => ['/library/metadata/1/thumb/2', 1080],
            'size 143'    => ['/library/metadata/1/thumb/2', 143],
            'size 0'      => ['/library/metadata/1/thumb/2', 0],
        ];
    }

    /** @dataProvider statuses */
    public function testArtStatusMapping(int $status, string $reason): void
    {
        $this->t->respond($status, 'UPSTREAM-BODY ' . self::TOKEN, ['Content-Type' => 'image/png']);
        $e = $this->expectPlexError(fn () => $this->client()->art('/library/metadata/1/thumb/2', 144), $reason);
        $this->assertSame($status, $e->status);
    }

    public function testArtTransportFailuresAreFixed(): void
    {
        $this->t->fail(new \RuntimeException('connect ' . self::BASE . '/photo?X-Plex-Token=' . self::TOKEN))
            ->fail(PlexException::tooLarge());
        $c = $this->client();
        $this->expectPlexError(fn () => $c->art('/library/metadata/1/thumb/2', 144), PlexException::UNREACHABLE);
        $this->expectPlexError(fn () => $c->art('/library/metadata/1/thumb/2', 144), PlexException::TOO_LARGE);
    }

    public function testArtOver2MiBIsTooLarge(): void
    {
        $this->t->respond(200, str_repeat('x', 2 * 1024 * 1024 + 1), ['Content-Type' => 'image/png']);
        $this->expectPlexError(fn () => $this->client()->art('/library/metadata/1/thumb/2', 512), PlexException::TOO_LARGE);
    }

    // ── PlexResponse ─────────────────────────────────────────────────────────

    public function testPlexResponseKeepsOnlyAllowListedHeadersLowercased(): void
    {
        $r = new PlexResponse(200, 'b', [
            'Content-Type'     => 'application/json',
            'CONTENT-LENGTH'   => '1',
            'content-range'    => 'bytes 0-0/1',
            'Accept-Ranges'    => 'bytes',
            'Set-Cookie'       => 'x=y',
            'X-Plex-Token'     => self::TOKEN,
            'Location'         => self::BASE . '/?X-Plex-Token=' . self::TOKEN,
        ]);
        $this->assertSame(200, $r->status);
        $this->assertSame('b', $r->body);
        $this->assertSame([
            'content-type'   => 'application/json',
            'content-length' => '1',
            'content-range'  => 'bytes 0-0/1',
            'accept-ranges'  => 'bytes',
        ], $r->headers);
    }

    // ── deviceName() (S14) ───────────────────────────────────────────────────

    /** @dataProvider deviceNames */
    public function testDeviceName(string $username, int $id, string $expected): void
    {
        $this->assertSame($expected, PlexClient::deviceName($username, $id));
    }

    public function deviceNames(): array
    {
        return [
            'plain'              => ['alice', 3, 'Matinee (alice)'],
            'allowed punctuation' => ['a.b_c-d e', 3, 'Matinee (a.b_c-d e)'],
            'CRLF injection'     => ["bob\r\nX-Plex-Token: x", 4, 'Matinee (bobX-Plex-Token x)'],
            'unicode dropped'    => ['José Ñúñez', 5, 'Matinee (Jos ez)'],
            'only unicode'       => ['日本語', 6, 'Matinee (user #6)'],
            'empty'              => ['', 7, 'Matinee (user #7)'],
            'only spaces'        => ['   ', 8, 'Matinee (user #8)'],
            'only symbols'       => ['<>()@!', 9, 'Matinee (user #9)'],
            'trimmed'            => ['  carol  ', 2, 'Matinee (carol)'],
            '40 chars kept'      => [str_repeat('x', 40), 1, 'Matinee (' . str_repeat('x', 40) . ')'],
            '41 chars truncated' => [str_repeat('y', 41), 1, 'Matinee (' . str_repeat('y', 40) . ')'],
            'email-ish'          => ['dan@example.test', 10, 'Matinee (danexample.test)'],
        ];
    }

    public function testADeviceNameFeedsTheHeader(): void
    {
        $this->t->respond(200, $this->fixture('identity'));
        (new PlexClient(self::BASE, self::TOKEN, self::CLIENT_ID, $this->t, null, PlexClient::deviceName("e\r\nvil", 1)))->identity();
        $this->assertSame('Matinee (evil)', $this->t->requests[0]['headers']['X-Plex-Device-Name']);
    }
}
