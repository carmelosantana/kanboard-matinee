<?php

namespace Kanboard\Plugin\Matinee\Model;

/** A stream cap is reached, or the caps lock could not be taken (C4b, C4c). One fixed message; nothing is chained. */
final class MatineeBusyException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('matinee.busy');
    }
}
