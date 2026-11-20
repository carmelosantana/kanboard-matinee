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
    public const BROWSE_RE = '#\A/library/(?:sections/\d{1,20}/all|metadata/\d{1,20}/children)\z#';
    /** Veranda's ART_PATH_RE. */
    public const ART_RE = '#\A/library/metadata/\d{1,20}/(?:thumb|art)/\d{1,20}\z#';
    public const SECTION_KEY_RE = '#\A\d{1,20}\z#';

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
}
