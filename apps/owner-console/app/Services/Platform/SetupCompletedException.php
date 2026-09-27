<?php

namespace App\Services\Platform;

class SetupCompletedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Platform is already initialized. /setup is locked.', 409);
    }
}
