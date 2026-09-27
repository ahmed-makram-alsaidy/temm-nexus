<?php

namespace App\Services\Platform;

class SetupLockedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Setup is locked by another request. Retry in a moment.', 429);
    }
}
