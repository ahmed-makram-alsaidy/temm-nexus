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

    // Webhook deliveries.
    'delivered' => 'Delivered',
    'exhausted' => 'Gave up after retries',

    // Console users.
    'disabled' => 'Disabled',

    // Backups / restore drills.
    'verified' => 'Verified',
    'drill_running' => 'Restore drill running',
    'drill_passed' => 'Restore drill passed',
    'drill_failed' => 'Restore drill failed',

    // Log severities.
    'info' => 'Info',
    'warning' => 'Warning',
    'debug' => 'Debug',
    'critical' => 'Critical',

    // Setup system checks (uppercase stored values normalize on lookup).
    'passed' => 'Passed',

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
