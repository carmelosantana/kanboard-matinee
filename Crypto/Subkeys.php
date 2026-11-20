<?php

namespace Kanboard\Plugin\Matinee\Crypto;

/** Purpose-bound subkeys of the root key (libsodium KDF; each context is exactly 8 bytes). */
final class Subkeys
{
    public static function token(#[\SensitiveParameter] string $root): string
    {
        return self::derive(1, 'mtntoken', $root);
    }

    public static function refs(#[\SensitiveParameter] string $root): string
    {
        return self::derive(2, 'mtnrefs_', $root);
    }

    private static function derive(int $id, string $context, #[\SensitiveParameter] string $root): string
    {
        if (strlen($root) !== SODIUM_CRYPTO_KDF_KEYBYTES) {
            throw new CryptoException('subkeys: bad root key');
        }
        return sodium_crypto_kdf_derive_from_key(32, $id, $context, $root);
    }
}
