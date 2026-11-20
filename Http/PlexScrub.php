<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * Removes Plex tokens from a Plex-returned URI before Matinee reuses it (spec §3), and refuses the URI when it
 * cannot be sure none is left.
 *
 * Query and fragment pairs are split on `&` and `;`. A pair is dropped when its key, normalised, is `xplextoken`.
 * Normalising means: percent-decode (`+` as space) until stable, at most three rounds; lowercase; keep only
 * `a`–`z`. So `X-PLEX-TOKEN`, `X%252DPlex%252DToken`, `X_Plex_Token`, `X-Plex-Token[]`, `X-Plex-Token%00` and
 * ` X-Plex-Token+` all go. Every other pair is kept byte for byte, in order, each with the separator it came with.
 * When nothing is removed the URI comes back unchanged; when something is, empty pairs are dropped too, and a query
 * or fragment left empty loses its `?` / `#`.
 *
 * Then the whole remaining URI is normalised the same way. If `xplextoken` still appears anywhere (a token nested
 * in a value such as `?url=%2Fx%3FX-Plex-Token%3D…`, a longer key, the path), or the URI is still changing after
 * three decoding rounds, `stripToken` throws `PlexException::tokenInUri()` rather than guess. This fails closed:
 * harmless text that merely spells the name is refused too. Callers drop a refused URI; they never fall back to
 * the input.
 *
 * @package Kanboard\Plugin\Matinee\Http
 */
final class PlexScrub
{
    private const NEEDLE = 'xplextoken';
    private const DECODE_ROUNDS = 3;

    /** @throws PlexException `plex.token_in_uri` when token-like text survives stripping */
    public static function stripToken(string $uri): string
    {
        $fragment = null;
        $hash = strpos($uri, '#');
        if ($hash !== false) {
            $fragment = substr($uri, $hash + 1);
            $uri = substr($uri, 0, $hash);
        }

        $query = null;
        $q = strpos($uri, '?');
        if ($q !== false) {
            $query = substr($uri, $q + 1);
            $uri = substr($uri, 0, $q);
        }

        $out = $uri . self::part('?', $query) . self::part('#', $fragment);

        $all = self::letters($out);
        if ($all === null || str_contains($all, self::NEEDLE)) {
            throw PlexException::tokenInUri();
        }
        return $out;
    }

    private static function part(string $sigil, ?string $pairs): string
    {
        if ($pairs === null) {
            return '';
        }
        // [pair, sep, pair, sep, pair, …]: every pair after the first is preceded by its own separator.
        $bits = preg_split('/([&;])/', $pairs, -1, PREG_SPLIT_DELIM_CAPTURE);
        $kept = [];
        $dropped = false;
        foreach ($bits as $i => $bit) {
            if ($i % 2 === 1) {
                continue;
            }
            if (self::letters(explode('=', $bit, 2)[0]) === self::NEEDLE) {
                $dropped = true;
                continue;
            }
            $kept[] = [$i === 0 ? '' : $bits[$i - 1], $bit];
        }
        if (! $dropped) {
            return $sigil . $pairs;
        }

        $out = '';
        foreach ($kept as [$sep, $pair]) {
            if ($pair !== '') {
                $out .= ($out === '' ? '' : $sep) . $pair;
            }
        }
        return $out === '' ? '' : $sigil . $out;
    }

    /** Decode until stable (≤ 3 rounds), lowercase, letters only; null when still changing after the last round. */
    private static function letters(string $s): ?string
    {
        for ($i = 0; $i < self::DECODE_ROUNDS; $i++) {
            $next = urldecode($s);
            if ($next === $s) {
                break;
            }
            $s = $next;
        }
        if (urldecode($s) !== $s) {
            return null;
        }
        return (string) preg_replace('/[^a-z]/', '', strtolower($s));
    }
}
