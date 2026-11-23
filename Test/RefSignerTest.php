<?php

use Kanboard\Plugin\Matinee\Crypto\CryptoException;
use Kanboard\Plugin\Matinee\Crypto\RefSigner;
use Kanboard\Plugin\Matinee\Model\MatineeForbiddenException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RefSignerTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const USER = 7;
    private const SERVER = 3;
    private const BROWSE = '/library/sections/2/all';
    private const ART = '/library/metadata/123/thumb/1700000000';
    private const PART = '/library/parts/2229/1250471640/file.flac';
    private const ITEM = '/library/metadata/42';

    private static function key(): string
    {
        return str_repeat("\x5a", 32);
    }

    private static function b64(string $bin): string
    {
        return sodium_bin2base64($bin, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    private static function signer(int $now = self::NOW, ?string $key = null): RefSigner
    {
        return new RefSigner($key ?? self::key(), fn (): int => $now);
    }

    /** A valid payload, as sign() would mint it. */
    private static function payload(array $changes = [], array $drop = []): array
    {
        $p = ['v' => 1, 'u' => self::USER, 'sv' => self::SERVER, 's' => '2', 'k' => 'browse', 'r' => self::BROWSE, 'exp' => self::NOW + 3600];
        foreach ($drop as $k) {
            unset($p[$k]);
        }
        return array_replace($p, $changes);
    }

    /** A ref signed with the test key over any body: what an attacker who had the key could mint. */
    private static function forgeBody(string $json, ?string $key = null): string
    {
        $body = self::b64($json);
        return $body.'.'.self::b64(hash_hmac('sha256', $body, $key ?? self::key(), true));
    }

    private static function forge(array $payload): string
    {
        return self::forgeBody(json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private static function flip(string $s, int $i): string
    {
        $s[$i] = $s[$i] === 'A' ? 'B' : 'A';
        return $s;
    }

    // ── round trip ───────────────────────────────────────────────────────────

    public function testBrowseRoundTrip(): void
    {
        $ref = self::signer()->sign(self::USER, self::SERVER, '2', 'browse', self::BROWSE, 3600);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}\z/', $ref);
        $this->assertSame(
            ['sv' => self::SERVER, 's' => '2', 'k' => 'browse', 'r' => self::BROWSE],
            self::signer()->verify($ref, self::USER, 'browse')
        );
    }

    public function testArtRoundTrip(): void
    {
        $ref = self::signer()->sign(self::USER, self::SERVER, '5', 'art', self::ART, 60);
        $this->assertSame(['sv' => self::SERVER, 's' => '5', 'k' => 'art', 'r' => self::ART], self::signer()->verify($ref, self::USER, 'art'));
    }

    public function testTheBodyIsExactlyTheSpecPayload(): void
    {
        $ref = self::signer()->sign(self::USER, self::SERVER, '2', 'browse', self::BROWSE, 600);
        [$body, $sig] = explode('.', $ref);
        $this->assertSame(self::b64(hash_hmac('sha256', $body, self::key(), true)), $sig);
        $this->assertSame(
            ['v' => 1, 'u' => self::USER, 'sv' => self::SERVER, 's' => '2', 'k' => 'browse', 'r' => self::BROWSE, 'exp' => self::NOW + 600],
            json_decode(sodium_base642bin($body, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), true)
        );
    }

    // A forged ref with the test key is the same as a minted one: the forge helper is faithful.
    public function testAForgedValidPayloadVerifies(): void
    {
        $this->assertSame('2', self::signer()->verify(self::forge(self::payload()), self::USER, 'browse')['s']);
    }

    public function testExpiryBoundaries(): void
    {
        $ref = self::signer()->sign(self::USER, self::SERVER, '2', 'browse', self::BROWSE, 60);
        $this->assertSame('2', self::signer(self::NOW + 59)->verify($ref, self::USER, 'browse')['s']);
        $this->expectException(MatineeForbiddenException::class);
        self::signer(self::NOW + 60)->verify($ref, self::USER, 'browse');
    }

    // exp may be exactly now + 3600, not one second more (kills dropping or loosening the upper bound).
    public function testTheUpperBoundIsInclusive(): void
    {
        $this->assertSame('2', self::signer()->verify(self::forge(self::payload(['exp' => self::NOW + 3600])), self::USER, 'browse')['s']);
    }

    // ── kinds and TTLs (C5, S13) ─────────────────────────────────────────────

    public function testTheKindsAndTheirTtls(): void
    {
        $this->assertSame(['browse', 'art', 'media', 'hls'], RefSigner::KINDS);
        $this->assertSame(['browse' => 3600, 'art' => 3600, 'media' => 28800, 'hls' => 28800], RefSigner::TTLS);
        $this->assertFalse(defined(RefSigner::class.'::MAX_TTL'));
    }

    /** @return array<string, array{string, string}> */
    public static function playRefs(): array
    {
        return [
            'media, part' => ['media', self::PART],
            'media, item' => ['media', self::ITEM],
            'hls, item' => ['hls', self::ITEM],
        ];
    }

    /** @dataProvider playRefs */
    #[DataProvider('playRefs')]
    public function testPlayRefsRoundTripWithAnEightHourTtl(string $kind, string $resource): void
    {
        $ref = self::signer()->sign(self::USER, self::SERVER, '4', $kind, $resource, 28800);
        $this->assertSame(['sv' => self::SERVER, 's' => '4', 'k' => $kind, 'r' => $resource], self::signer()->verify($ref, self::USER, $kind));
        $this->assertSame('4', self::signer(self::NOW + 28799)->verify($ref, self::USER, $kind)['s']);
        try {
            self::signer(self::NOW + 28800)->verify($ref, self::USER, $kind);
            $this->fail('expected MatineeForbiddenException');
        } catch (MatineeForbiddenException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @dataProvider playRefs */
    #[DataProvider('playRefs')]
    public function testPlayRefUpperBoundIsExactlyEightHours(string $kind, string $resource): void
    {
        $ok = self::forge(self::payload(['k' => $kind, 'r' => $resource, 'exp' => self::NOW + 28800]));
        $this->assertSame($kind, self::signer()->verify($ok, self::USER, $kind)['k']);
        $this->expectException(MatineeForbiddenException::class);
        self::signer()->verify(self::forge(self::payload(['k' => $kind, 'r' => $resource, 'exp' => self::NOW + 28801])), self::USER, $kind);
    }

    // A browse ref is still bounded by an hour at sign time (kills one global 28800 limit).
    public function testABrowseRefSignedForMoreThanAnHourIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ref: bad ttl');
        self::signer()->sign(self::USER, self::SERVER, '2', 'browse', self::BROWSE, 3601);
    }

    // ... and at verify time (kills one global 28800 limit).
    public function testAHandBuiltBrowseRefPastAnHourIsRefused(): void
    {
        $this->expectException(MatineeForbiddenException::class);
        self::signer()->verify(self::forge(self::payload(['exp' => self::NOW + 3601])), self::USER, 'browse');
    }

    public function testAHandBuiltArtRefPastAnHourIsRefused(): void
    {
        $this->expectException(MatineeForbiddenException::class);
        self::signer()->verify(self::forge(self::payload(['k' => 'art', 'r' => self::ART, 'exp' => self::NOW + 3601])), self::USER, 'art');
    }

    public function testSignAcceptsThePlayTtlBounds(): void
    {
        foreach ([1, 28800] as $ttl) {
            $ref = self::signer()->sign(self::USER, self::SERVER, '2', 'hls', self::ITEM, $ttl);
            $this->assertSame('hls', self::signer()->verify($ref, self::USER, 'hls')['k']);
        }
    }

    // ── refusals ─────────────────────────────────────────────────────────────

    /** @return array<string, array{\Closure(): string}> */
    public static function refusals(): array
    {
        $good = fn (): string => self::signer()->sign(self::USER, self::SERVER, '2', 'browse', self::BROWSE, 3600);
        $cases = [
            'bad version' => fn () => self::forge(self::payload(['v' => 2])),
            'version 0' => fn () => self::forge(self::payload(['v' => 0])),
            'string version' => fn () => self::forge(self::payload(['v' => '1'])),
            'float version' => fn () => self::forgeBody(str_replace('"v":1,', '"v":1.0,', json_encode(self::payload(), JSON_UNESCAPED_SLASHES))),
            'extra field' => fn () => self::forge(self::payload(['rk' => '1'])),
            'share id field' => fn () => self::forge(self::payload(['sh' => 1])),
            'expired' => fn () => self::forge(self::payload(['exp' => self::NOW])),
            'long expired' => fn () => self::forge(self::payload(['exp' => self::NOW - 1])),
            'too-far exp' => fn () => self::forge(self::payload(['exp' => self::NOW + 3601])),
            'far-future exp' => fn () => self::forge(self::payload(['exp' => PHP_INT_MAX])),
            'other user' => fn () => self::forge(self::payload(['u' => self::USER + 1])),
            'zero user' => fn () => self::forge(self::payload(['u' => 0])),
            'zero server' => fn () => self::forge(self::payload(['sv' => 0])),
            'negative server' => fn () => self::forge(self::payload(['sv' => -3])),
            'string user' => fn () => self::forge(self::payload(['u' => (string) self::USER])),
            'string server' => fn () => self::forge(self::payload(['sv' => '3'])),
            'string exp' => fn () => self::forge(self::payload(['exp' => (string) (self::NOW + 60)])),
            'float exp' => fn () => self::forge(self::payload(['exp' => self::NOW + 60.5])),
            'int section' => fn () => self::forge(self::payload(['s' => 2])),
            'array kind' => fn () => self::forge(self::payload(['k' => ['browse']])),
            'int resource' => fn () => self::forge(self::payload(['r' => 1])),
            'null resource' => fn () => self::forge(self::payload(['r' => null])),
            'bad section' => fn () => self::forge(self::payload(['s' => 'abc'])),
            'empty section' => fn () => self::forge(self::payload(['s' => ''])),
            'unknown kind' => fn () => self::forge(self::payload(['k' => 'video'])),
            'browse path as media' => fn () => self::forge(self::payload(['k' => 'media'])),
            'browse path as hls' => fn () => self::forge(self::payload(['k' => 'hls'])),
            'part path as hls' => fn () => self::forge(self::payload(['k' => 'hls', 'r' => self::PART, 'exp' => self::NOW + 60])),
            'art path as media' => fn () => self::forge(self::payload(['k' => 'media', 'r' => self::ART])),
            'item path as browse' => fn () => self::forge(self::payload(['r' => self::ITEM])),
            'art path as hls' => fn () => self::forge(self::payload(['k' => 'hls', 'r' => self::ART])),
            'item children as hls' => fn () => self::forge(self::payload(['k' => 'hls', 'r' => '/library/metadata/42/children'])),
            'item path as art' => fn () => self::forge(self::payload(['k' => 'art', 'r' => self::ITEM])),
            'part path as browse' => fn () => self::forge(self::payload(['r' => self::PART])),
            'art path as browse' => fn () => self::forge(self::payload(['r' => self::ART])),
            'browse path as art' => fn () => self::forge(self::payload(['k' => 'art'])),
            'dot-dot resource' => fn () => self::forge(self::payload(['r' => '/library/sections/2/../../all'])),
            'query resource' => fn () => self::forge(self::payload(['r' => self::BROWSE.'?x'])),
            'trailing slash resource' => fn () => self::forge(self::payload(['r' => self::BROWSE.'/'])),
            'json list' => fn () => self::forgeBody(json_encode(array_values(self::payload()))),
            'nested json' => fn () => self::forgeBody(json_encode(['p' => self::payload()])),
            'not json' => fn () => self::forgeBody('not json'),
            'json null' => fn () => self::forgeBody('null'),
            'empty body' => fn () => '.'.self::b64(hash_hmac('sha256', '', self::key(), true)),
            'other key' => fn () => self::forgeBody(json_encode(self::payload(), JSON_UNESCAPED_SLASHES), str_repeat("\x5b", 32)),
            'flipped sig byte' => fn () => self::flip($good(), strlen($good()) - 5),
            'flipped body byte' => fn () => self::flip($good(), 3),
            'truncated sig' => fn () => substr($good(), 0, -1),
            'extended sig' => fn () => $good().'A',
            'swapped halves' => fn () => implode('.', array_reverse(explode('.', $good()))),
            'padding' => fn () => $good().'=',
            'non-base64url body' => fn () => '+'.substr($good(), 1),
            'non-base64url sig' => fn () => substr($good(), 0, -1).'/',
            'standard base64 body' => function (): string {
                $body = base64_encode("\xfb\xff"); // "+/8=": signed, but not base64url
                return $body.'.'.self::b64(hash_hmac('sha256', $body, self::key(), true));
            },
            'no dot' => fn () => str_replace('.', '', $good()),
            'two dots' => fn () => $good().'.'.substr($good(), -43),
            'empty' => fn () => '',
            'only a dot' => fn () => '.',
            'whitespace' => fn () => ' '.$good(),
            'trailing newline' => fn () => $good()."\n",
            'too long' => fn () => str_repeat('A', 2049).'.'.substr($good(), -43),
        ];
        foreach (array_keys(self::payload()) as $field) {
            $cases["missing $field"] = fn () => self::forge(self::payload([], [$field]));
        }
        return array_map(fn (\Closure $c) => [$c], $cases);
    }

    // Refused whatever kind the caller asks for, so no case passes only because of a kind mismatch.
    /** @dataProvider refusals */
    #[DataProvider('refusals')]
    public function testEveryRefusalReadsTheSame(\Closure $ref): void
    {
        foreach (RefSigner::KINDS as $kind) {
            $this->assertRefused(fn () => self::signer()->verify($ref(), self::USER, $kind), $kind);
        }
    }

    private function assertRefused(\Closure $fn, string $label): void
    {
        try {
            $fn();
            $this->fail("expected MatineeForbiddenException ($label)");
        } catch (MatineeForbiddenException $e) {
            $this->assertSame(MatineeForbiddenException::class, get_class($e));
            $this->assertSame('matinee.forbidden', $e->getMessage());
        }
    }

    /** @return array<string, array{string, string, string}> */
    public static function kindPairs(): array
    {
        $resources = ['browse' => self::BROWSE, 'art' => self::ART, 'media' => self::PART, 'hls' => self::ITEM];
        $cases = [];
        foreach ($resources as $minted => $resource) {
            foreach ([...RefSigner::KINDS, 'video', ''] as $asked) {
                if ($asked !== $minted) {
                    $cases["$minted asked as ".($asked === '' ? 'empty' : $asked)] = [$minted, $resource, $asked];
                }
            }
        }
        return $cases;
    }

    // m6: verify takes the kind the caller expects, and a ref of any other kind is refused the same way.
    /** @dataProvider kindPairs */
    #[DataProvider('kindPairs')]
    public function testARefOfOneKindIsRefusedAsAnother(string $minted, string $resource, string $asked): void
    {
        $ref = self::signer()->sign(self::USER, self::SERVER, '2', $minted, $resource, 60);
        $this->assertSame($minted, self::signer()->verify($ref, self::USER, $minted)['k']);
        $this->assertRefused(fn () => self::signer()->verify($ref, self::USER, $asked), $asked);
    }

    // A timing leak is not observable here, so the constant-time compare is pinned in verify's source: swapping
    // hash_equals for === / !== behaves the same and only this test kills it.
    public function testVerifyComparesTheMacWithHashEquals(): void
    {
        $m = new ReflectionMethod(RefSigner::class, 'verify');
        $lines = array_slice(file($m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1);
        $source = implode('', $lines);
        $this->assertStringContainsString('hash_equals($this->mac($body), $sig)', $source);
        $this->assertDoesNotMatchRegularExpression('/\$sig\s*[!=]==?|[!=]==?\s*\$sig/', $source);
    }

    // ── sign() never mints an invalid ref ───────────────────────────────────

    /** @return array<string, array{array{int, int, string, string, string, int}, string}> */
    public static function badSignInputs(): array
    {
        $ok = [self::USER, self::SERVER, '2', 'browse', self::BROWSE, 3600];
        $with = fn (int $i, mixed $v): array => array_replace($ok, [$i => $v]);
        return [
            'zero user' => [$with(0, 0), 'ref: bad user'],
            'negative user' => [$with(0, -1), 'ref: bad user'],
            'zero server' => [$with(1, 0), 'ref: bad server'],
            'bad section' => [$with(2, '2/../1'), 'ref: bad section'],
            'empty section' => [$with(2, ''), 'ref: bad section'],
            'unknown kind' => [$with(3, 'video'), 'ref: bad kind'],
            'browse path as media' => [$with(3, 'media'), 'ref: bad resource'],
            'part path as hls' => [[self::USER, self::SERVER, '2', 'hls', self::PART, 60], 'ref: bad resource'],
            'too-long media ttl' => [[self::USER, self::SERVER, '2', 'media', self::PART, 28801], 'ref: bad ttl'],
            'too-long hls ttl' => [[self::USER, self::SERVER, '2', 'hls', self::ITEM, 28801], 'ref: bad ttl'],
            'too-long art ttl' => [[self::USER, self::SERVER, '2', 'art', self::ART, 3601], 'ref: bad ttl'],
            'art path as browse' => [$with(4, self::ART), 'ref: bad resource'],
            'browse path as art' => [array_replace($ok, [3 => 'art']), 'ref: bad resource'],
            'dot-dot resource' => [$with(4, '/library/sections/2/../all'), 'ref: bad resource'],
            'zero ttl' => [$with(5, 0), 'ref: bad ttl'],
            'negative ttl' => [$with(5, -1), 'ref: bad ttl'],
            'too-long ttl' => [$with(5, 3601), 'ref: bad ttl'],
        ];
    }

    /** @dataProvider badSignInputs */
    #[DataProvider('badSignInputs')]
    public function testSignRefusesBadInput(array $args, string $message): void
    {
        try {
            self::signer()->sign(...$args);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function testSignAcceptsTheTtlBounds(): void
    {
        foreach ([1, 3600] as $ttl) {
            $ref = self::signer()->sign(self::USER, self::SERVER, '2', 'browse', self::BROWSE, $ttl);
            $this->assertSame('2', self::signer()->verify($ref, self::USER, 'browse')['s']);
        }
    }

    // ── the key ──────────────────────────────────────────────────────────────

    public function testRejectsAWrongSizeKey(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('refs: bad key');
        new RefSigner(random_bytes(16));
    }

    // With args in traces on, the constructor frame holds a SensitiveParameterValue, never the key bytes.
    public function testTheKeyIsASensitiveParameterInTheConstructorFrame(): void
    {
        $key = random_bytes(16);
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            new RefSigner($key);
            $this->fail('expected CryptoException');
        } catch (CryptoException $e) {
            $frame = $e->getTrace()[0];
            $this->assertSame([RefSigner::class, '__construct'], [$frame['class'], $frame['function']]);
            $this->assertArrayHasKey('args', $frame, 'args are captured in traces');
            $this->assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][0]);
            $this->assertStringNotContainsString($key, print_r($frame, true).(string) $e);
            $this->assertStringNotContainsString(bin2hex($key), print_r($frame, true).(string) $e);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function testDebugOutputCarriesNoKeyBytes(): void
    {
        $key = random_bytes(32);
        $signer = new RefSigner($key, fn (): int => self::NOW);
        ob_start();
        var_dump($signer);
        $dump = ob_get_clean().print_r($signer, true).var_export($signer->__debugInfo(), true);
        foreach ([$key, bin2hex($key), base64_encode($key), self::b64($key)] as $form) {
            $this->assertStringNotContainsString($form, $dump);
        }
        $this->assertStringContainsString('[redacted]', $dump);
    }

    public function testTheDefaultClockIsTheWallClock(): void
    {
        $signer = new RefSigner(self::key());
        $ref = $signer->sign(self::USER, self::SERVER, '2', 'browse', self::BROWSE, 60);
        $exp = json_decode(sodium_base642bin(explode('.', $ref)[0], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), true)['exp'];
        $this->assertEqualsWithDelta(time() + 60, $exp, 2);
        $this->assertSame('2', $signer->verify($ref, self::USER, 'browse')['s']);
    }
}
