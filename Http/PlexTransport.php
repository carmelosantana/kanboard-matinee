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
    /**
     * @param array<string, string> $headers header name => value; carries the token, so never log it
     * @param array{timeout?: int} $opts only `timeout` (seconds, at least 1); anything else is refused
     */
    public function get(string $url, #[\SensitiveParameter] array $headers, array $opts = []): PlexResponse;

    /**
     * Stream a GET's body into $sink as it arrives (spec §3; C3a, C3c, C3f). The returned PlexResponse carries the
     * status and the forwardable headers; its body is always ''.
     *
     * - $opts is exactly `timeout`, `lowSpeedLimit`, `lowSpeedTime` and `maxBytes`, each an int of at least 1;
     *   anything else is an InvalidArgumentException and nothing is sent.
     * - The connected address is checked against the pin before $sink->open(), even for an empty body.
     * - Returns normally when the sink refused (open) or stopped (write).
     * - Throws PlexException::unreachable() on a curl error, a timeout, a low-speed abort or a pin mismatch, and
     *   PlexException::tooLarge() past maxBytes, after writing exactly the prefix that fits.
     * - An exception the sink throws is rethrown as-is after the transfer, never through libcurl.
     *
     * @param array<string, string> $headers
     * @param array{timeout: int, lowSpeedLimit: int, lowSpeedTime: int, maxBytes: int} $opts
     */
    public function stream(string $url, #[\SensitiveParameter] array $headers, array $opts, PlexStreamSink $sink): PlexResponse;

    /**
     * The secure plex.direct name of an `https://<IPv4>[:port]` base (see PlexDirect), read from the server's
     * certificate in a guarded, token-less TLS handshake: no request, no headers. Null when the base is not https
     * with an IPv4 host (nothing is sent) or the certificate names no Plex wildcard. The caller still guards and
     * fully verifies the returned URL before a token goes near it.
     */
    public function discoverPlexDirect(string $base): ?string;
}
