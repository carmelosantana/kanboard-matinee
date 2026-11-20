<?php

namespace Kanboard\Plugin\Matinee\Crypto;

use Kanboard\Plugin\Matinee\Http\PlexPaths;
use Kanboard\Plugin\Matinee\Model\MatineeForbiddenException;

/**
 * Signs and verifies refs (spec §6, light scope): "body.sig", both base64url without padding.
 *
 * - body = b64url(json({v:1, u, sv, s, k, r, exp})); sig = b64url(HMAC-SHA256(refKey, body)).
 * - verify checks the length and shape, then the signature (hash_equals), and only then decodes the body.
 *   The payload must have exactly the seven fields with their types, v = 1, u = the given user,
 *   now < exp ≤ now + MAX_TTL, a section key for s, and r matching kind k's PlexPaths regex.
 * - Every refusal is the same MatineeForbiddenException. sign() refuses bad input, so it never mints a ref
 *   that verify would refuse.
 */
final class RefSigner
{
    public const MAX_TTL = 3600;
    public const KINDS = ['browse', 'art'];
    private const MAX_REF = 2048;
    private const FIELDS = ['exp', 'k', 'r', 's', 'sv', 'u', 'v'];
    private const SHAPE = '/\A([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{43})\z/';

    /** @var callable(): int */
    private $clock;

    public function __construct(#[\SensitiveParameter] private string $refKey, ?callable $clock = null)
    {
        if (strlen($refKey) !== 32) {
            throw new CryptoException('refs: bad key');
        }
        $this->clock = $clock ?? time(...);
    }

    /** Redacted: var_dump and print_r never show the key. */
    public function __debugInfo(): array
    {
        return ['refKey' => '[redacted]'];
    }

    public function sign(int $userId, int $serverId, string $section, string $kind, string $resource, int $ttl): string
    {
        if ($userId < 1) {
            throw new \InvalidArgumentException('ref: bad user');
        }
        if ($serverId < 1) {
            throw new \InvalidArgumentException('ref: bad server');
        }
        if (! PlexPaths::isSectionKey($section)) {
            throw new \InvalidArgumentException('ref: bad section');
        }
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('ref: bad kind');
        }
        if (! self::resourceFits($kind, $resource)) {
            throw new \InvalidArgumentException('ref: bad resource');
        }
        if ($ttl < 1 || $ttl > self::MAX_TTL) {
            throw new \InvalidArgumentException('ref: bad ttl');
        }
        $payload = ['v' => 1, 'u' => $userId, 'sv' => $serverId, 's' => $section, 'k' => $kind, 'r' => $resource, 'exp' => $this->now() + $ttl];
        $body = self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $body.'.'.$this->mac($body);
    }

    /**
     * @return array{sv: int, s: string, k: string, r: string}
     * @throws MatineeForbiddenException
     */
    public function verify(string $ref, int $userId): array
    {
        if (strlen($ref) > self::MAX_REF || preg_match(self::SHAPE, $ref, $m) !== 1) {
            throw new MatineeForbiddenException();
        }
        [, $body, $sig] = $m;
        if (! hash_equals($this->mac($body), $sig)) {
            throw new MatineeForbiddenException();
        }

        try {
            $p = json_decode(sodium_base642bin($body, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), true, 2, JSON_THROW_ON_ERROR);
        } catch (\SodiumException | \JsonException) {
            throw new MatineeForbiddenException();
        }
        if (! is_array($p) || array_is_list($p)) {
            throw new MatineeForbiddenException();
        }
        $keys = array_keys($p);
        sort($keys, SORT_STRING);
        if ($keys !== self::FIELDS) {
            throw new MatineeForbiddenException();
        }

        $now = $this->now();
        $ok = $p['v'] === 1
            && is_int($p['u']) && $p['u'] === $userId
            && is_int($p['sv']) && $p['sv'] > 0
            && is_int($p['exp']) && $p['exp'] > $now && $p['exp'] <= $now + self::MAX_TTL
            && is_string($p['s']) && PlexPaths::isSectionKey($p['s'])
            && is_string($p['k']) && in_array($p['k'], self::KINDS, true)
            && is_string($p['r']) && self::resourceFits($p['k'], $p['r']);
        if (! $ok) {
            throw new MatineeForbiddenException();
        }
        return ['sv' => $p['sv'], 's' => $p['s'], 'k' => $p['k'], 'r' => $p['r']];
    }

    private static function resourceFits(string $kind, string $resource): bool
    {
        return match ($kind) {
            'browse' => PlexPaths::isBrowse($resource),
            'art' => PlexPaths::isArt($resource),
            default => false,
        };
    }

    private function mac(string $body): string
    {
        return self::b64(hash_hmac('sha256', $body, $this->refKey, true));
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    private static function b64(string $bin): string
    {
        return sodium_bin2base64($bin, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }
}
