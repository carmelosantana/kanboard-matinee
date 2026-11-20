<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * One upstream answer. Only the response headers Matinee may forward (spec §11 S9) are kept, with lowercased
 * names; every other header (Set-Cookie, Location, anything echoing a token) is dropped here, at construction.
 *
 * @package Kanboard\Plugin\Matinee\Http
 */
final class PlexResponse
{
    public const FORWARDED_HEADERS = ['content-type', 'content-length', 'content-range', 'accept-ranges'];

    /** @var array<string, string> */
    public readonly array $headers;

    /** @param array<string, string> $headers */
    public function __construct(public readonly int $status, public readonly string $body, array $headers = [])
    {
        $kept = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            if (in_array($lower, self::FORWARDED_HEADERS, true)) {
                $kept[$lower] = (string) $value;
            }
        }
        $this->headers = $kept;
    }
}
