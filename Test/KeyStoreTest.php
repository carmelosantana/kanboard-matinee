<?php

use Kanboard\Plugin\Matinee\Crypto\CryptoException;
use Kanboard\Plugin\Matinee\Crypto\KeyStore;
use Kanboard\Plugin\Matinee\Crypto\KeyStoreException;
use Kanboard\Plugin\Matinee\Crypto\Subkeys;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class KeyStoreTest extends TestCase
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
        if (is_link($path)) {
            unlink($path);
            return;
        }
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
    #[DataProvider('wrongSizes')]
    public function testWrongSizeFileFailsClosedAndIsLeftByteIdentical(int $size): void
    {
        $bytes = $size === 0 ? '' : random_bytes($size);
        $this->writeKeyFile($bytes);

        try {
            (new KeyStore($this->dir, null))->rootKey();
            $this->fail('expected KeyStoreException');
        } catch (KeyStoreException $e) {
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
    #[DataProvider('badEnvValues')]
    public function testMalformedEnvKeyFailsClosedAndCreatesNoFile(string $env): void
    {
        try {
            (new KeyStore($this->dir, $env))->rootKey();
            $this->fail('expected KeyStoreException');
        } catch (KeyStoreException $e) {
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

        $this->expectException(KeyStoreException::class);
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

    private function assertRefused(KeyStore $store, string $message, string $method = 'rootKey'): void
    {
        try {
            $store->$method();
            $this->fail('expected KeyStoreException');
        } catch (KeyStoreException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    // fopen 'x' / 'c' and file_get_contents follow symlinks; a planted link must never be used or created through.
    public function testDanglingSymlinkKeyFileIsRefusedAndItsTargetIsNotCreated(): void
    {
        mkdir($this->dir, 0700, true);
        $target = $this->base.'/elsewhere.key';
        symlink($target, $this->keyFile());

        $this->assertRefused(new KeyStore($this->dir, null), 'keystore: unsafe key file');
        $this->assertFileDoesNotExist($target);
        // The read-only path (env key set) refuses it too.
        $this->assertRefused(new KeyStore($this->dir, base64_encode(random_bytes(32))), 'keystore: unsafe key file', 'allKeys');
        $this->assertFileDoesNotExist($target);
    }

    public function testSymlinkToAnExistingKeyFileIsRefused(): void
    {
        mkdir($this->dir, 0700, true);
        $target = $this->base.'/elsewhere.key';
        file_put_contents($target, random_bytes(32));
        symlink($target, $this->keyFile());

        $this->assertRefused(new KeyStore($this->dir, null), 'keystore: unsafe key file');
        $this->assertRefused(new KeyStore($this->dir, base64_encode(random_bytes(32))), 'keystore: unsafe key file', 'allKeys');
    }

    public function testSymlinkedLockIsRefusedAndItsTargetIsNotCreated(): void
    {
        mkdir($this->dir, 0700, true);
        $target = $this->base.'/elsewhere.lock';
        symlink($target, $this->dir.'/.lock');

        $this->assertRefused(new KeyStore($this->dir, null), 'keystore: cannot lock key dir');
        $this->assertFileDoesNotExist($target);
        $this->assertFileDoesNotExist($this->keyFile());
    }

    public static function unsafeDirModes(): array
    {
        return ['world-writable' => [0777], 'group-writable' => [0770], 'other-writable only' => [0702]];
    }

    /** @dataProvider unsafeDirModes */
    #[DataProvider('unsafeDirModes')]
    public function testGroupOrWorldWritableExistingDirIsRefused(int $mode): void
    {
        mkdir($this->dir, 0700, true);
        chmod($this->dir, $mode);

        $this->assertRefused(new KeyStore($this->dir, null), 'keystore: unsafe key dir');
        $this->assertFileDoesNotExist($this->keyFile());
        $this->assertSame($mode, $this->mode($this->dir));
    }

    public function testSymlinkedDirIsRefused(): void
    {
        $real = $this->base.'/real';
        mkdir($real, 0700, true);
        symlink($real, $this->dir);

        $this->assertRefused(new KeyStore($this->dir, null), 'keystore: unsafe key dir');
        $this->assertFileDoesNotExist($real.'/secret.key');
    }

    // Kills dropping the chmod on an existing dir: 0755 is not writable by others but is still too open.
    public function testExistingReadableDirIsTightenedTo0700(): void
    {
        mkdir($this->dir, 0700, true);
        chmod($this->dir, 0755);

        (new KeyStore($this->dir, null))->rootKey();
        $this->assertSame(0700, $this->mode($this->dir));
    }

    // Kills dropping the tightening of an existing key file, on both the create and the read-only path.
    public function testExistingLooseKeyFileIsTightenedTo0600(): void
    {
        $bytes = random_bytes(32);
        $this->writeKeyFile($bytes);
        chmod($this->keyFile(), 0644);
        $this->assertSame($bytes, (new KeyStore($this->dir, null))->rootKey());
        $this->assertSame(0600, $this->mode($this->keyFile()));

        chmod($this->keyFile(), 0640);
        (new KeyStore($this->dir, base64_encode(random_bytes(32))))->allKeys();
        $this->assertSame(0600, $this->mode($this->keyFile()));
    }

    // Kills dropping the unlink on a failed write: a short write must not leave a partial key behind,
    // or every later request would fail closed on it.
    public function testAFailedWriteLeavesNoKeyFile(): void
    {
        stream_wrapper_register('mtnshort', ShortWriteStream::class);
        try {
            ShortWriteStream::$entries = ['mtnshort://k' => ['dir' => true, 'mode' => 0700, 'data' => '']];
            try {
                (new KeyStore('mtnshort://k', null))->rootKey();
                $this->fail('expected KeyStoreException');
            } catch (KeyStoreException $e) {
                $this->assertSame('keystore: cannot create key file', $e->getMessage());
            }
            $this->assertTrue(ShortWriteStream::$created, 'the key file was opened for creation');
            $this->assertArrayNotHasKey('mtnshort://k/secret.key', ShortWriteStream::$entries);
        } finally {
            stream_wrapper_unregister('mtnshort');
            ShortWriteStream::$created = false;
        }
    }

    // The env value never reaches a trace argument (#[\SensitiveParameter]). The constructor only stores it, so
    // its frame is never in a trace; the attribute is pinned directly for when a constructor throw is added.
    public function testABadEnvValueIsNotInTheTrace(): void
    {
        $param = new ReflectionParameter([KeyStore::class, '__construct'], 'envValue');
        $this->assertNotEmpty($param->getAttributes(SensitiveParameter::class));

        $env = base64_encode(random_bytes(31));
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            (new KeyStore($this->dir, $env))->rootKey();
            $this->fail('expected KeyStoreException');
        } catch (KeyStoreException $e) {
            $this->assertStringNotContainsString($env, (string) $e);
            $this->assertStringNotContainsString($env, self::matineeFrames($e));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function testDebugOutputCarriesNoKeyBytes(): void
    {
        $env = random_bytes(32);
        $store = new KeyStore($this->dir, base64_encode($env));
        $store->rootKey();
        $file = new KeyStore($this->base.'/file', null);
        $fileKey = $file->rootKey();

        foreach ([[$store, $env], [$file, $fileKey]] as [$subject, $key]) {
            ob_start();
            var_dump($subject);
            $dump = ob_get_clean().print_r($subject, true);
            $this->assertStringNotContainsString($key, $dump);
            $this->assertStringNotContainsString(bin2hex($key), $dump);
            $this->assertStringNotContainsString(base64_encode($key), $dump);
        }
    }

    public function testAWrongSizeRootIsNotInTheSubkeysTrace(): void
    {
        $root = random_bytes(16);
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            Subkeys::token($root);
            $this->fail('expected CryptoException');
        } catch (CryptoException $e) {
            $trace = (string) $e.self::matineeFrames($e);
            $this->assertStringNotContainsString($root, $trace);
            $this->assertStringNotContainsString(bin2hex($root), $trace);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function testSubkeysRejectAWrongSizeRoot(): void
    {
        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('subkeys: bad root key');
        Subkeys::token(random_bytes(16));
    }
}

/**
 * In-memory stream wrapper whose writes always come up short, so KeyStore's failed-write path can run.
 * Paths are whole URLs; entries hold dirs and files.
 */
class ShortWriteStream
{
    /** @var array<string, array{dir: bool, mode: int, data: string}> */
    public static array $entries = [];
    public static bool $created = false;

    /** @var resource|null */
    public $context;
    private string $path = '';
    private int $pos = 0;

    public function url_stat(string $path, int $flags): array|false
    {
        $e = self::$entries[$path] ?? null;
        if ($e === null) {
            return false;
        }
        $mode = ($e['dir'] ? 0040000 : 0100000) | $e['mode'];
        return ['mode' => $mode, 'size' => strlen($e['data'])] + array_fill(0, 13, 0);
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        self::$entries[$path] = ['dir' => true, 'mode' => $mode, 'data' => ''];
        return true;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        if ($option !== STREAM_META_ACCESS || !isset(self::$entries[$path])) {
            return false;
        }
        self::$entries[$path]['mode'] = $value;
        return true;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $exists = isset(self::$entries[$path]);
        if ($mode[0] === 'x' && $exists || $mode[0] === 'r' && !$exists) {
            return false;
        }
        if (!$exists) {
            self::$entries[$path] = ['dir' => false, 'mode' => 0644, 'data' => ''];
            if ($mode[0] === 'x') {
                self::$created = true;
            }
        }
        $this->path = $path;
        return true;
    }

    public function stream_write(string $data): int
    {
        return 0;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$entries[$this->path]['data'] ?? '', $this->pos, $count);
        $this->pos += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen(self::$entries[$this->path]['data'] ?? '');
    }

    public function stream_stat(): array|false
    {
        return $this->url_stat($this->path, 0);
    }

    public function stream_lock(int $operation): bool
    {
        return true;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    public function unlink(string $path): bool
    {
        unset(self::$entries[$path]);
        return true;
    }
}
