<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * Rewrites a Plex HLS playlist so every URI in it is a Matinee proxy URL (spec §7 Video, S2; P3 C2d, C2e). Pure: no
 * I/O, and the proxy URL comes from the injected builder (the model passes one over `url->to()`).
 *
 * It fails closed: anything it does not recognise refuses the whole playlist as `plex.bad_playlist`.
 * - Lines split on "\n", and a trailing "\r" is dropped, so the output is LF. Every line is printable ASCII or tab:
 *   no other control character, no DEL, nothing non-ASCII (U+0085, U+2028 and U+2029 end a line to some parsers).
 * - The first line is exactly `#EXTM3U`. A whitespace-only line is refused, an empty one is kept. `{$` (an
 *   EXT-X-DEFINE variable) is refused anywhere.
 * - Only the tags Plex emits pass (TAGS), each with its value checked. A plain `#` comment line is dropped.
 * - An attribute list is split the way hls.js splits it. An attribute named `URI` or ending in `-URI` must be quoted
 *   and is rewritten; no other attribute value may hold `/` or `\`.
 * - A URI, a bare line or a quoted URI attribute, has no scheme and does not start with `//`. A path-absolute URI is
 *   kept, a relative one joins the directory of the upstream path (its query dropped). Dot segments are never
 *   resolved, so `..` stays in and PlexPaths refuses it. The result must be an HLS child of this very session.
 * - The builder's URL must be printable ASCII with no space and no `"`.
 * - At most MAX_URIS URIs and MAX_BYTES of output.
 *
 * @package Kanboard\Plugin\Matinee\Http
 */
final class HlsPlaylist
{
    public const MAX_URIS = 50000;
    public const MAX_BYTES = 16777216;

    private const LINE = '#\A[\x20-\x7e\t]*\z#';
    private const SCHEME = '#\A[A-Za-z][A-Za-z0-9+.-]*:#';
    /** hls.js 1.7.4 AttrList's ATTR_LIST_REGEX, with JavaScript's `$` (no m flag) as `\z`. */
    private const ATTR = '#(.+?)=(".*?"|.*?)(?:,|\z)#';
    private const ATTR_NAME = '#\A[A-Z0-9-]+\z#';
    private const PROXY_URL = '#\A[\x21\x23-\x7e]+\z#';
    /** A TAGS shape: the value is an attribute list. */
    private const ATTRIBUTES = 'attributes';
    /** A TAGS shape: the tag takes no value. */
    private const BARE = 'bare';
    /** The tags Plex emits, by name, with the shape of the value after the colon. */
    private const TAGS = [
        'EXT-X-VERSION'              => '\d{1,2}',
        'EXT-X-TARGETDURATION'       => '\d{1,10}',
        'EXT-X-MEDIA-SEQUENCE'       => '\d{1,20}',
        'EXT-X-PLAYLIST-TYPE'        => 'VOD|EVENT',
        'EXTINF'                     => '\d{1,10}(?:\.\d{1,10})?,.*',
        'EXT-X-PROGRAM-DATE-TIME'    => '\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:?\d{2})',
        'EXT-X-ENDLIST'              => self::BARE,
        'EXT-X-DISCONTINUITY'        => self::BARE,
        'EXT-X-INDEPENDENT-SEGMENTS' => self::BARE,
        'EXT-X-STREAM-INF'           => self::ATTRIBUTES,
        'EXT-X-MEDIA'                => self::ATTRIBUTES,
        'EXT-X-MAP'                  => self::ATTRIBUTES,
    ];

    /** @param \Closure(string): string $proxyUrl builds the Matinee URL for one checked HLS child path */
    public static function rewrite(string $body, string $upstreamPath, string $session, \Closure $proxyUrl): string
    {
        $lines = explode("\n", $body);
        if (str_contains($body, '{$') || self::chomp($lines[0]) !== '#EXTM3U') {
            throw PlexException::badPlaylist();
        }
        $dir = dirname(explode('?', $upstreamPath, 2)[0]) . '/';
        $uris = 0;
        $proxy = static function (string $uri) use (&$uris, $dir, $session, $proxyUrl): string {
            if (++$uris > self::MAX_URIS) {
                throw PlexException::badPlaylist();
            }
            $url = ($proxyUrl)(self::resolve($uri, $dir, $session));
            if (preg_match(self::PROXY_URL, $url) !== 1) {
                throw PlexException::badPlaylist();
            }
            return $url;
        };

        $out = ['#EXTM3U'];
        $bytes = strlen('#EXTM3U');
        $last = count($lines) - 1;
        for ($i = 1; $i <= $last; $i++) {
            $line = self::chomp($lines[$i]);
            if (preg_match(self::LINE, $line) !== 1) {
                throw PlexException::badPlaylist();
            }
            if ($line === '') {
                $kept = '';
            } elseif (str_starts_with($line, '#EXT')) {
                $kept = self::tag($line, $i === $last, $proxy);
            } elseif ($line[0] === '#') {
                continue;
            } else {
                // A whitespace-only line is refused here: it is no HLS child.
                $kept = $proxy($line);
            }
            $bytes += 1 + strlen($kept);
            if ($bytes > self::MAX_BYTES) {
                throw PlexException::badPlaylist();
            }
            $out[] = $kept;
        }
        return implode("\n", $out);
    }

    private static function chomp(string $line): string
    {
        return str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;
    }

    /** @param \Closure(string): string $proxy */
    private static function tag(string $line, bool $isLast, \Closure $proxy): string
    {
        $parts = explode(':', substr($line, 1), 2);
        $name = $parts[0];
        $value = $parts[1] ?? null;
        $shape = self::TAGS[$name] ?? null;
        if ($shape === self::ATTRIBUTES) {
            return '#' . $name . ':' . self::attributes($value ?? '', $proxy);
        }
        $ok = match ($shape) {
            null       => false,
            self::BARE => $value === null,
            // hls.js reads the title of an EXTINF up to whitespace it must find after it, so the line needs its end.
            default    => $value !== null && preg_match('#\A(?:' . $shape . ')\z#', $value) === 1
                && ! ($name === 'EXTINF' && $isLast),
        };
        if (! $ok) {
            throw PlexException::badPlaylist();
        }
        return $line;
    }

    /**
     * An attribute list split as hls.js splits it, with every URI attribute rewritten.
     *
     * @param \Closure(string): string $proxy
     */
    private static function attributes(string $list, \Closure $proxy): string
    {
        if (preg_match_all(self::ATTR, $list, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
            throw PlexException::badPlaylist();
        }
        $out = '';
        $at = 0;
        foreach ($matches as $m) {
            // A name may hold anything, so each match starts where the last ended; only a tail can be left over.
            $at += strlen($m[0][0]);
            [$name, $raw] = [$m[1][0], $m[2][0]];
            $quoted = strlen($raw) >= 2 && $raw[0] === '"' && $raw[-1] === '"';
            $inner = $quoted ? substr($raw, 1, -1) : $raw;
            if (preg_match(self::ATTR_NAME, $name) !== 1 || str_contains($inner, '"')) {
                throw PlexException::badPlaylist();
            }
            if ($name === 'URI' || str_ends_with($name, '-URI')) {
                if (! $quoted) {
                    throw PlexException::badPlaylist();
                }
                $raw = '"' . $proxy($inner) . '"';
            } elseif (strpbrk($inner, '/\\') !== false) {
                throw PlexException::badPlaylist();
            }
            $out .= $name . '=' . $raw . (str_ends_with($m[0][0], ',') ? ',' : '');
        }
        // hls.js ignores a tail no match covers ("…,DEFAULT"); here it fails the playlist.
        if ($at !== strlen($list)) {
            throw PlexException::badPlaylist();
        }
        return $out;
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
