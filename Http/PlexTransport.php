<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * Moves bytes to and from a Plex server (spec §3). The real one is CurlTransport (guarded, pinned, no redirects);
 * tests use FakeTransport.
 *
 * Implementations throw PlexException (for example `plex.too_large`) or UnsafeUrlException for the failures they
 * understand. Anything else they throw is reported by PlexClient as `plex.unreachable`, with nothing chained, so
 * a transport message that names the URL can never escape.
 *
 * @package Kanboard\Plugin\Matinee\Http
 */
interface PlexTransport
{
    /** @param array<string, string> $headers header name => value; carries the token, so never log it */
    public function get(string $url, #[\SensitiveParameter] array $headers): PlexResponse;
}
