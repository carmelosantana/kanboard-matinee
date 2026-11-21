<?php

require_once __DIR__ . '/support/FakeTransport.php';
require_once __DIR__ . '/support/RecordingSink.php';

use Kanboard\Plugin\Matinee\Http\ByteRange;
use Kanboard\Plugin\Matinee\Http\PlexClient;
use Kanboard\Plugin\Matinee\Http\PlexException;
use Kanboard\Plugin\Matinee\Http\PlexResponse;
use Kanboard\Plugin\Matinee\Http\UnsafeUrlException;
use Kanboard\Plugin\Matinee\Test\FakeTransport;
use Kanboard\Plugin\Matinee\Test\RecordingSink;
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

    /**
     * L1: ~262k tiny objects fit under the 2 MiB cap but decode to ~106 MiB. Too many JSON containers is refused
     * before json_decode, on every JSON path (children, sections, identity), with memory growth kept small.
     */
    public function testAJsonBodyWithTooManyContainersIsTooLargeBeforeItIsDecoded(): void
    {
        $head = '{"MediaContainer":{"Metadata":[';
        $n = intdiv(PlexClient::MAX_BODY_BYTES - strlen($head) - 3, 8);
        $body = $head . rtrim(str_repeat('{"a":1},', $n), ',') . ']}}';
        $this->assertLessThanOrEqual(PlexClient::MAX_BODY_BYTES, strlen($body));

        foreach ([
            'children' => fn () => $this->client()->children('/library/sections/1/all', 0, 60),
            'sections' => fn () => $this->client()->sections(),
            'identity' => fn () => $this->client()->identity(),
        ] as $label => $call) {
            $this->t->respond(200, $body);
            $before = memory_get_usage();
            gc_collect_cycles();
            memory_reset_peak_usage();
            $this->expectPlexError($call, PlexException::TOO_LARGE);
            $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $before, $label . ': decoded anyway');
        }
    }

    public function testAJsonBodyAtTheContainerCapIsDecoded(): void
    {
        $items = rtrim(str_repeat('{"ratingKey":"1","type":"movie"},', PlexClient::MAX_JSON_CONTAINERS - 3), ',');
        $this->t->respond(200, '{"MediaContainer":{"Metadata":[' . $items . ']}}');
        $this->assertCount(60, $this->client()->children('/library/sections/1/all', 0, 60)['items']);

        $this->t->respond(200, '{"MediaContainer":{"Metadata":[' . $items . ',{}]}}');
        $this->expectPlexError(fn () => $this->client()->children('/library/sections/1/all', 0, 60), PlexException::TOO_LARGE);
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
            PlexException::badRange(),
            PlexException::badPlaylist(),
        ];
        $this->assertSame(
            [
                'plex.unreachable', 'plex.http_error', 'plex.token_rejected', 'plex.bad_json', 'plex.server_changed',
                'plex.too_large', 'plex.token_in_uri', 'plex.bad_type', 'plex.bad_range', 'plex.bad_playlist',
            ],
            array_map(fn (PlexException $e) => $e->getMessage(), $all),
        );
        $this->assertSame([0, 503, 401, 0, 0, 0, 0, 0, 0, 0], array_map(fn (PlexException $e) => $e->status, $all));
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

    /** Art is never decoded, so the JSON container cap does not apply: only the 2 MiB transport cap does. */
    public function testArtUpTo2MiBIsNotHeldToTheJsonContainerCap(): void
    {
        $body = str_repeat('{[', 1024 * 1024);
        $this->t->respond(200, $body, ['Content-Type' => 'image/png']);
        $this->assertSame($body, $this->client()->art('/library/metadata/1/thumb/2', 512)['body']);
    }

    // ── item() (P3 Task 8; spec §7, S13; C1a) ────────────────────────────────

    /** @dataProvider itemFixtures */
    public function testItemParsesTheWhitelistedFields(string $fixture, string $path, array $expected): void
    {
        $this->t->respond(200, $this->fixture($fixture));
        $this->assertSame($expected, $this->client(self::MACHINE)->item($path));
        $this->assertSame(self::BASE . $path, $this->t->requests[0]['url']);
    }

    public function itemFixtures(): array
    {
        return [
            'mp3 track' => ['item-track-mp3', '/library/metadata/3001', [
                'section' => '3', 'ratingKey' => '3001', 'type' => 'track', 'title' => 'Fake Song',
                'parentTitle' => 'Fake Album', 'grandparentTitle' => 'Fake Artist', 'duration' => 215000,
                'container' => 'mp3', 'audioCodec' => 'mp3', 'videoCodec' => null,
                'part' => '/library/parts/5001/1700000000/file.mp3',
            ]],
            'flac track' => ['item-track-flac', '/library/metadata/3002', [
                'section' => '3', 'ratingKey' => '3002', 'type' => 'track', 'title' => 'Fake Lossless',
                'parentTitle' => 'Fake Album', 'grandparentTitle' => 'Fake Artist', 'duration' => 301000,
                'container' => 'flac', 'audioCodec' => 'flac', 'videoCodec' => null,
                'part' => '/library/parts/5002/1700000001/file.flac',
            ]],
            'h263 track' => ['item-track-h263', '/library/metadata/3003', [
                'section' => '3', 'ratingKey' => '3003', 'type' => 'track', 'title' => 'Fake Video Track',
                'parentTitle' => 'Fake Album', 'grandparentTitle' => 'Fake Artist', 'duration' => 99000,
                'container' => 'mp4', 'audioCodec' => 'aac', 'videoCodec' => 'h263',
                'part' => '/library/parts/5003/1700000002/file.mp4',
            ]],
            'avi movie' => ['item-movie-avi', '/library/metadata/2229', [
                'section' => '1', 'ratingKey' => '2229', 'type' => 'movie', 'title' => 'Fake Old Movie',
                'parentTitle' => '', 'grandparentTitle' => '', 'duration' => 5700000,
                'container' => 'avi', 'audioCodec' => 'mp3', 'videoCodec' => 'mpeg4',
                'part' => '/library/parts/2229/1250471640/file.avi',
            ]],
            'no section' => ['item-no-section', '/library/metadata/3001', [
                'section' => null, 'ratingKey' => '3001', 'type' => 'track', 'title' => 'Fake Song',
                'parentTitle' => '', 'grandparentTitle' => '', 'duration' => null,
                'container' => 'mp3', 'audioCodec' => 'mp3', 'videoCodec' => null,
                'part' => '/library/parts/5001/1700000000/file.mp3',
            ]],
        ];
    }

    private static function itemJson(array $meta, array $mc = ['librarySectionID' => 3]): string
    {
        return (string) json_encode(['MediaContainer' => $mc + ['Metadata' => [$meta]]]);
    }

    public function testItemWithAMismatchedRatingKeyIsBadJson(): void
    {
        $this->t->respond(200, $this->fixture('item-track-mp3'));
        $this->expectPlexError(fn () => $this->client()->item('/library/metadata/3999'), PlexException::BAD_JSON);
    }

    /**
     * A malformed container or audio codec is ''; a malformed video codec is '?', so it can never read as "no video"
     * (absent, which is null).
     *
     * @dataProvider badCodecs
     */
    public function testItemCodecsOutsideTheShapeAreEmptyOrAnUnknownVideoCodec(mixed $codec): void
    {
        $this->t->respond(200, self::itemJson(['ratingKey' => '7', 'type' => 'track', 'Media' => [[
            'container' => $codec, 'audioCodec' => $codec, 'videoCodec' => $codec,
            'Part' => [['key' => '/library/parts/1/2/file.mp3']],
        ]]]));
        $item = $this->client()->item('/library/metadata/7');
        $this->assertSame(['', '', '?'], [$item['container'], $item['audioCodec'], $item['videoCodec']]);
    }

    /** @dataProvider absentVideoCodecs */
    public function testAnAbsentVideoCodecIsNull(array $media): void
    {
        $this->t->respond(200, self::itemJson(['ratingKey' => '7', 'type' => 'track', 'Media' => [$media + [
            'container' => 'mp3', 'audioCodec' => 'mp3', 'Part' => [['key' => '/library/parts/1/2/file.mp3']],
        ]]]));
        $this->assertNull($this->client()->item('/library/metadata/7')['videoCodec']);
    }

    public function absentVideoCodecs(): array
    {
        return ['missing' => [[]], 'json null' => [['videoCodec' => null]]];
    }

    public function badCodecs(): array
    {
        return [
            'trailing space' => ['MP3 '],
            'slash'          => ['x/y'],
            'too long'       => [str_repeat('a', 17)],
            'empty'          => [''],
            'number'         => [3],
            'array'          => [['mp3']],
            'underscore'     => ['amr_nb'],
            'hyphen'         => ['h264-main'],
        ];
    }

    /** @dataProvider badParts */
    public function testItemPartIsNullUnlessItIsAPartPath(mixed $media): void
    {
        $meta = ['ratingKey' => '7', 'type' => 'track'] + ($media === 'absent' ? [] : ['Media' => $media]);
        $this->t->respond(200, self::itemJson($meta));
        $item = $this->client()->item('/library/metadata/7');
        $this->assertNull($item['part']);
    }

    public function badParts(): array
    {
        return [
            'no media'      => ['absent'],
            'media not list' => ['x'],
            'no part'       => [[['container' => 'mp3']]],
            'absolute'      => [[['Part' => [['key' => 'https://evil.example.test/library/parts/1/2/file.mp3']]]]],
            'with query'    => [[['Part' => [['key' => '/library/parts/1/2/file.mp3?X-Plex-Token=x']]]]],
            'dot dot'       => [[['Part' => [['key' => '/library/parts/1/2/../file.mp3']]]]],
            'int key'       => [[['Part' => [['key' => 5]]]]],
        ];
    }

    /** @dataProvider malformedItems */
    public function testMalformedItemsAreBadJson(string $body): void
    {
        $this->t->respond(200, $body);
        $this->expectPlexError(fn () => $this->client()->item('/library/metadata/7'), PlexException::BAD_JSON);
    }

    public function malformedItems(): array
    {
        return [
            'no metadata'    => [(string) json_encode(['MediaContainer' => ['librarySectionID' => 3]])],
            'empty metadata' => [(string) json_encode(['MediaContainer' => ['Metadata' => []]])],
            'metadata map'   => [(string) json_encode(['MediaContainer' => ['Metadata' => ['a' => ['ratingKey' => '7', 'type' => 'track']]]])],
            'entry scalar'   => [(string) json_encode(['MediaContainer' => ['Metadata' => ['x']]])],
            'no ratingKey'   => [self::itemJson(['type' => 'track'])],
            'word ratingKey' => [self::itemJson(['ratingKey' => 'seven', 'type' => 'track'])],
            'padded ratingKey' => [self::itemJson(['ratingKey' => '07', 'type' => 'track'])],
            'bad type'       => [self::itemJson(['ratingKey' => '7', 'type' => 'clip'])],
            'no type'        => [self::itemJson(['ratingKey' => '7'])],
            'not json'       => ['<html>UPSTREAM-BODY</html>'],
        ];
    }

    /** @dataProvider badItemPaths */
    public function testItemRefusesANonItemPathWithNoRequest(string $path): void
    {
        try {
            $this->client()->item($path);
            $this->fail('expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertMessageIsClean($e);
        }
        $this->assertSame([], $this->t->requests);
    }

    public function badItemPaths(): array
    {
        return [
            'children' => ['/library/metadata/7/children'],
            'part'     => ['/library/parts/1/2/file.mp3'],
            'query'    => ['/library/metadata/7?X-Plex-Token=evil'],
            'absolute' => ['https://evil.example.test/library/metadata/7'],
            'empty'    => [''],
        ];
    }

    public function testItemFromAnotherServerIsServerChanged(): void
    {
        $this->t->respond(200, self::itemJson(['ratingKey' => '7', 'type' => 'track'], ['machineIdentifier' => 'someone-else']));
        $this->expectPlexError(fn () => $this->client(self::MACHINE)->item('/library/metadata/7'), PlexException::SERVER_CHANGED);
    }

    public function testItem401IsTokenRejected(): void
    {
        $this->t->respond(401, 'UPSTREAM-BODY');
        $this->expectPlexError(fn () => $this->client()->item('/library/metadata/7'), PlexException::TOKEN_REJECTED);
    }

    // ── part() (P3 Task 8; C1b, C1d, C3a, C3b) ───────────────────────────────

    private const MP3_PART = '/library/parts/5001/1700000000/file.mp3';

    private function sink(bool $refuse = false, ?int $stopAfter = null): RecordingSink
    {
        return new RecordingSink($refuse, $stopAfter);
    }

    private function assertOnlyHeaderCarriesTheToken(array $req): void
    {
        $this->assertStringNotContainsString(self::TOKEN, $req['url']);
        $this->assertStringNotContainsStringIgnoringCase('token', $req['url']);
        $hits = array_keys(array_filter($req['headers'], fn ($v) => str_contains((string) $v, self::TOKEN)));
        $this->assertSame(['X-Plex-Token'], $hits);
    }

    public function testPartRequestsTheRangeWithTheTokenOnlyInTheHeaderAndTheS3Opts(): void
    {
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-999/5000'], [str_repeat('a', 1000)]);
        $this->client()->part(self::MP3_PART, 0, 999, $this->sink());

        $this->assertCount(1, $this->t->requests);
        [$req] = $this->t->requests;
        $this->assertSame(self::BASE . self::MP3_PART, $req['url']);
        $this->assertSame('bytes=0-999', $req['headers']['Range']);
        $this->assertOnlyHeaderCarriesTheToken($req);
        $this->assertSame(['timeout' => 60, 'lowSpeedLimit' => 1024, 'lowSpeedTime' => 15, 'maxBytes' => ByteRange::WINDOW], $req['opts']);
    }

    public function testA206IsRebuiltFromTheValidatedRange(): void
    {
        $sink = $this->sink();
        $this->t->streams(206, [
            'Content-Type' => 'audio/mpeg; charset=binary', 'Content-Range' => 'bytes 0-999/5000',
            'Content-Length' => '1000', 'Accept-Ranges' => 'none',
        ], ['abc', 'def']);
        $result = $this->client()->part(self::MP3_PART, 0, 999, $sink);

        $this->assertSame(['status' => 206, 'total' => 5000], $result);
        $this->assertSame(206, $sink->status);
        $this->assertSame([
            'content-type'   => 'audio/mpeg',
            'content-range'  => 'bytes 0-999/5000',
            'content-length' => '1000',
            'accept-ranges'  => 'bytes',
        ], $sink->headers);
        $this->assertSame('abcdef', $sink->body);
    }

    public function testAShorterUpstreamRangeSetsTheLength(): void
    {
        $sink = $this->sink();
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 100-149/150'], ['x']);
        $this->client()->part(self::MP3_PART, 100, 999, $sink);
        $this->assertSame('bytes 100-149/150', $sink->headers['content-range']);
        $this->assertSame('50', $sink->headers['content-length']);
    }

    /** @dataProvider partTypes */
    public function testPartTypesAreCanonical(string $path, string $upstream, string $sent): void
    {
        $sink = $this->sink();
        $this->t->streams(206, ['Content-Type' => $upstream, 'Content-Range' => 'bytes 0-9/10'], ['0123456789']);
        $this->client()->part($path, 0, 9, $sink);
        $this->assertSame($sent, $sink->headers['content-type']);
    }

    public function partTypes(): array
    {
        return [
            'mp3'          => [self::MP3_PART, 'audio/mpeg', 'audio/mpeg'],
            'flac'         => ['/library/parts/1/2/file.flac', 'audio/flac', 'audio/flac'],
            'x-flac'       => ['/library/parts/1/2/file.flac', 'audio/x-flac', 'audio/flac'],
            'm4a mp4'      => ['/library/parts/1/2/file.m4a', 'audio/mp4', 'audio/mp4'],
            'm4a x-m4a'    => ['/library/parts/1/2/file.m4a', 'audio/x-m4a', 'audio/mp4'],
            'mp4 x-m4a'    => ['/library/parts/1/2/file.mp4', 'AUDIO/X-M4A', 'audio/mp4'],
        ];
    }

    /** @dataProvider badPartTypes */
    public function testAPartTypeOutsideItsContainerListIsBadType(string $path, ?string $upstream): void
    {
        $sink = $this->sink();
        $this->t->streams(206, ($upstream === null ? [] : ['Content-Type' => $upstream]) + ['Content-Range' => 'bytes 0-9/10'], ['<html>UPSTREAM-BODY']);
        $this->expectPlexError(fn () => $this->client()->part($path, 0, 9, $sink), PlexException::BAD_TYPE);
        $this->assertSame(0, $sink->opens);
        $this->assertSame('', $sink->body);
    }

    public function badPartTypes(): array
    {
        return [
            'html'        => [self::MP3_PART, 'text/html'],
            'missing'     => [self::MP3_PART, null],
            'octet'       => [self::MP3_PART, 'application/octet-stream'],
            'flac as mp3' => [self::MP3_PART, 'audio/flac'],
            'mp3 as flac' => ['/library/parts/1/2/file.flac', 'audio/mpeg'],
            'ogg'         => ['/library/parts/1/2/file.m4a', 'audio/ogg'],
        ];
    }

    public function testAPartWithAnotherExtensionIsBadTypeWithNoRequest(): void
    {
        $this->expectPlexError(fn () => $this->client()->part('/library/parts/1/2/file.ogg', 0, 9, $this->sink()), PlexException::BAD_TYPE);
        $this->assertSame([], $this->t->requests);
    }

    public function testASmallWholeFile200BecomesA206(): void
    {
        $sink = $this->sink();
        $this->t->streams(200, ['Content-Type' => 'audio/mpeg', 'Content-Length' => '700'], [str_repeat('z', 700)]);
        $this->assertSame(['status' => 206, 'total' => 700], $this->client()->part(self::MP3_PART, 0, 999, $sink));
        $this->assertSame(206, $sink->status);
        $this->assertSame([
            'content-type'   => 'audio/mpeg',
            'content-range'  => 'bytes 0-699/700',
            'content-length' => '700',
            'accept-ranges'  => 'bytes',
        ], $sink->headers);
        $this->assertSame(700, strlen($sink->body));
    }

    public function testA200TheSizeOfTheWindowIsAccepted(): void
    {
        $sink = $this->sink();
        $this->t->streams(200, ['Content-Type' => 'audio/mpeg', 'Content-Length' => '1000'], ['x']);
        $this->client()->part(self::MP3_PART, 0, 999, $sink);
        $this->assertSame('bytes 0-999/1000', $sink->headers['content-range']);
    }

    /** @dataProvider bad200s */
    public function testOther200sAreHttpErrorsAndTheSinkNeverOpens(int $start, ?string $length): void
    {
        $sink = $this->sink();
        $this->t->streams(200, ['Content-Type' => 'audio/mpeg'] + ($length === null ? [] : ['Content-Length' => $length]), ['UPSTREAM-BODY']);
        $e = $this->expectPlexError(fn () => $this->client()->part(self::MP3_PART, $start, 999, $sink), PlexException::HTTP_ERROR);
        $this->assertSame(200, $e->status);
        $this->assertSame(0, $sink->opens);
        $this->assertSame('', $sink->body);
    }

    public function bad200s(): array
    {
        return [
            'not from 0'      => [100, '700'],
            'longer than window' => [0, (string) (ByteRange::WINDOW + 1)],
            'no length'       => [0, null],
            'zero length'     => [0, '0'],
            'not a number'    => [0, '7e2'],
            'signed'          => [0, '+700'],
            '19 digits'       => [0, '1000000000000000000'],
        ];
    }

    /** @dataProvider badUpstreamRanges */
    public function testA206WhoseRangeDoesNotFitIsBadRange(?string $range): void
    {
        $sink = $this->sink();
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg'] + ($range === null ? [] : ['Content-Range' => $range]), ['UPSTREAM-BODY']);
        $this->expectPlexError(fn () => $this->client()->part(self::MP3_PART, 100, 999, $sink), PlexException::BAD_RANGE);
        $this->assertSame(0, $sink->opens);
    }

    public function badUpstreamRanges(): array
    {
        return [
            'start moved'  => ['bytes 0-999/5000'],
            'past the end' => ['bytes 100-1000/5000'],
            'no total'     => ['bytes 100-999/*'],
            'missing'      => [null],
        ];
    }

    public function testAnUpstream416GivesItsTotalAndLeavesTheSinkUnopened(): void
    {
        $sink = $this->sink();
        $this->t->streams(416, ['Content-Range' => 'bytes */5000', 'Content-Type' => 'text/html'], ['UPSTREAM-BODY']);
        $this->assertSame(['status' => 416, 'total' => 5000], $this->client()->part(self::MP3_PART, 9000, 9999, $sink));
        $this->assertSame(0, $sink->opens);
        $this->assertSame('', $sink->body);
    }

    public function testAnUpstream416WithNoTotalIsBadRange(): void
    {
        $sink = $this->sink();
        $this->t->streams(416, ['Content-Range' => 'bytes 0-1/2'], []);
        $this->expectPlexError(fn () => $this->client()->part(self::MP3_PART, 9000, 9999, $sink), PlexException::BAD_RANGE);
        $this->assertSame(0, $sink->opens);
    }

    /** @dataProvider statuses */
    public function testPartStatusMapping(int $status, string $reason): void
    {
        $sink = $this->sink();
        $this->t->streams($status, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-9/10'], ['UPSTREAM-BODY ' . self::TOKEN]);
        $e = $this->expectPlexError(fn () => $this->client()->part(self::MP3_PART, 0, 9, $sink), $reason);
        $this->assertSame($status, $e->status);
        $this->assertSame(0, $sink->opens);
    }

    /** The exact maxBytes cut (C3a): 1000 bytes reach the sink, then too_large, with the sink already open. */
    public function testAnOverlongUpstreamWritesExactlyTheWindowThenIsTooLarge(): void
    {
        $sink = $this->sink();
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-999/5000'], [str_repeat('a', 600), str_repeat('b', 900)]);
        $this->expectPlexError(fn () => $this->client()->part(self::MP3_PART, 0, 999, $sink), PlexException::TOO_LARGE);
        $this->assertSame(1, $sink->opens);
        $this->assertSame(1000, strlen($sink->body));
    }

    /** H1: a 206 is cut at its validated length e - s + 1, not at the window. */
    public function testA206BodyPastItsContentRangeWritesExactlyThatLengthThenIsTooLarge(): void
    {
        $sink = $this->sink();
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-499/5000'], [str_repeat('a', 1000)]);
        $this->expectPlexError(fn () => $this->client()->part(self::MP3_PART, 0, 999, $sink), PlexException::TOO_LARGE);
        $this->assertSame(1, $sink->opens);
        $this->assertSame('500', $sink->headers['content-length']);
        $this->assertSame(500, strlen($sink->body));
    }

    public function testTheCutCountsAcrossChunks(): void
    {
        $sink = $this->sink();
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 100-599/5000'],
            [str_repeat('a', 300), str_repeat('b', 200), 'c']);
        $this->expectPlexError(fn () => $this->client()->part(self::MP3_PART, 100, 999, $sink), PlexException::TOO_LARGE);
        $this->assertSame(str_repeat('a', 300) . str_repeat('b', 200), $sink->body);
    }

    public function testABodyOfExactlyTheValidatedLengthEndsNormally(): void
    {
        $sink = $this->sink();
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-499/5000'], [str_repeat('a', 250), str_repeat('b', 250)]);
        $this->assertSame(['status' => 206, 'total' => 5000], $this->client()->part(self::MP3_PART, 0, 999, $sink));
        $this->assertSame(500, strlen($sink->body));
    }

    /**
     * H1: a 206 whose upstream Content-Length is present and is not e - s + 1 is refused before the sink opens.
     *
     * @dataProvider mismatchedLengths
     */
    public function testA206WhoseContentLengthIsNotItsRangeIsBadRange(string $length): void
    {
        $sink = $this->sink();
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-499/5000', 'Content-Length' => $length], [str_repeat('a', 500)]);
        $this->expectPlexError(fn () => $this->client()->part(self::MP3_PART, 0, 999, $sink), PlexException::BAD_RANGE);
        $this->assertSame(0, $sink->opens);
        $this->assertSame('', $sink->body);
    }

    public function mismatchedLengths(): array
    {
        return [
            'the window' => ['1000'],
            'one short'  => ['499'],
            'one over'   => ['501'],
            'junk'       => ['5e2'],
            'empty'      => [''],
        ];
    }

    /** H1: Safari's `bytes=0-1` probe of a 4 KB file Plex answers whole is still served, cut at the file's length. */
    public function testSafarisTwoByteProbeAnsweredWithA200IsTheWholeSmallFile(): void
    {
        $sink = $this->sink();
        $this->t->streams(200, ['Content-Type' => 'audio/mpeg', 'Content-Length' => '4096'], [str_repeat('z', 4096)]);
        $this->assertSame(['status' => 206, 'total' => 4096], $this->client()->part(self::MP3_PART, 0, 1, $sink));
        $this->assertSame('bytes 0-4095/4096', $sink->headers['content-range']);
        $this->assertSame('4096', $sink->headers['content-length']);
        $this->assertSame(4096, strlen($sink->body));
    }

    public function testA200BodyPastItsLengthWritesExactlyThatLengthThenIsTooLarge(): void
    {
        $sink = $this->sink();
        $this->t->streams(200, ['Content-Type' => 'audio/mpeg', 'Content-Length' => '700'], [str_repeat('z', 650), str_repeat('y', 100)]);
        $this->expectPlexError(fn () => $this->client()->part(self::MP3_PART, 0, 999, $sink), PlexException::TOO_LARGE);
        $this->assertSame(700, strlen($sink->body));
    }

    public function testASinkThatStopsEndsThePartNormally(): void
    {
        $sink = $this->sink(false, 1);
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-9/10'], ['01234', '56789']);
        $this->assertSame(['status' => 206, 'total' => 10], $this->client()->part(self::MP3_PART, 0, 9, $sink));
        $this->assertSame('01234', $sink->body);
    }

    /** @dataProvider badWindows */
    public function testPartRefusesABadWindowWithNoRequest(string $path, int $start, int $end): void
    {
        try {
            $this->client()->part($path, $start, $end, $this->sink());
            $this->fail('expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertMessageIsClean($e);
        }
        $this->assertSame([], $this->t->requests);
    }

    public function badWindows(): array
    {
        return [
            'item path'     => ['/library/metadata/7', 0, 9],
            'query'         => [self::MP3_PART . '?X-Plex-Token=evil', 0, 9],
            'absolute'      => ['https://evil.example.test' . self::MP3_PART, 0, 9],
            'negative'      => [self::MP3_PART, -1, 9],
            'end < start'   => [self::MP3_PART, 10, 9],
            'window + 1'    => [self::MP3_PART, 0, ByteRange::WINDOW],
            'late window + 1' => [self::MP3_PART, 5, 5 + ByteRange::WINDOW],
        ];
    }

    public function testAFullWindowIsAccepted(): void
    {
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-9/10'], ['0123456789']);
        $this->client()->part(self::MP3_PART, 0, ByteRange::WINDOW - 1, $this->sink());
        $this->assertSame(ByteRange::WINDOW, $this->t->requests[0]['opts']['maxBytes']);
        $this->assertSame('bytes=0-' . (ByteRange::WINDOW - 1), $this->t->requests[0]['headers']['Range']);
    }

    public function testPartTransportFailuresAreFixed(): void
    {
        $this->t->fail(new \RuntimeException('connect ' . self::BASE . self::MP3_PART . '?X-Plex-Token=' . self::TOKEN))
            ->fail(PlexException::unreachable());
        $c = $this->client();
        $this->expectPlexError(fn () => $c->part(self::MP3_PART, 0, 9, $this->sink()), PlexException::UNREACHABLE);
        $this->expectPlexError(fn () => $c->part(self::MP3_PART, 0, 9, $this->sink()), PlexException::UNREACHABLE);
    }

    // ── musicTranscode() (P3 Task 8; C1c, C2f, C3b) ──────────────────────────

    private const SESSION = '0f1e2d3c-4b5a-4987-8a6b-5c4d3e2f1a0b';

    public function testMusicTranscodeRequestsTheMp3StartUrlWithTheS3Opts(): void
    {
        $this->t->streams(200, ['Content-Type' => 'audio/mpeg'], ['ID3']);
        $this->client()->musicTranscode('/library/metadata/3003', self::SESSION, $this->sink());

        [$req] = $this->t->requests;
        $this->assertSame(
            self::BASE . '/music/:/transcode/universal/start.mp3?path=%2Flibrary%2Fmetadata%2F3003&mediaIndex=0'
                . '&partIndex=0&musicBitrate=320&protocol=http&X-Plex-Platform=Chrome&session=' . self::SESSION,
            $req['url'],
        );
        $this->assertSame(['timeout' => 900, 'lowSpeedLimit' => 1024, 'lowSpeedTime' => 15, 'maxBytes' => 67108864], $req['opts']);
        $this->assertOnlyHeaderCarriesTheToken($req);
        $this->assertArrayNotHasKey('Range', $req['headers']);
    }

    public function testMusicTranscodeOpensTheSinkWithExactlyTheTypeAndNoRanges(): void
    {
        $sink = $this->sink();
        $this->t->streams(200, [
            'Content-Type' => 'Audio/MPEG; x=1', 'Content-Length' => '12345', 'Accept-Ranges' => 'bytes', 'Content-Range' => 'bytes 0-1/2',
        ], ['ID3', 'more']);
        $this->client()->musicTranscode('/library/metadata/3003', self::SESSION, $sink);
        $this->assertSame(200, $sink->status);
        $this->assertSame(['content-type' => 'audio/mpeg', 'accept-ranges' => 'none'], $sink->headers);
        $this->assertSame('ID3more', $sink->body);
    }

    /** @dataProvider badTranscodeTypes */
    public function testMusicTranscodeOfAnotherTypeIsBadType(?string $type): void
    {
        $sink = $this->sink();
        $this->t->streams(200, $type === null ? [] : ['Content-Type' => $type], ['UPSTREAM-BODY']);
        $this->expectPlexError(fn () => $this->client()->musicTranscode('/library/metadata/3003', self::SESSION, $sink), PlexException::BAD_TYPE);
        $this->assertSame(0, $sink->opens);
    }

    public function badTranscodeTypes(): array
    {
        return ['ogg' => ['audio/ogg'], 'html' => ['text/html'], 'missing' => [null], 'flac' => ['audio/flac']];
    }

    /** @dataProvider statuses */
    public function testMusicTranscodeStatusMapping(int $status, string $reason): void
    {
        $sink = $this->sink();
        $this->t->streams($status, ['Content-Type' => 'audio/mpeg'], ['UPSTREAM-BODY ' . self::TOKEN]);
        $e = $this->expectPlexError(fn () => $this->client()->musicTranscode('/library/metadata/3003', self::SESSION, $sink), $reason);
        $this->assertSame($status, $e->status);
        $this->assertSame(0, $sink->opens);
    }

    /** Counts bytes instead of keeping them, so the suite stays inside a 128 MiB memory_limit. */
    public function testMusicTranscodeOver64MiBIsTooLargeAfterTheCut(): void
    {
        $sink = new class () implements \Kanboard\Plugin\Matinee\Http\PlexStreamSink {
            public int $bytes = 0;

            public function open(int $status, array $headers): bool
            {
                return true;
            }

            public function write(string $chunk): bool
            {
                $this->bytes += strlen($chunk);
                return true;
            }
        };
        $chunk = str_repeat('m', 16 * 1024 * 1024);
        $this->t->streams(200, ['Content-Type' => 'audio/mpeg'], [$chunk, $chunk, $chunk, $chunk, 'x']);
        $this->expectPlexError(fn () => $this->client()->musicTranscode('/library/metadata/3003', self::SESSION, $sink), PlexException::TOO_LARGE);
        $this->assertSame(67108864, $sink->bytes);
    }

    /** @dataProvider badTranscodeArgs */
    public function testMusicTranscodeRefusesBadArgumentsWithNoRequest(string $path, string $session): void
    {
        try {
            $this->client()->musicTranscode($path, $session, $this->sink());
            $this->fail('expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertMessageIsClean($e);
        }
        $this->assertSame([], $this->t->requests);
    }

    public function badTranscodeArgs(): array
    {
        return [
            'part path'     => [self::MP3_PART, self::SESSION],
            'children'      => ['/library/metadata/1/children', self::SESSION],
            'query'         => ['/library/metadata/1?X-Plex-Token=evil', self::SESSION],
            'upper session' => ['/library/metadata/1', strtoupper(self::SESSION)],
            'v1 session'    => ['/library/metadata/1', '0f1e2d3c-4b5a-1987-8a6b-5c4d3e2f1a0b'],
            'empty session' => ['/library/metadata/1', ''],
            'session &'     => ['/library/metadata/1', self::SESSION . '&X-Plex-Token=x'],
        ];
    }

    /** Across every streamed call: the token never reaches a URL, an exception message or a sink header. */
    public function testTheTokenNeverLeavesTheHeaderOnStreamedCalls(): void
    {
        $sinks = [$this->sink(), $this->sink(), $this->sink()];
        $this->t->streams(206, ['Content-Type' => 'audio/mpeg', 'Content-Range' => 'bytes 0-9/10'], ['0123456789'])
            ->streams(200, ['Content-Type' => 'audio/mpeg'], ['ID3'])
            ->streams(401, ['Content-Type' => 'text/plain'], [self::TOKEN]);
        $c = $this->client();
        $c->part(self::MP3_PART, 0, 9, $sinks[0]);
        $c->musicTranscode('/library/metadata/3003', self::SESSION, $sinks[1]);
        $this->expectPlexError(fn () => $c->musicTranscode('/library/metadata/3003', self::SESSION, $sinks[2]), PlexException::TOKEN_REJECTED);

        foreach ($this->t->requests as $req) {
            $this->assertOnlyHeaderCarriesTheToken($req);
        }
        foreach ($sinks as $s) {
            $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($s->headers));
            $this->assertStringNotContainsString(self::TOKEN, $s->body);
        }
    }

    // ── HLS: hlsStart(), hlsPlaylist(), hlsSegment(), hlsStop() (P3 Task 12; C2a, C2b, C2f, C3b) ─────────

    private const HLS_BASE = '/video/:/transcode/universal/session/' . self::SESSION . '/base';
    private const OTHER_SESSION = '11111111-2222-4333-8444-555566667777';

    /** C2b: Veranda's transcode headers, Matinee's product and the viewer's device name; the token in the header only. */
    private function hlsHeaders(): array
    {
        return [
            'X-Plex-Token'              => self::TOKEN,
            'X-Plex-Client-Identifier'  => self::CLIENT_ID,
            'X-Plex-Product'            => 'Matinee',
            'X-Plex-Version'            => '0.1',
            'X-Plex-Platform'           => 'Chrome',
            'X-Plex-Platform-Version'   => '120',
            'X-Plex-Device'             => 'Linux',
            'X-Plex-Device-Name'        => 'Matinee (alice)',
            'X-Plex-Model'              => 'hosted',
            'X-Plex-Session-Identifier' => self::SESSION,
            'Accept'                    => 'application/vnd.apple.mpegurl,*/*',
        ];
    }

    public function testHlsStartRequestsTheC2aStartUrlWithTheC2bHeadersAndTimeout60(): void
    {
        $this->t->respond(200, "#EXTM3U\nraw", ['Content-Type' => 'application/vnd.apple.mpegurl']);
        $this->assertSame("#EXTM3U\nraw", $this->client()->hlsStart('/library/metadata/2002', self::SESSION));

        $this->assertCount(1, $this->t->requests);
        [$req] = $this->t->requests;
        $this->assertSame(
            self::BASE . '/video/:/transcode/universal/start.m3u8?hasMDE=1&path=%2Flibrary%2Fmetadata%2F2002&mediaIndex=0'
                . '&partIndex=0&protocol=hls&fastSeek=1&directPlay=0&directStream=1&subtitleSize=100&audioBoost=100'
                . '&location=lan&addDebugOverlay=0&autoAdjustQuality=0&directStreamAudio=1&mediaBufferSize=102400'
                . '&session=' . self::SESSION . '&subtitles=burn&Accept-Language=en&videoQuality=100'
                . '&videoResolution=1920x1080&maxVideoBitrate=8000&X-Plex-Client-Identifier=' . self::CLIENT_ID
                . '&X-Plex-Session-Identifier=' . self::SESSION,
            $req['url'],
        );
        $this->assertSame($this->hlsHeaders(), $req['headers']);
        $this->assertOnlyHeaderCarriesTheToken($req);
        $this->assertSame(['timeout' => 60], $req['opts']);
    }

    /** @dataProvider playlistTypes */
    public function testHlsPlaylistTypesAreAccepted(string $type): void
    {
        $this->t->respond(200, '#EXTM3U', ['Content-Type' => $type])->respond(200, '#EXTM3U', ['Content-Type' => $type]);
        $this->assertSame('#EXTM3U', $this->client()->hlsStart('/library/metadata/2002', self::SESSION));
        $this->assertSame('#EXTM3U', $this->client()->hlsPlaylist(self::HLS_BASE . '/index.m3u8', self::SESSION));
    }

    public function playlistTypes(): array
    {
        return [
            'apple'           => ['application/vnd.apple.mpegurl'],
            'x-mpegurl'       => ['application/x-mpegurl'],
            'case and params' => ['Application/X-MpegURL; charset=UTF-8'],
        ];
    }

    /** @dataProvider badPlaylistTypes */
    public function testAPlaylistOfAnotherTypeIsBadType(?string $type): void
    {
        $this->t->respond(200, 'UPSTREAM-BODY', $type === null ? [] : ['Content-Type' => $type])
            ->respond(200, 'UPSTREAM-BODY', $type === null ? [] : ['Content-Type' => $type]);
        $this->expectPlexError(fn () => $this->client()->hlsStart('/library/metadata/2002', self::SESSION), PlexException::BAD_TYPE);
        $this->expectPlexError(fn () => $this->client()->hlsPlaylist(self::HLS_BASE . '/index.m3u8', self::SESSION), PlexException::BAD_TYPE);
    }

    public function badPlaylistTypes(): array
    {
        return ['html' => ['text/html'], 'json' => ['application/json'], 'missing' => [null], 'mp2t' => ['video/mp2t']];
    }

    public function testAPlexErrorOnStartIsHttpErrorAndTheBodyIsNeverReturned(): void
    {
        $this->t->respond(400, 'UPSTREAM-BODY ' . self::TOKEN, ['Content-Type' => 'application/vnd.apple.mpegurl']);
        $e = $this->expectPlexError(fn () => $this->client()->hlsStart('/library/metadata/2002', self::SESSION), PlexException::HTTP_ERROR);
        $this->assertSame(400, $e->status);
    }

    /** @dataProvider statuses */
    public function testHlsBufferedStatusMapping(int $status, string $reason): void
    {
        $this->t->respond($status, 'UPSTREAM-BODY', ['Content-Type' => 'application/vnd.apple.mpegurl'])
            ->respond($status, 'UPSTREAM-BODY', ['Content-Type' => 'application/vnd.apple.mpegurl']);
        $this->expectPlexError(fn () => $this->client()->hlsStart('/library/metadata/2002', self::SESSION), $reason);
        $this->expectPlexError(fn () => $this->client()->hlsPlaylist(self::HLS_BASE . '/index.m3u8', self::SESSION), $reason);
    }

    public function testAPlaylistOver2MiBIsTooLarge(): void
    {
        $this->t->respond(200, str_repeat('#', PlexClient::MAX_BODY_BYTES + 1), ['Content-Type' => 'application/vnd.apple.mpegurl']);
        $this->expectPlexError(fn () => $this->client()->hlsPlaylist(self::HLS_BASE . '/index.m3u8', self::SESSION), PlexException::TOO_LARGE);
    }

    public function testHlsTransportFailuresAreFixed(): void
    {
        $this->t->fail(new \RuntimeException('curl: ' . self::BASE . '/?X-Plex-Token=' . self::TOKEN));
        $this->expectPlexError(fn () => $this->client()->hlsStart('/library/metadata/2002', self::SESSION), PlexException::UNREACHABLE);
    }

    /** @dataProvider badStartArgs */
    public function testHlsStartRefusesBadArgumentsWithNoRequest(string $path, string $session): void
    {
        $this->assertRefusedWithNoRequest(fn () => $this->client()->hlsStart($path, $session));
    }

    public function badStartArgs(): array
    {
        return [
            'part path'     => [self::MP3_PART, self::SESSION],
            'children'      => ['/library/metadata/1/children', self::SESSION],
            'query'         => ['/library/metadata/1?X-Plex-Token=evil', self::SESSION],
            'upper session' => ['/library/metadata/1', strtoupper(self::SESSION)],
            'v1 session'    => ['/library/metadata/1', '0f1e2d3c-4b5a-1987-8a6b-5c4d3e2f1a0b'],
            'empty session' => ['/library/metadata/1', ''],
            'session &'     => ['/library/metadata/1', self::SESSION . '&X-Plex-Token=x'],
        ];
    }

    private function assertRefusedWithNoRequest(callable $fn): void
    {
        try {
            $fn();
            $this->fail('expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertMessageIsClean($e);
        }
        $this->assertSame([], $this->t->requests);
    }

    public function testHlsPlaylistRequestsTheChildWithTheSameHeadersAsStartAndTimeout60(): void
    {
        $this->t->respond(200, '#EXTM3U', ['Content-Type' => 'application/vnd.apple.mpegurl'])
            ->respond(200, '#EXTM3U', ['Content-Type' => 'application/vnd.apple.mpegurl']);
        $c = $this->client();
        $c->hlsStart('/library/metadata/2002', self::SESSION);
        $this->assertSame('#EXTM3U', $c->hlsPlaylist(self::HLS_BASE . '/index.m3u8?x=1', self::SESSION));

        [$start, $req] = $this->t->requests;
        $this->assertSame(self::BASE . self::HLS_BASE . '/index.m3u8?x=1', $req['url']);
        $this->assertSame($start['headers'], $req['headers']);
        $this->assertSame($this->hlsHeaders(), $req['headers']);
        $this->assertSame(['timeout' => 60], $req['opts']);
    }

    /** @dataProvider badPlaylistChildren */
    public function testHlsPlaylistRefusesAChildOutsideItsSessionWithNoRequest(string $u): void
    {
        $this->assertRefusedWithNoRequest(fn () => $this->client()->hlsPlaylist($u, self::SESSION));
    }

    public function badPlaylistChildren(): array
    {
        $other = '/video/:/transcode/universal/session/' . self::OTHER_SESSION . '/base';
        return [
            'another session' => [$other . '/index.m3u8'],
            'a segment'       => [self::HLS_BASE . '/00000.ts'],
            'the start path'  => ['/video/:/transcode/universal/start.m3u8'],
            'dot-dot'         => [self::HLS_BASE . '/../index.m3u8'],
            'token query'     => [self::HLS_BASE . '/index.m3u8?X-Plex-Token=x'],
            'absolute'        => ['http://evil' . self::HLS_BASE . '/index.m3u8'],
            'item path'       => ['/library/metadata/1'],
        ];
    }

    public function testHlsSegmentStreamsWithTheS3OptsAndTheSameHeadersAsStart(): void
    {
        $sink = $this->sink();
        $this->t->respond(200, '#EXTM3U', ['Content-Type' => 'application/vnd.apple.mpegurl'])
            ->streams(200, ['Content-Type' => 'video/mp2t', 'Content-Length' => '6', 'Content-Range' => 'bytes 0-5/6', 'Accept-Ranges' => 'bytes'], ['G@', 'data']);
        $c = $this->client();
        $c->hlsStart('/library/metadata/2002', self::SESSION);
        $c->hlsSegment(self::HLS_BASE . '/00000.ts', self::SESSION, $sink);

        [$start, $req] = $this->t->requests;
        $this->assertSame(self::BASE . self::HLS_BASE . '/00000.ts', $req['url']);
        $this->assertSame($start['headers'], $req['headers']);
        $this->assertSame($this->hlsHeaders(), $req['headers']);
        $this->assertSame(['timeout' => 60, 'lowSpeedLimit' => 1024, 'lowSpeedTime' => 15, 'maxBytes' => 67108864], $req['opts']);
        $this->assertSame(200, $sink->status);
        $this->assertSame(['content-type' => 'video/mp2t', 'content-length' => '6'], $sink->headers);
        $this->assertSame('G@data', $sink->body);
    }

    /** @dataProvider segmentTypes */
    public function testSegmentTypesAreCanonicalByExtension(string $name, string $upstream, string $sent): void
    {
        $sink = $this->sink();
        $this->t->streams(206, ['Content-Type' => $upstream], ['x']);
        $this->client()->hlsSegment(self::HLS_BASE . '/' . $name, self::SESSION, $sink);
        $this->assertSame(200, $sink->status);
        $this->assertSame(['content-type' => $sent], $sink->headers);
    }

    public function segmentTypes(): array
    {
        return [
            'ts'            => ['00000.ts', 'video/mp2t', 'video/mp2t'],
            'ts with query' => ['00000.ts?x=a.m3u8', 'Video/MP2T; x=1', 'video/mp2t'],
            'm4s mp4'       => ['00000.m4s', 'video/mp4', 'video/mp4'],
            'm4s segment'   => ['00000.m4s', 'video/iso.segment', 'video/iso.segment'],
            'aac'           => ['00000.aac', 'audio/aac', 'audio/aac'],
            'vtt'           => ['sub.vtt', 'text/vtt; charset=utf-8', 'text/vtt'],
        ];
    }

    /** @dataProvider badSegmentTypes */
    public function testASegmentOfAnotherTypeIsBadTypeAndTheSinkNeverOpens(string $name, ?string $type): void
    {
        $sink = $this->sink();
        $this->t->streams(200, $type === null ? [] : ['Content-Type' => $type], ['UPSTREAM-BODY']);
        $this->expectPlexError(fn () => $this->client()->hlsSegment(self::HLS_BASE . '/' . $name, self::SESSION, $sink), PlexException::BAD_TYPE);
        $this->assertSame(0, $sink->opens);
        $this->assertSame('', $sink->body);
    }

    public function badSegmentTypes(): array
    {
        return [
            'ts html'      => ['00000.ts', 'text/html'],
            'ts mp4'       => ['00000.ts', 'video/mp4'],
            'ts webm'      => ['00000.ts', 'video/webm'],
            'ts missing'   => ['00000.ts', null],
            'm4s mp2t'     => ['00000.m4s', 'video/mp2t'],
            'aac mpeg'     => ['00000.aac', 'audio/mpeg'],
            'vtt html'     => ['sub.vtt', 'text/html'],
            'ts playlist'  => ['00000.ts', 'application/vnd.apple.mpegurl'],
        ];
    }

    /** @dataProvider segmentLengths */
    public function testASegmentLengthIsForwardedOnlyWhenItIsDigits(string $length, bool $kept): void
    {
        $sink = $this->sink();
        $this->t->streams(200, ['Content-Type' => 'video/mp2t', 'Content-Length' => $length], ['x']);
        $this->client()->hlsSegment(self::HLS_BASE . '/00000.ts', self::SESSION, $sink);
        $this->assertSame($kept ? ['content-type' => 'video/mp2t', 'content-length' => $length] : ['content-type' => 'video/mp2t'], $sink->headers);
    }

    public function segmentLengths(): array
    {
        return [
            'one digit'  => ['1', true],
            'the cap'    => ['67108864', true],
            '19 digits'  => [str_repeat('9', 19), false],
            'empty'      => ['', false],
            'negative'   => ['-1', false],
            'spaced'     => [' 5', false],
            'exponent'   => ['1e3', false],
            'two values' => ['5, 5', false],
        ];
    }

    /** @dataProvider statuses */
    public function testSegmentStatusMapping(int $status, string $reason): void
    {
        $sink = $this->sink();
        $this->t->streams($status, ['Content-Type' => 'video/mp2t'], ['UPSTREAM-BODY ' . self::TOKEN]);
        $e = $this->expectPlexError(fn () => $this->client()->hlsSegment(self::HLS_BASE . '/00000.ts', self::SESSION, $sink), $reason);
        $this->assertSame($status, $e->status);
        $this->assertSame(0, $sink->opens);
    }

    public function testASegmentOver64MiBIsTooLargeAfterTheCut(): void
    {
        $sink = new class () implements \Kanboard\Plugin\Matinee\Http\PlexStreamSink {
            public int $bytes = 0;

            public function open(int $status, array $headers): bool
            {
                return true;
            }

            public function write(string $chunk): bool
            {
                $this->bytes += strlen($chunk);
                return true;
            }
        };
        $chunk = str_repeat('s', 16 * 1024 * 1024);
        $this->t->streams(200, ['Content-Type' => 'video/mp2t'], [$chunk, $chunk, $chunk, $chunk, 'x']);
        $this->expectPlexError(fn () => $this->client()->hlsSegment(self::HLS_BASE . '/00000.ts', self::SESSION, $sink), PlexException::TOO_LARGE);
        $this->assertSame(67108864, $sink->bytes);
    }

    /** @dataProvider overCapLengths */
    public function testASegmentDeclaredOver64MiBIsTooLargeBeforeTheSinkOpens(string $length): void
    {
        $sink = $this->sink();
        $this->t->streams(200, ['Content-Type' => 'video/mp2t', 'Content-Length' => $length], ['UPSTREAM-BODY']);
        $this->expectPlexError(fn () => $this->client()->hlsSegment(self::HLS_BASE . '/00000.ts', self::SESSION, $sink), PlexException::TOO_LARGE);
        $this->assertSame(0, $sink->opens);
        $this->assertSame('', $sink->body);
    }

    public function overCapLengths(): array
    {
        return [
            'one byte over' => ['67108865'],
            '18 digits'     => [str_repeat('9', 18)],
        ];
    }

    public function testSegmentTransportFailuresAreFixed(): void
    {
        $this->t->fail(new \RuntimeException('curl: ' . self::BASE . '/?X-Plex-Token=' . self::TOKEN));
        $this->expectPlexError(fn () => $this->client()->hlsSegment(self::HLS_BASE . '/00000.ts', self::SESSION, $this->sink()), PlexException::UNREACHABLE);
    }

    /** @dataProvider badSegmentChildren */
    public function testHlsSegmentRefusesAChildOutsideItsSessionWithNoRequest(string $u, string $session): void
    {
        $this->assertRefusedWithNoRequest(fn () => $this->client()->hlsSegment($u, $session, $this->sink()));
    }

    public function badSegmentChildren(): array
    {
        return [
            'another session' => ['/video/:/transcode/universal/session/' . self::OTHER_SESSION . '/base/00000.ts', self::SESSION],
            'session mismatch' => [self::HLS_BASE . '/00000.ts', self::OTHER_SESSION],
            'a playlist'      => [self::HLS_BASE . '/index.m3u8', self::SESSION],
            'a playlist with a segment-shaped query' => [self::HLS_BASE . '/index.m3u8?x=a.ts', self::SESSION],
            'mp4'             => [self::HLS_BASE . '/00000.mp4', self::SESSION],
            'part path'       => [self::MP3_PART, self::SESSION],
            'encoded'         => [self::HLS_BASE . '/%2e%2e/00000.ts', self::SESSION],
        ];
    }

    public function testHlsStopRequestsTheStopUrlWithTheSameHeadersAndTimeout3(): void
    {
        $this->t->respond(200, '', []);
        $this->client()->hlsStop(self::SESSION);

        [$req] = $this->t->requests;
        $this->assertSame(self::BASE . '/video/:/transcode/universal/stop?session=' . self::SESSION, $req['url']);
        $this->assertSame($this->hlsHeaders(), $req['headers']);
        $this->assertSame(['timeout' => 3], $req['opts']);
    }

    public function testHlsStopNon2xxIsHttpError(): void
    {
        $this->t->respond(404, 'UPSTREAM-BODY', []);
        $e = $this->expectPlexError(fn () => $this->client()->hlsStop(self::SESSION), PlexException::HTTP_ERROR);
        $this->assertSame(404, $e->status);
    }

    /** @dataProvider badStopSessions */
    public function testHlsStopRefusesANonV4SessionWithNoRequest(string $session): void
    {
        $this->assertRefusedWithNoRequest(fn () => $this->client()->hlsStop($session));
    }

    public function badStopSessions(): array
    {
        return ['upper' => [strtoupper(self::SESSION)], 'empty' => [''], 'injected' => [self::SESSION . '&X-Plex-Token=x']];
    }

    /** The viewer's device name rides on every HLS call (C9, S14); another viewer's client names itself. */
    public function testTheHlsDeviceNameIsTheClients(): void
    {
        $this->t->respond(200, '', []);
        (new PlexClient(self::BASE, self::TOKEN, self::CLIENT_ID, $this->t, null, 'Matinee (bob)'))->hlsStop(self::SESSION);
        $this->assertSame('Matinee (bob)', $this->t->requests[0]['headers']['X-Plex-Device-Name']);
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
