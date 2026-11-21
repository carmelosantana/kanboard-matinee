<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * The only Plex paths a ref may name (spec §6, S13). One place, used both when minting a ref and when using it.
 *
 * Anchored with \A…\z (so no trailing newline), ASCII digits only, no decoding: "%2e", "..", "?", "//" and a
 * trailing "/" simply fail to match.
 */
final class PlexPaths
{
    /**
     * A section's items, one item's children, or an artist's albums (the section filtered by artist: Plex's artist
     * children leave out compilations and EPs). The artist query is the one fixed shape artistAlbums() builds.
     */
    public const BROWSE_RE = '#\A/library/(?:sections/\d{1,20}/all(?:\?type=9&artist\.id=\d{1,20})?|metadata/\d{1,20}/children)\z#';
    /** Veranda's ART_PATH_RE. */
    public const ART_RE = '#\A/library/metadata/\d{1,20}/(?:thumb|art)/\d{1,20}\z#';
    public const SECTION_KEY_RE = '#\A\d{1,20}\z#';
    /** Spec §6 `media`: a direct part. */
    public const PART_RE = '#\A/library/parts/\d{1,20}/\d{1,20}/file\.[a-z0-9]{1,8}\z#';
    public const ITEM_RE = '#\A/library/metadata/\d{1,20}\z#';
    /** S2: a playlist, segment or subtitle under one transcode session. */
    public const HLS_CHILD_RE = '#\A/video/:/transcode/universal/session/[0-9a-f-]{36}/[A-Za-z0-9/_.-]{1,200}\.(?:m3u8|ts|m4s|vtt|aac)(?:\?[A-Za-z0-9=&_.-]{0,512})?\z#';
    public const HLS_PREFIX = '/video/:/transcode/universal/';
    /** A session uuid Matinee mints: lowercase v4. */
    public const SESSION_ID_RE = '#\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z#';
    /** Refused anywhere in an HLS child, whatever the regex allows. */
    private const HLS_FORBIDDEN = ['..', '%', '@', '\\', '//'];

    public static function isBrowse(string $path): bool
    {
        return preg_match(self::BROWSE_RE, $path) === 1;
    }

    public static function isArt(string $path): bool
    {
        return preg_match(self::ART_RE, $path) === 1;
    }

    public static function isSectionKey(string $key): bool
    {
        return preg_match(self::SECTION_KEY_RE, $key) === 1;
    }

    /** "/library/sections/{key}/all" for a valid section key. */
    public static function sectionAll(string $key): string
    {
        if (! self::isSectionKey($key)) {
            throw new \InvalidArgumentException('plex paths: bad section key');
        }
        return '/library/sections/'.$key.'/all';
    }

    /** "/library/sections/{section}/all?type=9&artist.id={artist}": every album (type 9) of one artist. */
    public static function artistAlbums(string $section, string $artist): string
    {
        if (! self::isSectionKey($section) || ! self::isSectionKey($artist)) {
            throw new \InvalidArgumentException('plex paths: bad section or artist key');
        }
        return self::sectionAll($section).'?type=9&artist.id='.$artist;
    }

    public static function isPart(string $path): bool
    {
        return preg_match(self::PART_RE, $path) === 1;
    }

    public static function isItem(string $path): bool
    {
        return preg_match(self::ITEM_RE, $path) === 1;
    }

    /** "/library/metadata/{ratingKey}" for a valid rating key. */
    public static function item(string $ratingKey): string
    {
        if (preg_match(self::SECTION_KEY_RE, $ratingKey) !== 1) {
            throw new \InvalidArgumentException('plex paths: bad rating key');
        }
        return '/library/metadata/'.$ratingKey;
    }

    /**
     * An HLS child path (S2): the regex, none of the forbidden substrings, no X-Plex query key, and nothing PlexScrub
     * would strip. A token-bearing `u` is refused, never cleaned.
     */
    public static function isHlsChild(string $u): bool
    {
        if (preg_match(self::HLS_CHILD_RE, $u) !== 1) {
            return false;
        }
        foreach (self::HLS_FORBIDDEN as $bad) {
            if (str_contains($u, $bad)) {
                return false;
            }
        }
        if (self::hasPlexHeaderKey($u)) {
            return false;
        }
        try {
            return PlexScrub::stripToken($u) === $u;
        } catch (PlexException) {
            return false;
        }
    }

    /** The session uuid of an HLS child path, or null when it is not one or the segment is not a v4 id Matinee mints. */
    public static function hlsSession(string $u): ?string
    {
        if (! self::isHlsChild($u)) {
            return null;
        }
        $s = substr($u, strlen(self::HLS_PREFIX.'session/'), 36);
        return self::isSessionId($s) ? $s : null;
    }

    /**
     * A query key that reads as an X-Plex header (lowercased, "-", "_" and "." removed, it starts with "xplex"): Plex takes
     * those from the query too, so a viewer could relabel their own requests (X-Plex-Client-Identifier and the like).
     */
    private static function hasPlexHeaderKey(string $u): bool
    {
        $q = strpos($u, '?');
        if ($q === false) {
            return false;
        }
        foreach (explode('&', substr($u, $q + 1)) as $pair) {
            $key = str_replace(['-', '_', '.'], '', strtolower(explode('=', $pair, 2)[0]));
            if (str_starts_with($key, 'xplex')) {
                return true;
            }
        }
        return false;
    }

    public static function isSessionId(string $id): bool
    {
        return preg_match(self::SESSION_ID_RE, $id) === 1;
    }
}
