<?php

namespace App\Services\ControlPlane\Connectors\Contracts;

use App\Models\MigrationSource;
use App\Services\ControlPlane\Migration\Cdc\ChangeCaptureConnector;

/**
 * Phase 35.6 — optional connector contract: provides a REAL log-based
 * change capture for a source. The generic CDC worker resolves captures
 * ONLY through this contract — it never learns which provider is behind it
 * and never branches on source type.
 *
 * $options carries run-scoped naming (slot / publication / server-id
 * identity) so concurrent runs cannot collide. Implementations must refuse
 * honestly (throw) when the source cannot support real log capture.
 */
interface CdcCaptureProvider
{
    public function cdcCapture(MigrationSource $source, array $options = []): ChangeCaptureConnector;
}
