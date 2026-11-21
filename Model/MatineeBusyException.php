<?php

namespace Kanboard\Plugin\Matinee\Model;

/**
 * A stream cap is reached, or the caps lock could not be taken (C4b, C4c), or the in-flight gate is full (M1). One
 * fixed message; nothing is chained. $retryAfter is the seconds a 503 asks the browser to wait: a long-stream cap
 * frees slowly, an in-flight slot within seconds.
 */
final class MatineeBusyException extends \RuntimeException
{
    public const STREAM_RETRY_AFTER = 30;
    public const INFLIGHT_RETRY_AFTER = 5;

    public function __construct(public readonly int $retryAfter = self::STREAM_RETRY_AFTER)
    {
        parent::__construct('matinee.busy');
    }
}
