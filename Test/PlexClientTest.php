<?php

require_once __DIR__ . '/support/FakeTransport.php';

use Kanboard\Plugin\Matinee\Http\PlexClient;
use Kanboard\Plugin\Matinee\Http\PlexException;
use Kanboard\Plugin\Matinee\Http\PlexResponse;
use Kanboard\Plugin\Matinee\Http\UnsafeUrlException;
use Kanboard\Plugin\Matinee\Test\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * PlexClient's P1 surface (spec §3, §11 S9, S11, S14): headers, whitelisted parsing of /identity and
 * /library/sections, and fixed, scrubbed errors. No network: every byte comes from FakeTransport.
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
        ];
        $this->assertSame(
            ['plex.unreachable', 'plex.http_error', 'plex.token_rejected', 'plex.bad_json', 'plex.server_changed', 'plex.too_large', 'plex.token_in_uri'],
            array_map(fn (PlexException $e) => $e->getMessage(), $all),
        );
        $this->assertSame([0, 503, 401, 0, 0, 0, 0], array_map(fn (PlexException $e) => $e->status, $all));
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
