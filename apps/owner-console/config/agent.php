<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agent Runtime Platform (Phase 43)
    |--------------------------------------------------------------------------
    |
    | Configuration for the Developer Agent runtime layer. Sensitive values
    | (managed service basic-auth password) come from the environment and are
    | NEVER logged. Per-runtime configuration lives in the `agent_runtimes`
    | table; everything here is platform-level.
    |
    */

    /*
    | Managed OpenCode service (TEMM's own Docker stack).
    |
    | The endpoint is the internal Docker DNS name — never published through
    | Caddy, never bound to 0.0.0.0. The password is the shared stack secret
    | (OPENCODE_SERVER_PASSWORD) so the app can authenticate to the service.
    */
    'managed_opencode' => [
        'endpoint' => env('OPENCODE_MANAGED_ENDPOINT', 'http://opencode:4096'),
        'username' => env('OPENCODE_SERVER_USERNAME', 'opencode'),
        'password' => env('OPENCODE_SERVER_PASSWORD'),
    ],

    /*
    | SSRF posture for EXTERNAL runtime endpoints. Mirrors the AI provider
    | guard: HTTPS-only, private/metadata ranges refused. The loopback flag
    | exists for local development against the shipped mock server and must
    | never be enabled in production.
    */
    'allow_loopback_endpoints' => env('AGENT_ALLOW_LOOPBACK_ENDPOINTS', false),

    /*
    | Version compatibility: managed/external servers must report MAJOR 1,
    | MINOR >= 18 (the verified 1.18.x interface, docs/agent-runtime/
    | OPENCODE_INTEGRATION.md). Anything else fails the connection test with a
    | classified error.
    */
    'opencode' => [
        'min_major' => 1,
        'min_minor' => 18,
        'pin' => env('OPENCODE_VERSION', '1.18.34'),
    ],

    /*
    | Bounded execution. Nothing in this feature may grow without limit.
    | Output CONTENT is never persisted — only byte counts and truncation.
    */
    'limits' => [
        'max_events_per_task' => (int) env('AGENT_MAX_EVENTS', 500),
        'max_diff_bytes' => (int) env('AGENT_MAX_DIFF_BYTES', 512000),
        'max_prompt_bytes' => (int) env('AGENT_MAX_PROMPT_BYTES', 32768),
        'max_output_bytes_per_command' => (int) env('AGENT_MAX_OUTPUT_BYTES', 262144),
        'event_idle_timeout' => (int) env('AGENT_EVENT_IDLE_TIMEOUT', 120),
        'max_stream_reconnects' => (int) env('AGENT_MAX_STREAM_RECONNECTS', 30),
        'http_connect_timeout' => (int) env('AGENT_HTTP_CONNECT_TIMEOUT', 10),
        'max_concurrent_tasks' => (int) env('AGENT_MAX_CONCURRENT_TASKS', 2),
    ],

    /*
    | Workspace isolation. Worktrees live under storage/app/private so they
    | are volume-backed (they must survive container recreation like every
    | operational artifact) and are never web-reachable.
    */
    'workspaces' => [
        'retention_days' => (int) env('AGENT_WORKSPACE_RETENTION_DAYS', 7),
    ],

    /*
    | Task codes: AGT-XXXX sequential, human-quotable, unique.
    */
    'task_code_prefix' => 'AGT-',

    /*
    | Queue for task execution. Horizon supervises this queue with a long
    | timeout (see config/horizon.php); tests run it synchronously.
    */
    'queue' => env('AGENT_QUEUE', 'agents'),

    /*
    | Post-apply verification policy. Operator-defined per project slug with
    | a default; NEVER agent-proposed, never deploy. Commands run in the
    | authoritative source directory after an approved apply.
    */
    'verification' => [
        'timeout_seconds' => (int) env('AGENT_VERIFY_TIMEOUT', 900),
        // Comma-separated fallback commands for projects without a specific
        // policy, e.g. AGENT_VERIFY_DEFAULT_COMMANDS="php -l index.php"
        'default' => array_values(array_filter(explode(',', (string) env('AGENT_VERIFY_DEFAULT_COMMANDS', '')))),
        'projects' => [
            // 'slug' => ['php artisan test', 'php -l app/Models/User.php'],
        ],
    ],
];
