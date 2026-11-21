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

    /**
     * The secure plex.direct name of an `https://<IPv4>[:port]` base (see PlexDirect), read from the server's
     * certificate in a guarded, token-less TLS handshake: no request, no headers. Null when the base is not https
     * with an IPv4 host (nothing is sent) or the certificate names no Plex wildcard. The caller still guards and
     * fully verifies the returned URL before a token goes near it.
     */
    public function discoverPlexDirect(string $base): ?string;
}
