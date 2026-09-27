<?php

namespace App\Console\Commands;

use App\Services\ControlPlane\NodeHeartbeatService;
use Illuminate\Console\Command;

/** Phase 21B: persist DEGRADED/OFFLINE for nodes whose heartbeat went stale. */
class InfraMarkStaleCommand extends Command
{
    protected $signature = 'infra:mark-stale';

    protected $description = 'Mark nodes with stale heartbeats as degraded/offline (local).';

    public function handle(): int
    {
        $t = NodeHeartbeatService::markStale();
        $this->info("Stale sweep: {$t['degraded']} degraded, {$t['offline']} offline.");

        return self::SUCCESS;
    }
}
