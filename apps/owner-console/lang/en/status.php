<?php

/* ── 0.6.0 Phase A — Status dictionary (audit §A5) ────────────────────
   Human-facing labels for raw stored status values. Raw values are
   NEVER rendered on normal product surfaces; they stay available in
   technical details and audit metadata. Stored enums are unchanged. */

return [
    // Project health.
    'healthy' => 'Healthy',
    'unhealthy' => 'Unhealthy',
    'degraded' => 'Degraded',
    'unknown' => 'Unknown',

    // Project lifecycle.
    'planned' => 'Planned',
    'active' => 'Active',
    'paused' => 'Paused',
    'archived' => 'Archived',

    // Deployment.
    'not_deployed' => 'Not deployed',
    'deploying' => 'Deploying',
    'deployed' => 'Deployed',
    'deploy_failed' => 'Deploy failed',
    'offline' => 'Offline',

    // Migration analysis / runs.
    'pending' => 'Pending',
    'running' => 'Running',
    'completed' => 'Completed',
    'failed' => 'Failed',
    'cancelled' => 'Cancelled',

    // Capability / connection checks.
    'ready' => 'Ready',
    'error' => 'Error',
    'ok' => 'OK',
    'blocked' => 'Blocked',
    'unavailable' => 'Unavailable',

    // Cutover verdicts.
    'cutover_ready' => 'Ready',
    'cutover_warning' => 'Review needed',
    'cutover_blocked' => 'Blocked',

    // Readiness colors.
    'green' => 'Ready',
    'yellow' => 'Review needed',
    'red' => 'Blocked',
    'not_applicable' => 'Not applicable',
];
