<?php

/**
 * Phase 21B: multi-node infrastructure model (descriptive, not provisioning).
 *
 * The Control Plane supports three architecture profiles while everything
 * still runs on one host. "Supported" means the code paths (endpoint
 * resolution, service mapping, heartbeat, topology) handle the profile —
 * it does NOT mean production is deployed that way.
 */
return [

    /*
    | Current descriptive profile: single | split | distributed.
    | This value only changes what the Control Plane DISPLAYS and which
    | endpoint overrides are consulted. There is no "move database" button.
    */
    'profile' => env('INFRA_PROFILE', 'single'),

    'profiles' => [
        'single' => [
            'label' => 'Single node',
            'description' => 'Caddy, apps, PostgreSQL, Redis, workers and Reverb share one host (node-local-01).',
        ],
        'split' => [
            'label' => 'Split database',
            'description' => 'App + proxy + Redis + workers + Reverb on node A; PostgreSQL on node B.',
        ],
        'distributed' => [
            'label' => 'Distributed',
            'description' => 'Apps, PostgreSQL, Redis, workers and realtime on dedicated nodes; object storage on R2/S3.',
        ],
    ],

    /*
    | Heartbeat liveness thresholds (seconds). Mirrors
    | InfrastructureNode::DEGRADED_AFTER_S / OFFLINE_AFTER_S.
    */
    'heartbeat' => [
        'degraded_after_s' => (int) env('INFRA_HEARTBEAT_DEGRADED_S', 90),
        'offline_after_s' => (int) env('INFRA_HEARTBEAT_OFFLINE_S', 300),
    ],

    /*
    | Allowlisted future remote operations. The agent channel carries signed
    | heartbeat + status ONLY; any action below would require a separate
    | signed, allowlisted command envelope — generic shell is never allowed.
    */
    'allowed_remote_actions' => [
        'health_check',
        'restart_managed_service',
        'deploy_project',
        'restart_worker',
        'reload_proxy',
    ],

    /*
    | Node agent budget target (Phase 21B gate): <50MB RAM per agent.
    | The local simulator is a stateless HTTPS POST; measure with
    | `docker stats` / `ps` on the agent host and record actuals.
    */
    'agent_memory_target_mb' => 50,
];
