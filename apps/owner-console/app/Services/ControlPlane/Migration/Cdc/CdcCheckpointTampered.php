<?php

namespace App\Services\ControlPlane\Migration\Cdc;

/**
 * Phase 32C — a stored checkpoint failed signature verification. The run
 * must STOP: continuing from a tampered position could silently skip or
 * replay changes.
 */
class CdcCheckpointTampered extends \RuntimeException
{
}
