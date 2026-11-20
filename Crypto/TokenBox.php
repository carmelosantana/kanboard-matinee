<?php

namespace Kanboard\Plugin\Matinee\Crypto;

/**
 * Seals a Plex token to its server row with XChaCha20-Poly1305-IETF and a fresh 24-byte nonce.
 *
 * AD (spec §11 S7): "matinee:token:v1|{serverId}|{ownerId}|{baseUrl}|{machineId}". Changing the base URL
 * or machine id therefore needs a re-seal. The machine id may not contain "|", so the AD splits one way
 * only (the ids are integers and the machine id is last).
 *
 * Nonce and ciphertext travel as standard base64. Every open failure throws the same fixed message.
 */
final class TokenBox
{
    private const AD_PREFIX = 'matinee:token:v1';

    public function __construct(#[\SensitiveParameter] private string $tokenKey)
    {
        if (strlen($tokenKey) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new CryptoException('token: bad key');
        }
    }

    /** Redacted: var_dump and print_r never show the key. */
    public function __debugInfo(): array
    {
        return ['tokenKey' => '[redacted]'];
    }

    /** @return array{nonce: string, ct: string} */
    public function seal(#[\SensitiveParameter] string $token, int $serverId, int $ownerId, string $baseUrl, string $machineId): array
    {
        if (str_contains($machineId, '|')) {
            throw new CryptoException('token: bad machine id');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $token,
            self::ad($serverId, $ownerId, $baseUrl, $machineId),
            $nonce,
            $this->tokenKey
        );
        return ['nonce' => base64_encode($nonce), 'ct' => base64_encode($ct)];
    }

    public function open(string $nonce, string $ct, int $serverId, int $ownerId, string $baseUrl, string $machineId): string
    {
        $rawNonce = base64_decode($nonce, true);
        $rawCt = base64_decode($ct, true);
        if (
            str_contains($machineId, '|')
            || $rawNonce === false || $rawCt === false
            || strlen($rawNonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
        ) {
            throw new CryptoException('token: cannot open');
        }
        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $rawCt,
                self::ad($serverId, $ownerId, $baseUrl, $machineId),
                $rawNonce,
                $this->tokenKey
            );
        } catch (\SodiumException) {
            $plain = false;
        }
        if ($plain === false) {
            throw new CryptoException('token: cannot open');
        }
        return $plain;
    }

    private static function ad(int $serverId, int $ownerId, string $baseUrl, string $machineId): string
    {
        return implode('|', [self::AD_PREFIX, $serverId, $ownerId, $baseUrl, $machineId]);
    }
}
