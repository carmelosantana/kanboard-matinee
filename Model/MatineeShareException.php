<?php

namespace Kanboard\Plugin\Matinee\Model;

/**
 * A share request the actor may make, but whose content is wrong: a bad audience shape, a malformed or no-longer-live
 * section key, or a share that already exists. One fixed message whatever the cause; the input is never echoed.
 */
final class MatineeShareException extends \RuntimeException
{
    public const INVALID = 'matinee.invalid_share';

    public function __construct()
    {
        parent::__construct(self::INVALID);
    }
}
