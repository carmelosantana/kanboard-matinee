<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * Removes Plex tokens from a Plex-returned URI before Matinee reuses it (spec §3).
 *
 * A query pair is dropped when its key, percent-decoded once (`+` as space, the way a server reads it) and with
 * any trailing `[...]` (PHP's array form) removed, equals `x-plex-token` ignoring case. So `X-PLEX-TOKEN`,
 * `X%2DPlex%2DToken` and `X-Plex-Token[]` all go, while `X-Plex-Token2` or a value of `X-Plex-Token` stay. The
 * same rule runs over the fragment, in case a token was parked there. Every other pair is kept byte for byte, in
 * order. When nothing is removed the URI comes back unchanged; when something is, empty pairs are dropped too,
 * and a query or fragment left empty loses its `?` / `#`.
 *
 * @package Kanboard\Plugin\Matinee\Http
 */
final class PlexScrub
{
    public static function stripToken(string $url): string
    {
        $fragment = null;
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = substr($url, $hash + 1);
            $url = substr($url, 0, $hash);
        }

        $query = null;
        $q = strpos($url, '?');
        if ($q !== false) {
            $query = substr($url, $q + 1);
            $url = substr($url, 0, $q);
        }

        return $url . self::part('?', $query) . self::part('#', $fragment);
    }

    private static function part(string $sigil, ?string $pairs): string
    {
        if ($pairs === null) {
            return '';
        }
        $all = explode('&', $pairs);
        $kept = array_values(array_filter($all, fn (string $pair) => ! self::isToken($pair)));
        if (count($kept) === count($all)) {
            return $sigil . $pairs;
        }
        $kept = array_filter($kept, fn (string $pair) => $pair !== '');
        return $kept === [] ? '' : $sigil . implode('&', $kept);
    }

    private static function isToken(string $pair): bool
    {
        $key = urldecode(explode('=', $pair, 2)[0]);
        return preg_match('/\Ax-plex-token(\[[^\]]*\])?\z/i', $key) === 1;
    }
}
