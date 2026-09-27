<?php

namespace App\Services\ControlPlane;

/** Control-flow exception: carries read results out of a rolled-back transaction. */
class ReadOnlyRollback extends \RuntimeException
{
    /** @param list<array<string,mixed>> $rows */
    public function __construct(public readonly array $rows)
    {
        parent::__construct('read-only rollback');
    }
}
