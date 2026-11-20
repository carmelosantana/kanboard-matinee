<?php

use Kanboard\Plugin\Matinee\Crypto\CryptoException;
use Kanboard\Plugin\Matinee\Crypto\TokenBox;
use PHPUnit\Framework\TestCase;

class TokenBoxTest extends TestCase
{
    /**
     * print_r of the trace frames inside Matinee code. The full trace drags in PHPUnit's suite objects, whose
     * data providers (other tests') can hold the same fixture strings.
     */
    private static function matineeFrames(\Throwable $e): string
    {
        $frames = array_filter($e->getTrace(), fn (array $f) => str_starts_with($f['class'] ?? '', 'Kanboard\\Plugin\\Matinee\\'));
        return print_r(array_values($frames), true);
    }

    private const TOKEN = 'test-token-NOT-REAL-0000';
    private const BASE_URL = 'https://plex.example.test:32400';
    private const MACHINE = 'abc123machine';

    private string $key;

    protected function setUp(): void
    {
        $this->key = random_bytes(32);
    }

    private function box(): TokenBox
    {
        return new TokenBox($this->key);
    }

    private function sealDefault(): array
    {
        return $this->box()->seal(self::TOKEN, 7, 3, self::BASE_URL, self::MACHINE);
    }

    public function testRoundTrip(): void
    {
        $sealed = $this->sealDefault();

        $this->assertSame(['nonce', 'ct'], array_keys($sealed));
        $this->assertSame(24, strlen(base64_decode($sealed['nonce'], true)));
        $this->assertStringNotContainsString(self::TOKEN, base64_decode($sealed['ct'], true));
        $this->assertSame(
            self::TOKEN,
            $this->box()->open($sealed['nonce'], $sealed['ct'], 7, 3, self::BASE_URL, self::MACHINE)
        );
    }

    // Pins the S7 AD byte for byte.
    public function testAdIsTheS7String(): void
    {
        $sealed = $this->sealDefault();
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            base64_decode($sealed['ct'], true),
            'matinee:token:v1|7|3|'.self::BASE_URL.'|'.self::MACHINE,
            base64_decode($sealed['nonce'], true),
            $this->key
        );
        $this->assertSame(self::TOKEN, $plain);
    }

    // Kills a fixed nonce.
    public function testFreshNonceEachSeal(): void
    {
        $a = $this->sealDefault();
        $b = $this->sealDefault();
        $this->assertNotSame($a['nonce'], $b['nonce']);
        $this->assertNotSame($a['ct'], $b['ct']);
    }

    public static function tamperedOpens(): array
    {
        return [
            'wrong server id' => [8, 3, self::BASE_URL, self::MACHINE],
            'wrong owner id' => [7, 4, self::BASE_URL, self::MACHINE],
            'wrong base url' => [7, 3, 'https://evil.example.test:32400', self::MACHINE],
            'wrong machine id' => [7, 3, self::BASE_URL, 'other-machine'],
        ];
    }

    /** @dataProvider tamperedOpens */
    public function testOpenFailsWhenAnyBoundFieldDiffers(int $serverId, int $ownerId, string $baseUrl, string $machine): void
    {
        $sealed = $this->sealDefault();
        $this->assertCannotOpen(fn () => $this->box()->open($sealed['nonce'], $sealed['ct'], $serverId, $ownerId, $baseUrl, $machine));
    }

    public function testOpenFailsForAFlippedCiphertextByte(): void
    {
        $sealed = $this->sealDefault();
        $ct = base64_decode($sealed['ct'], true);
        $ct[0] = chr(ord($ct[0]) ^ 0x01);
        $this->assertCannotOpen(fn () => $this->box()->open($sealed['nonce'], base64_encode($ct), 7, 3, self::BASE_URL, self::MACHINE));
    }

    public function testOpenFailsForAFlippedNonceByte(): void
    {
        $sealed = $this->sealDefault();
        $nonce = base64_decode($sealed['nonce'], true);
        $nonce[5] = chr(ord($nonce[5]) ^ 0x80);
        $this->assertCannotOpen(fn () => $this->box()->open(base64_encode($nonce), $sealed['ct'], 7, 3, self::BASE_URL, self::MACHINE));
    }

    public function testOpenFailsWithTheWrongKey(): void
    {
        $sealed = $this->sealDefault();
        $other = new TokenBox(random_bytes(32));
        $this->assertCannotOpen(fn () => $other->open($sealed['nonce'], $sealed['ct'], 7, 3, self::BASE_URL, self::MACHINE));
    }

    public static function malformedInputs(): array
    {
        return [
            'nonce not base64' => ['!!!', null],
            'short nonce' => [base64_encode(random_bytes(12)), null],
            'ct not base64' => [null, '%%%'],
            'empty ct' => [null, ''],
        ];
    }

    /** @dataProvider malformedInputs */
    public function testMalformedInputsCannotOpen(?string $nonce, ?string $ct): void
    {
        $sealed = $this->sealDefault();
        $this->assertCannotOpen(fn () => $this->box()->open($nonce ?? $sealed['nonce'], $ct ?? $sealed['ct'], 7, 3, self::BASE_URL, self::MACHINE));
    }

    // A "|" in the machine id would let base URL and machine id trade bytes and still give the same AD.
    public function testSealRejectsASeparatorInTheMachineIdWithoutLeakingTheToken(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $this->box()->seal(self::TOKEN, 7, 3, self::BASE_URL, 'abc|123');
            $this->fail('expected CryptoException');
        } catch (CryptoException $e) {
            $this->assertSame('token: bad machine id', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, (string) $e);
            $this->assertStringNotContainsString(self::TOKEN, self::matineeFrames($e));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function testOpenRejectsASeparatorInTheMachineId(): void
    {
        $sealed = $this->sealDefault();
        $this->assertCannotOpen(fn () => $this->box()->open($sealed['nonce'], $sealed['ct'], 7, 3, self::BASE_URL.'|abc', '123machine'));
    }

    public function testRejectsAWrongSizeKey(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('token: bad key');
        new TokenBox(random_bytes(16));
    }

    public function testAWrongSizeKeyIsNotInTheTrace(): void
    {
        $key = random_bytes(16);
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            new TokenBox($key);
            $this->fail('expected CryptoException');
        } catch (CryptoException $e) {
            $trace = (string) $e.self::matineeFrames($e);
            $this->assertStringNotContainsString($key, $trace);
            $this->assertStringNotContainsString(bin2hex($key), $trace);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function testDebugOutputCarriesNoKeyBytes(): void
    {
        $box = $this->box();
        ob_start();
        var_dump($box);
        $dump = ob_get_clean().print_r($box, true);
        $this->assertStringNotContainsString($this->key, $dump);
        $this->assertStringNotContainsString(bin2hex($this->key), $dump);
        $this->assertStringNotContainsString(base64_encode($this->key), $dump);
    }

    // The fixed message, and nothing in the exception's string form, carries the token.
    private function assertCannotOpen(callable $open): void
    {
        try {
            $open();
            $this->fail('expected CryptoException');
        } catch (CryptoException $e) {
            $this->assertSame('token: cannot open', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, (string) $e);
            $this->assertNull($e->getPrevious());
        }
    }
}
