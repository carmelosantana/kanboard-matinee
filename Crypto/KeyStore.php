<?php

namespace Kanboard\Plugin\Matinee\Crypto;

/**
 * Resolves Matinee's 32-byte root key.
 *
 * - A non-null $envValue (the caller passes getenv('MATINEE_KEY') ?: null) must be base64 of exactly
 *   32 bytes, or every call fails closed. When valid it is the active key.
 * - Otherwise the key lives in "$dir/secret.key". It is created once, under an flock on "$dir/.lock",
 *   with fopen 'x' (never over an existing file), chmod 0600 before the write, then re-read so the bytes
 *   on disk win. A key file of the wrong size fails closed and is never replaced; a failed write removes
 *   the partial file it created.
 * - An existing key dir that is a symlink or group/world-writable is refused, otherwise it is tightened
 *   to 0700. A symlinked key file or lock file is refused; a key file readable by others is tightened to 0600.
 *
 * Exception messages are fixed strings and never carry key bytes or paths.
 */
final class KeyStore
{
    private const KEY_BYTES = 32;
    private const FILE = 'secret.key';

    private ?string $envKey = null;
    private ?string $fileKey = null;

    public function __construct(private string $dir, #[\SensitiveParameter] private ?string $envValue)
    {
    }

    /** The active root key: the env key when set, otherwise the key file (created on first use). */
    public function rootKey(): string
    {
        return $this->envKey() ?? $this->fileKey(true);
    }

    /** Where the active key comes from: "env" or "file". */
    public function keyId(): string
    {
        return $this->envKey() !== null ? 'env' : 'file';
    }

    /** The key id (see keyIdFor) of the active root key. */
    public function activeKeyId(): string
    {
        return self::keyIdFor($this->rootKey());
    }

    /**
     * Every configured key, keyed by key id: the env key when set and the key file when it exists.
     * With no env key the file is created, so the result is never empty.
     *
     * @return array<string, string>
     */
    public function allKeys(): array
    {
        $keys = [];
        $env = $this->envKey();
        if ($env !== null) {
            $keys[self::keyIdFor($env)] = $env;
        }
        $file = $this->fileKey($env === null);
        if ($file !== null) {
            $keys[self::keyIdFor($file)] = $file;
        }
        return $keys;
    }

    /**
     * First 8 hex chars of BLAKE2b keyed by the root over the message "mtn-kid".
     * sodium_crypto_generichash($message, $key): the root is the key (a PRF of the root, so the id
     * reveals nothing about it). The reverse order is not possible: a BLAKE2b key must be 16–64 bytes.
     */
    public static function keyIdFor(#[\SensitiveParameter] string $root): string
    {
        return substr(bin2hex(sodium_crypto_generichash('mtn-kid', $root)), 0, 8);
    }

    private function envKey(): ?string
    {
        if ($this->envValue === null) {
            return null;
        }
        if ($this->envKey === null) {
            try {
                $key = sodium_base642bin($this->envValue, SODIUM_BASE64_VARIANT_ORIGINAL, " \t\r\n");
            } catch (\SodiumException) {
                throw new CryptoException('keystore: bad env key');
            }
            if (strlen($key) !== self::KEY_BYTES) {
                sodium_memzero($key);
                throw new CryptoException('keystore: bad env key');
            }
            $this->envKey = $key;
        }
        return $this->envKey;
    }

    /** Redacted: var_dump and print_r never show key material, the env value or the path. */
    public function __debugInfo(): array
    {
        return [
            'envKey' => $this->envValue === null ? null : '[redacted]',
            'fileKey' => $this->fileKey === null ? null : '[redacted]',
        ];
    }

    private function fileKey(bool $create): ?string
    {
        if ($this->fileKey !== null) {
            return $this->fileKey;
        }
        $path = $this->dir.'/'.self::FILE;
        // fopen 'x' and file_get_contents would follow a planted symlink.
        if (is_link($path)) {
            throw new CryptoException('keystore: unsafe key file');
        }
        if (!$create) {
            return file_exists($path) ? $this->fileKey = $this->readKeyFile($path) : null;
        }

        $this->ensureDir();
        $lockPath = $this->dir.'/.lock';
        // fopen 'c' follows a symlink and would create its target.
        $lock = is_link($lockPath) ? false : @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new CryptoException('keystore: cannot lock key dir');
        }
        try {
            if (!file_exists($path)) {
                $this->createKeyFile($path);
            }
            return $this->fileKey = $this->readKeyFile($path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * An existing dir must be a real directory that only its owner can write; it is then tightened to 0700.
     * A new one is created and set to 0700 exactly (mkdir's mode is filtered by the umask).
     */
    private function ensureDir(): void
    {
        if (is_link($this->dir)) {
            throw new CryptoException('keystore: unsafe key dir');
        }
        if (is_dir($this->dir)) {
            if ((fileperms($this->dir) & 0022) !== 0 || !@chmod($this->dir, 0700)) {
                throw new CryptoException('keystore: unsafe key dir');
            }
            return;
        }
        if (!@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new CryptoException('keystore: cannot create key dir');
        }
        if (!@chmod($this->dir, 0700)) {
            throw new CryptoException('keystore: cannot create key dir');
        }
    }

    /**
     * Runs under the lock with no key file present, so a file this call created is its own to remove on failure.
     */
    private function createKeyFile(string $path): void
    {
        $fh = @fopen($path, 'x');
        if ($fh === false) {
            if (file_exists($path)) {
                return; // Someone else created it; the caller reads what is on disk.
            }
            throw new CryptoException('keystore: cannot create key file');
        }
        // fopen's mode is 0666 filtered by the umask; narrow it before any key byte is written.
        $ok = @chmod($path, 0600);
        if ($ok) {
            $key = random_bytes(self::KEY_BYTES);
            $ok = fwrite($fh, $key) === self::KEY_BYTES && fflush($fh) && @fsync($fh);
            sodium_memzero($key);
        }
        $ok = fclose($fh) && $ok;
        if (!$ok) {
            @unlink($path);
            throw new CryptoException('keystore: cannot create key file');
        }
    }

    private function readKeyFile(string $path): string
    {
        $mode = @fileperms($path);
        if ($mode !== false && ($mode & 0077) !== 0 && !@chmod($path, 0600)) {
            throw new CryptoException('keystore: unsafe key file');
        }
        $key = @file_get_contents($path);
        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            if (is_string($key)) {
                sodium_memzero($key);
            }
            throw new CryptoException('keystore: bad key file');
        }
        return $key;
    }
}
