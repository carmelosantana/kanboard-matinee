<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * Rewrites a Plex HLS playlist so every URI in it is a Matinee proxy URL (spec §7 Video, S2; P3 C2d, C2e). Pure: no
 * I/O, and the proxy URL comes from the injected builder (the model passes one over `url->to()`).
 *
 * It fails closed: anything it does not recognise refuses the whole playlist as `plex.bad_playlist`.
 * - The body is valid UTF-8 and starts with `#EXTM3U`. Lines split on "\n", and a trailing "\r" is dropped, so the
 *   output is LF. No line may hold another control character (a lone "\r" is a line break to some players).
 * - A URI, a bare line or a tag's `URI="…"`, has no scheme and does not start with `//`. A path-absolute URI is kept,
 *   a relative one joins the directory of the upstream path (its query dropped). Dot segments are never resolved, so
 *   `..` stays in and PlexPaths refuses it. The result must be an HLS child of this very session.
 * - On a tag line, once every `URI="…"` is rewritten, what is left may hold neither `/` nor `\`: that catches a
 *   lowercase `uri=`, single quotes and a URL in any other attribute.
 *
 * @package Kanboard\Plugin\Matinee\Http
 */
final class HlsPlaylist
{
    private const SCHEME = '#\A[A-Za-z][A-Za-z0-9+.-]*:#';
    private const TAG_URI = '#URI="([^"]*)"#';
    /** C0 controls but tab, and DEL. */
    private const CONTROL = '#[\x00-\x08\x0a-\x1f\x7f]#';

    /** @param \Closure(string): string $proxyUrl builds the Matinee URL for one checked HLS child path */
    public static function rewrite(string $body, string $upstreamPath, string $session, \Closure $proxyUrl): string
    {
        if (! str_starts_with($body, '#EXTM3U') || ! mb_check_encoding($body, 'UTF-8')) {
            throw PlexException::badPlaylist();
        }
        $dir = dirname(explode('?', $upstreamPath, 2)[0]) . '/';

        $out = [];
        foreach (explode("\n", $body) as $line) {
            if (str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }
            if (preg_match(self::CONTROL, $line) === 1) {
                throw PlexException::badPlaylist();
            }
            if (trim($line) === '') {
                $out[] = $line;
            } elseif ($line[0] === '#') {
                $out[] = self::tag($line, $dir, $session, $proxyUrl);
            } else {
                $out[] = ($proxyUrl)(self::resolve($line, $dir, $session));
            }
        }
        return implode("\n", $out);
    }

    private static function tag(string $line, string $dir, string $session, \Closure $proxyUrl): string
    {
        $leftover = preg_replace(self::TAG_URI, 'URI=""', $line);
        if ($leftover === null || strpbrk($leftover, '/\\') !== false) {
            throw PlexException::badPlaylist();
        }
        $rewritten = preg_replace_callback(
            self::TAG_URI,
            static fn (array $m): string => 'URI="' . ($proxyUrl)(self::resolve($m[1], $dir, $session)) . '"',
            $line,
        );
        if ($rewritten === null) {
            throw PlexException::badPlaylist();
        }
        return $rewritten;
    }

    /** The checked HLS child path a playlist URI names, or `plex.bad_playlist`. */
    private static function resolve(string $uri, string $dir, string $session): string
    {
        if (preg_match(self::SCHEME, $uri) === 1 || str_starts_with($uri, '//')) {
            throw PlexException::badPlaylist();
        }
        $path = str_starts_with($uri, '/') ? $uri : $dir . $uri;
        if (! PlexPaths::isHlsChild($path) || PlexPaths::hlsSession($path) !== $session) {
            throw PlexException::badPlaylist();
        }
        return $path;
    }
}
