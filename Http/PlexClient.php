<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * Talks to one Plex server (spec §3, P1 surface): `identity()` and `sections()`, with whitelisted parsing.
 *
 * - The token travels only in the `X-Plex-Token` header, never in a URL.
 * - Errors are PlexException with a fixed reason; upstream bodies and transport messages never surface (§11 S9).
 * - Bodies over 2 MiB are refused and JSON is decoded with depth 32 (§11 S11).
 * - With a pinned `machineId`, any response whose `MediaContainer.machineIdentifier` differs is
 *   `plex.server_changed`.
 * - The header set (with the token) is kept outside the object, in a class-private WeakMap, so var_export, an
 *   `(array)` cast, get_object_vars and json_encode never see it; var_dump / print_r go through __debugInfo. A
 *   client cannot be serialized, unserialized or cloned.
 * - The device name is `Matinee` or exactly what deviceName() produces (S14).
 *
 * @package Kanboard\Plugin\Matinee\Http
 */
final class PlexClient
{
    public const PRODUCT = 'Matinee';
    public const MAX_BODY_BYTES = 2097152;
    public const JSON_DEPTH = 32;
    public const SECTION_TYPES = ['movie', 'show', 'artist', 'photo'];
    public const MAX_TITLE_CHARS = 200;

    private const MACHINE_ID = '/\A[A-Za-z0-9-]{1,64}\z/';
    private const VERSION = '/\A[A-Za-z0-9._+-]{1,64}\z/';
    private const SECTION_KEY = '/\A\d{1,20}\z/';
    private const DEVICE_NAME = '/\AMatinee( \([A-Za-z0-9._ #-]{1,40}\))?\z/';

    private readonly string $baseUrl;

    /** @var \WeakMap<self, array<string, string>>|null header sets by client; never a property of the client */
    private static ?\WeakMap $headers = null;

    public function __construct(
        string $baseUrl,
        #[\SensitiveParameter] string $token,
        string $clientId,
        private readonly PlexTransport $transport,
        private readonly ?string $machineId = null,
        string $deviceName = self::PRODUCT,
    ) {
        if (strpbrk($baseUrl, '?#') !== false) {
            throw new \InvalidArgumentException('The Plex base URL must not carry a query string or fragment.');
        }
        foreach ([$token, $clientId] as $value) {
            if ($value === '' || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new \InvalidArgumentException('A Plex header value is empty or holds a control character.');
            }
        }
        if (preg_match(self::DEVICE_NAME, $deviceName) !== 1) {
            throw new \InvalidArgumentException('The Plex device name must be Matinee or come from deviceName().');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        self::$headers ??= new \WeakMap();
        self::$headers[$this] = [
            'X-Plex-Token'             => $token,
            'X-Plex-Client-Identifier' => $clientId,
            'X-Plex-Product'           => self::PRODUCT,
            'X-Plex-Device-Name'       => $deviceName,
            'Accept'                   => 'application/json',
        ];
    }

    /** var_dump / print_r never show the token. */
    public function __debugInfo(): array
    {
        return [
            'baseUrl'   => $this->baseUrl,
            'machineId' => $this->machineId,
            'headers'   => ['X-Plex-Token' => '[redacted]'] + $this->headers(),
        ];
    }

    public function __serialize(): array
    {
        throw new \LogicException('A PlexClient holds a token and cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException('A PlexClient cannot be unserialized.');
    }

    /** A clone would have no header set; build a new client instead. */
    private function __clone()
    {
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return self::$headers[$this];
    }

    /** S14:`Matinee (<username filtered to [A-Za-z0-9._ -], ≤40>)`, or `Matinee (user #<id>)` when nothing is left. */
    public static function deviceName(string $username, int $userId): string
    {
        $name = trim(substr((string) preg_replace('/[^A-Za-z0-9._ -]/', '', $username), 0, 40));
        return self::PRODUCT . ' (' . ($name === '' ? 'user #' . $userId : $name) . ')';
    }

    /** @return array{machineIdentifier: string, version: string} */
    public function identity(): array
    {
        $mc = $this->fetch('/identity');

        $id = $mc['machineIdentifier'] ?? null;
        if (! is_string($id) || preg_match(self::MACHINE_ID, $id) !== 1) {
            throw PlexException::badJson();
        }
        $version = $mc['version'] ?? null;

        return [
            'machineIdentifier' => $id,
            'version'           => is_string($version) && preg_match(self::VERSION, $version) === 1 ? $version : '',
        ];
    }

    /** @return list<array{key: string, title: string, type: string}> */
    public function sections(): array
    {
        $dirs = $this->fetch('/library/sections')['Directory'] ?? null;
        if (! is_array($dirs) || ! array_is_list($dirs)) {
            return [];
        }

        $out = [];
        foreach ($dirs as $d) {
            if (! is_array($d)) {
                continue;
            }
            $key = $d['key'] ?? null;
            $key = is_int($key) ? (string) $key : $key;
            $type = $d['type'] ?? null;
            if (! is_string($key) || preg_match(self::SECTION_KEY, $key) !== 1
                || ! is_string($type) || ! in_array($type, self::SECTION_TYPES, true)) {
                continue;
            }
            $title = $d['title'] ?? '';
            $title = is_scalar($title) ? (string) $title : '';
            $out[] = ['key' => $key, 'title' => mb_substr($title, 0, self::MAX_TITLE_CHARS, 'UTF-8'), 'type' => $type];
        }
        return $out;
    }

    /** GET a path, check status, size and JSON, and return `MediaContainer` (after the pinned-server check). */
    private function fetch(string $path): array
    {
        try {
            $r = $this->transport->get($this->baseUrl . $path, $this->headers());
        } catch (PlexException | UnsafeUrlException $e) {
            throw $e;
        } catch (\Throwable) {
            throw PlexException::unreachable();
        }

        if ($r->status === 401) {
            throw PlexException::tokenRejected();
        }
        if ($r->status < 200 || $r->status > 299) {
            throw PlexException::httpError($r->status);
        }
        if (strlen($r->body) > self::MAX_BODY_BYTES) {
            throw PlexException::tooLarge();
        }

        try {
            $data = json_decode($r->body, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw PlexException::badJson();
        }
        $mc = is_array($data) ? ($data['MediaContainer'] ?? null) : null;
        if (! is_array($mc)) {
            throw PlexException::badJson();
        }

        if ($this->machineId !== null && array_key_exists('machineIdentifier', $mc)
            && $mc['machineIdentifier'] !== $this->machineId) {
            throw PlexException::serverChanged();
        }
        return $mc;
    }
}
