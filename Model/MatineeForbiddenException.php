<?php

namespace Kanboard\Plugin\Matinee\Model;

/** The actor may not act on this server, or the server does not exist: the two read the same (no IDOR oracle). */
final class MatineeForbiddenException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('matinee.forbidden');
    }
}
