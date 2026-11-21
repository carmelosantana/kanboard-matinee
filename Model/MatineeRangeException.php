<?php

namespace Kanboard\Plugin\Matinee\Model;

/** A direct part's range can't be satisfied (C1b): Plex answered 416 with this total. One fixed message; nothing chained. */
final class MatineeRangeException extends \RuntimeException
{
    public function __construct(public readonly int $total)
    {
        parent::__construct('matinee.range');
    }
}
