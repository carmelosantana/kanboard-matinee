<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * Where PlexTransport::stream() writes a body as it arrives (spec §3; C3a, C3e, C3f). The real one is
 * ResponseSink, which answers the browser; tests record.
 *
 * @package Kanboard\Plugin\Matinee\Http
 */
interface PlexStreamSink
{
    /**
     * Called at most once: before the first body byte, or at the end of an empty body, and only after the
     * connected address matched the pin. $headers are PlexResponse's forwardable ones, with lowercased names.
     * Returning false refuses the response: the transfer stops and nothing is written.
     *
     * @param array<string, string> $headers
     */
    public function open(int $status, array $headers): bool;

    /** One piece of the body, in order. Returning false stops the transfer (for example, the client has gone). */
    public function write(string $chunk): bool;
}
