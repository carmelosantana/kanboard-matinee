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

    private readonly string $baseUrl;

    /** @var array<string, string> */
    private readonly array $headers;

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
        foreach ([$token, $clientId, $deviceName] as $value) {
            if ($value === '' || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new \InvalidArgumentException('A Plex header value is empty or holds a control character.');
            }
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->headers = [
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
            'headers'   => ['X-Plex-Token' => '[redacted]'] + $this->headers,
        ];
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
            $r = $this->transport->get($this->baseUrl . $path, $this->headers);
        } catch (PlexException | UnsafeUrlException $e) {
            throw $e;
        } catch (\Exception) {
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
