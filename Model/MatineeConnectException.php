<?php

namespace Kanboard\Plugin\Matinee\Model;

/**
 * Connect, verify or update failed (spec §11 S1). The message is one fixed translation key whatever the stage: a
 * refused URL, an unreachable server and a rejected token all read the same. The stage is logged server-side only.
 * `matinee.token_required` asks the owner to re-enter the token for a changed URL or machine. Nothing is chained.
 */
final class MatineeConnectException extends \RuntimeException
{
    public const CONNECT_FAILED = 'matinee.connect_failed';
    public const TOKEN_REQUIRED = 'matinee.token_required';

    public function __construct(string $code = self::CONNECT_FAILED)
    {
        if ($code !== self::CONNECT_FAILED && $code !== self::TOKEN_REQUIRED) {
            throw new \LogicException('Unknown Matinee connect failure code.');
        }
        parent::__construct($code);
    }
}
