<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * A Plex request that failed. The message is always the fixed reason code (a translation key) and nothing else:
 * never a URL, a query string, a token or an upstream body (spec §3, §11 S9). Nothing is chained as `previous`.
 * `status` is the upstream HTTP status for `plex.http_error` / `plex.token_rejected`, else 0.
 *
 * @package Kanboard\Plugin\Matinee\Http
 */
class PlexException extends \RuntimeException
{
    public const UNREACHABLE    = 'plex.unreachable';
    public const HTTP_ERROR     = 'plex.http_error';
    public const TOKEN_REJECTED = 'plex.token_rejected';
    public const BAD_JSON       = 'plex.bad_json';
    public const SERVER_CHANGED = 'plex.server_changed';
    public const TOO_LARGE      = 'plex.too_large';
    /** PlexScrub found token-like text it could not strip, so the URI is refused (not reused). */
    public const TOKEN_IN_URI   = 'plex.token_in_uri';
    /** A response whose content-type is off its allow-list: art, a direct part or an mp3 transcode (§11 S11). */
    public const BAD_TYPE       = 'plex.bad_type';
    /** A direct part whose upstream Content-Range does not fit the requested window, or a 416 with no total (C1b). */
    public const BAD_RANGE      = 'plex.bad_range';
    /** An HLS playlist that is not UTF-8 #EXTM3U, or names a URI off its session's S2 shape (C2e): refused whole. */
    public const BAD_PLAYLIST   = 'plex.bad_playlist';

    private function __construct(public readonly string $reason, public readonly int $status = 0)
    {
        parent::__construct($reason);
    }

    public static function unreachable(): self
    {
        return new self(self::UNREACHABLE);
    }

    public static function httpError(int $status): self
    {
        return new self(self::HTTP_ERROR, $status);
    }

    public static function tokenRejected(): self
    {
        return new self(self::TOKEN_REJECTED, 401);
    }

    public static function badJson(): self
    {
        return new self(self::BAD_JSON);
    }

    public static function serverChanged(): self
    {
        return new self(self::SERVER_CHANGED);
    }

    public static function tooLarge(): self
    {
        return new self(self::TOO_LARGE);
    }

    public static function tokenInUri(): self
    {
        return new self(self::TOKEN_IN_URI);
    }

    public static function badType(): self
    {
        return new self(self::BAD_TYPE);
    }

    public static function badRange(): self
    {
        return new self(self::BAD_RANGE);
    }

    public static function badPlaylist(): self
    {
        return new self(self::BAD_PLAYLIST);
    }
}
