<?php

use Kanboard\Plugin\Matinee\Crypto\CryptoException;
use Kanboard\Plugin\Matinee\Crypto\KeyStore;
use Kanboard\Plugin\Matinee\Crypto\Subkeys;
use PHPUnit\Framework\TestCase;

class KeyStoreTest extends TestCase
{
    private string $base;
    private string $dir;
    private int $umask;

    protected function setUp(): void
    {
        $this->umask = umask(0022);
        $this->base = sys_get_temp_dir().'/matinee-ks-'.bin2hex(random_bytes(8));
        $this->dir = $this->base.'/matinee';
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        $this->rmTree($this->base);
    }

    private function rmTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_dir($path)) {
            chmod($path, 0700);
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->rmTree($path.'/'.$entry);
                }
            }
            rmdir($path);
            return;
        }
        unlink($path);
    }

    private function keyFile(): string
    {
        return $this->dir.'/secret.key';
    }

    private function writeKeyFile(string $bytes): void
    {
        mkdir($this->dir, 0700, true);
        file_put_contents($this->keyFile(), $bytes);
    }

    private function mode(string $path): int
    {
        clearstatcache();
        return fileperms($path) & 0777;
    }

    // Kills dropping either chmod: with umask 022 fopen would leave the file at 0644.
    public function testCreatesKeyFileAt0600AndDirAt0700(): void
    {
        $store = new KeyStore($this->dir, null);
        $key = $store->rootKey();

        $this->assertSame(32, strlen($key));
        $this->assertSame('file', $store->keyId());
        $this->assertSame($key, file_get_contents($this->keyFile()));
        $this->assertSame(0600, $this->mode($this->keyFile()));
        $this->assertSame(0700, $this->mode($this->dir));
    }

    // Kills dropping the chmod after mkdir: umask 0277 would leave the dir at 0500 and the file at 0400.
    public function testModesAreExactUnderAStrictUmask(): void
    {
        mkdir($this->base, 0700); // the parent (DATA_DIR in production) already exists
        umask(0277);
        (new KeyStore($this->dir, null))->rootKey();
        $this->assertSame(0700, $this->mode($this->dir));
        $this->assertSame(0600, $this->mode($this->keyFile()));
    }

    public function testSecondInstanceReturnsTheSameKey(): void
    {
        $first = (new KeyStore($this->dir, null))->rootKey();
        $second = (new KeyStore($this->dir, null))->rootKey();
        $this->assertSame($first, $second);
    }

    // Kills writing over an existing file.
    public function testUsesAnExistingFileAndNeverRewritesIt(): void
    {
        $bytes = random_bytes(32);
        $this->writeKeyFile($bytes);
        clearstatcache();
        $before = filemtime($this->keyFile());
        touch($this->keyFile(), $before - 100);

        $this->assertSame($bytes, (new KeyStore($this->dir, null))->rootKey());

        clearstatcache();
        $this->assertSame($bytes, file_get_contents($this->keyFile()));
        $this->assertSame($before - 100, filemtime($this->keyFile()));
    }

    public static function wrongSizes(): array
    {
        return ['empty' => [0], 'short' => [31], 'long' => [33], 'base64-ish' => [44]];
    }

    /** @dataProvider wrongSizes */
    public function testWrongSizeFileFailsClosedAndIsLeftByteIdentical(int $size): void
    {
        $bytes = $size === 0 ? '' : random_bytes($size);
        $this->writeKeyFile($bytes);

        try {
            (new KeyStore($this->dir, null))->rootKey();
            $this->fail('expected CryptoException');
        } catch (CryptoException $e) {
            $this->assertSame('keystore: bad key file', $e->getMessage());
            if ($bytes !== '') {
                $this->assertStringNotContainsString($bytes, $e->getMessage());
                $this->assertStringNotContainsString(bin2hex($bytes), $e->getMessage());
            }
        }

        $this->assertSame($bytes, file_get_contents($this->keyFile()));
    }

    public function testValidEnvKeyWinsAndCreatesNoFile(): void
    {
        $env = random_bytes(32);
        $store = new KeyStore($this->dir, base64_encode($env));

        $this->assertSame($env, $store->rootKey());
        $this->assertSame('env', $store->keyId());
        $this->assertFileDoesNotExist($this->keyFile());
    }

    public function testEnvKeyWinsOverAnExistingFile(): void
    {
        $file = random_bytes(32);
        $env = random_bytes(32);
        $this->writeKeyFile($file);
        $store = new KeyStore($this->dir, base64_encode($env));

        $this->assertSame($env, $store->rootKey());
        $this->assertSame('env', $store->keyId());
        $this->assertSame($file, file_get_contents($this->keyFile()));
    }

    public static function badEnvValues(): array
    {
        return [
            'not base64' => ['not base64 at all!!'],
            'empty' => [''],
            'short' => [base64_encode(str_repeat("\x01", 31))],
            'long' => [base64_encode(str_repeat("\x02", 33))],
            'hex not base64' => [str_repeat('ab', 32)],
        ];
    }

    /** @dataProvider badEnvValues */
    public function testMalformedEnvKeyFailsClosedAndCreatesNoFile(string $env): void
    {
        try {
            (new KeyStore($this->dir, $env))->rootKey();
            $this->fail('expected CryptoException');
        } catch (CryptoException $e) {
            $this->assertSame('keystore: bad env key', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->keyFile());
    }

    public function testKeyIdForIsEightHexStableAndDistinct(): void
    {
        $a = random_bytes(32);
        $b = random_bytes(32);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', KeyStore::keyIdFor($a));
        $this->assertSame(KeyStore::keyIdFor($a), KeyStore::keyIdFor($a));
        $this->assertNotSame(KeyStore::keyIdFor($a), KeyStore::keyIdFor($b));
        // Pins the construction: BLAKE2b keyed by the root over the message "mtn-kid".
        $this->assertSame(substr(bin2hex(sodium_crypto_generichash('mtn-kid', $a)), 0, 8), KeyStore::keyIdFor($a));
    }

    public function testAllKeysHoldsBothWhenEnvAndFileExist(): void
    {
        $file = random_bytes(32);
        $env = random_bytes(32);
        $this->writeKeyFile($file);
        $store = new KeyStore($this->dir, base64_encode($env));

        $this->assertSame(
            [KeyStore::keyIdFor($env) => $env, KeyStore::keyIdFor($file) => $file],
            $store->allKeys()
        );
        $this->assertSame(KeyStore::keyIdFor($env), $store->activeKeyId());
    }

    public function testAllKeysWithEnvOnlyHoldsOnlyTheEnvKey(): void
    {
        $env = random_bytes(32);
        $store = new KeyStore($this->dir, base64_encode($env));

        $this->assertSame([KeyStore::keyIdFor($env) => $env], $store->allKeys());
        $this->assertFileDoesNotExist($this->keyFile());
    }

    public function testAllKeysWithFileOnly(): void
    {
        $store = new KeyStore($this->dir, null);
        $root = $store->rootKey();

        $this->assertSame([KeyStore::keyIdFor($root) => $root], $store->allKeys());
        $this->assertSame(KeyStore::keyIdFor($root), $store->activeKeyId());
    }

    public function testAllKeysFailsClosedOnABadFileEvenWithAnEnvKey(): void
    {
        $this->writeKeyFile(random_bytes(16));
        $store = new KeyStore($this->dir, base64_encode(random_bytes(32)));

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('keystore: bad key file');
        $store->allKeys();
    }

    // Kills swapping the contexts (or the subkey ids).
    public function testSubkeysAreDistinctAndPinnedToTheirContexts(): void
    {
        $root = random_bytes(32);
        $token = Subkeys::token($root);
        $refs = Subkeys::refs($root);

        $this->assertSame(32, strlen($token));
        $this->assertSame(32, strlen($refs));
        $this->assertNotSame($token, $refs);
        $this->assertNotSame($root, $token);
        $this->assertNotSame($root, $refs);
        $this->assertSame($token, Subkeys::token($root));
        $this->assertSame(sodium_crypto_kdf_derive_from_key(32, 1, 'mtntoken', $root), $token);
        $this->assertSame(sodium_crypto_kdf_derive_from_key(32, 2, 'mtnrefs_', $root), $refs);
    }

    public function testSubkeysRejectAWrongSizeRoot(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('subkeys: bad root key');
        Subkeys::token(random_bytes(16));
    }
}
