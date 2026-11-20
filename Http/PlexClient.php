<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * Talks to one Plex server (spec §3): `identity()`, `sections()`, `children()` (browse listings) and `art()`
 * (posters through Plex's photo transcoder), with whitelisted parsing.
 *
 * - The token travels only in the `X-Plex-Token` header, never in a URL.
 * - Errors are PlexException with a fixed reason; upstream bodies and transport messages never surface (§11 S9).
 * - Bodies over 2 MiB are refused and JSON is decoded with depth 32 (§11 S11). A JSON body with more than
 *   MAX_JSON_CONTAINERS `{` and `[` (counted over the raw bytes, so braces inside strings count too) is refused as
 *   too large before it is decoded: each decoded array costs PHP hundreds of bytes, so ~262k `{"a":1}` under the
 *   2 MiB cap would decode to ~106 MiB. Capped, a 2 MiB body decodes to under ~30 MiB.
 * - With a pinned `machineId`, any response whose `MediaContainer.machineIdentifier` differs is
 *   `plex.server_changed`.
 * - The header set (with the token) is kept outside the object, in a class-private WeakMap, so var_export, an
 *   `(array)` cast, get_object_vars and json_encode never see it; var_dump / print_r go through __debugInfo. A
 *   client cannot be serialized, unserialized or cloned.
 * - The device name is `Matinee` or exactly what deviceName() produces (S14).
 * - `children()` and `art()` take only a path that matches PlexPaths (browse / art); anything else is refused
 *   before a request is made. Paging and transcode params are built with http_build_query, never with the token.
 *
 * @package Kanboard\Plugin\Matinee\Http
 */
final class PlexClient
{
    public const PRODUCT = 'Matinee';
    public const MAX_BODY_BYTES = 2097152;
    public const JSON_DEPTH = 32;
    /**
     * A 60-item page of full Plex metadata holds ~20 containers per item (Media, Part, tag lists), so ~1.2k; even a
     * MAX_PAGE_SIZE page Plex over-fills is a few thousand. Sections and identity are far smaller.
     */
    public const MAX_JSON_CONTAINERS = 20000;
    public const SECTION_TYPES = ['movie', 'show', 'artist', 'photo'];
    public const MAX_TITLE_CHARS = 200;
    public const ITEM_TYPES = ['movie', 'show', 'season', 'episode', 'artist', 'album', 'track', 'photo'];
    public const MAX_PAGE_SIZE = 200;
    /** Ruling B1: 1080 waits for the P3 detail view. */
    public const ART_SIZES = [144, 512];
    public const ART_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const MACHINE_ID = '/\A[A-Za-z0-9-]{1,64}\z/';
    private const VERSION = '/\A[A-Za-z0-9._+-]{1,64}\z/';
    private const DEVICE_NAME = '/\AMatinee( \([A-Za-z0-9._ #-]{1,40}\))?\z/';
    /** A non-negative integer that fits an int on 64-bit PHP. */
    private const UINT = '/\A\d{1,18}\z/';

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
            if (! is_string($key) || ! PlexPaths::isSectionKey($key)
                || ! is_string($type) || ! in_array($type, self::SECTION_TYPES, true)) {
                continue;
            }
            $out[] = ['key' => $key, 'title' => self::text($d['title'] ?? ''), 'type' => $type];
        }
        return $out;
    }

    /**
     * One page of a browse listing (`MediaContainer.Metadata`), whitelisted (§11 S9, S13).
     *
     * - `section` is `MediaContainer.librarySectionID` as a string, or null when absent or not a section key.
     * - `total` is `MediaContainer.totalSize` when it is a non-negative integer; otherwise Plex did not page the
     *   answer, so it is `start` + the number of entries kept (no further page is implied).
     * - At most `size` entries are kept, even when Plex returns more.
     * - Every kept item has every field: strings default to '', integers and paths to null. An item without a
     *   digit `ratingKey` or an allowed `type` is dropped. `thumb` is kept only when it is an art path and `key`
     *   only when it is a browse path.
     *
     * @return array{section: ?string, total: int, items: list<array{ratingKey: string, type: string, title: string,
     *   parentTitle: string, grandparentTitle: string, year: ?int, index: ?int, duration: ?int, leafCount: ?int,
     *   thumb: ?string, key: ?string}>}
     */
    public function children(string $path, int $start, int $size): array
    {
        if (! PlexPaths::isBrowse($path)) {
            throw new \InvalidArgumentException('A Plex listing path must be a browse path.');
        }
        if ($size < 1 || $size > self::MAX_PAGE_SIZE || $start < 0) {
            throw new \InvalidArgumentException('A Plex listing page is out of range.');
        }

        $mc = $this->fetch($path, ['X-Plex-Container-Start' => $start, 'X-Plex-Container-Size' => $size]);

        $section = $mc['librarySectionID'] ?? null;
        $section = is_int($section) ? (string) $section : $section;
        $section = is_string($section) && PlexPaths::isSectionKey($section) ? $section : null;

        $raw = $mc['Metadata'] ?? null;
        // Plex may ignore X-Plex-Container-Size; never keep more than the page asked for.
        $raw = is_array($raw) && array_is_list($raw) ? array_slice($raw, 0, $size) : [];

        $items = [];
        foreach ($raw as $m) {
            if (is_array($m) && ($item = self::item($m)) !== null) {
                $items[] = $item;
            }
        }

        return [
            'section' => $section,
            'total'   => self::uint($mc['totalSize'] ?? null) ?? $start + count($raw),
            'items'   => $items,
        ];
    }

    /**
     * A poster or backdrop at a fixed square size, through Plex's photo transcoder (Veranda's URL; ruling B6:
     * buffered). The body is returned as is: it is never decoded, and the pinned-server check does not apply.
     *
     * @return array{type: string, body: string}
     */
    public function art(string $path, int $size): array
    {
        if (! PlexPaths::isArt($path)) {
            throw new \InvalidArgumentException('A Plex art path must be an art path.');
        }
        if (! in_array($size, self::ART_SIZES, true)) {
            throw new \InvalidArgumentException('A Plex art size must be 144 or 512.');
        }

        $r = $this->request('/photo/:/transcode', [
            'width' => $size, 'height' => $size, 'minSize' => 1, 'upscale' => 0, 'url' => $path,
        ]);

        $type = strtolower(trim(explode(';', $r->headers['content-type'] ?? '', 2)[0]));
        if (! in_array($type, self::ART_TYPES, true)) {
            throw PlexException::badType();
        }
        return ['type' => $type, 'body' => $r->body];
    }

    /** One whitelisted listing item, or null to drop it. */
    private static function item(array $m): ?array
    {
        $rk = $m['ratingKey'] ?? null;
        $rk = is_int($rk) ? (string) $rk : $rk;
        $type = $m['type'] ?? null;
        if (! is_string($rk) || ! PlexPaths::isSectionKey($rk)
            || ! is_string($type) || ! in_array($type, self::ITEM_TYPES, true)) {
            return null;
        }

        $item = ['ratingKey' => $rk, 'type' => $type];
        foreach (['title', 'parentTitle', 'grandparentTitle'] as $f) {
            $item[$f] = self::text($m[$f] ?? '');
        }
        foreach (['year', 'index', 'duration', 'leafCount'] as $f) {
            $item[$f] = self::uint($m[$f] ?? null);
        }
        $thumb = $m['thumb'] ?? null;
        $item['thumb'] = is_string($thumb) && PlexPaths::isArt($thumb) ? $thumb : null;
        $key = $m['key'] ?? null;
        $item['key'] = is_string($key) && PlexPaths::isBrowse($key) ? $key : null;
        return $item;
    }

    /**
     * A display string: a scalar cast to string with control and format characters (\p{Cc}, \p{Cf}: tabs,
     * newlines, bidi overrides, zero-width spaces) removed, then cut to MAX_TITLE_CHARS; '' when it is not valid
     * UTF-8. The strip runs first because mb_substr would quietly turn invalid bytes into '?'.
     */
    private static function text(mixed $v): string
    {
        $s = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', is_scalar($v) ? (string) $v : '') ?? '';
        return mb_substr($s, 0, self::MAX_TITLE_CHARS, 'UTF-8');
    }

    /** A non-negative int from an int or a digit string, else null. */
    private static function uint(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v >= 0 ? $v : null;
        }
        return is_string($v) && preg_match(self::UINT, $v) === 1 ? (int) $v : null;
    }

    /** GET a path, check status, size and JSON, and return `MediaContainer` (after the pinned-server check). */
    private function fetch(string $path, array $query = []): array
    {
        $r = $this->request($path, $query);
        if (substr_count($r->body, '{') + substr_count($r->body, '[') > self::MAX_JSON_CONTAINERS) {
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

    /**
     * GET a path (with a query built by http_build_query, never holding the token) and check status and size.
     * On a non-2xx the body is not read.
     */
    private function request(string $path, array $query = []): PlexResponse
    {
        $url = $this->baseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        try {
            $r = $this->transport->get($url, $this->headers());
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
        return $r;
    }
}
