<?php

/**
 * Phase 35.6 — CDC capture + cutover lag thresholds.
 *
 * Conservative defaults; every deployment's safe lag threshold differs.
 * These are STARTING POINTS an operator tunes per workload — never a
 * claim that one threshold fits every source.
 */
return [
    'capture' => [
        // Events consumed per captureChanges cycle (bounded memory, 35.6 §13).
        'max_events_per_cycle' => env('CDC_MAX_EVENTS_PER_CYCLE', 5000),
        // Rows per apply batch inside a cycle.
        'apply_batch_rows' => env('CDC_APPLY_BATCH_ROWS', 200),
        // Target failure handling: retry with linear backoff, then STOP.
        // Checkpoints never advance past unapplied events.
        'apply_retries' => env('CDC_APPLY_RETRIES', 3),
        'apply_backoff_ms' => env('CDC_APPLY_BACKOFF_MS', 500),
        // Poll pause when the log is drained.
        'idle_poll_ms' => env('CDC_IDLE_POLL_MS', 250),
        // Distinct replication client identity per platform instance
        // (PostgreSQL application_name / MySQL server-id range guard).
        'replication_app_name' => env('CDC_REPLICATION_APP_NAME', 'temm-nexus-cdc'),
        'mysql_server_id_base' => (int) env('CDC_MYSQL_SERVER_ID_BASE', 420240000),
        // Source mutation gate: creating replication slots/publications (PG),
        // nothing in MySQL, nothing in MongoDB. Default FALSE — the platform
        // never alters a source configuration without the operator allowing
        // it explicitly (35.6 §3: disposable test sources may enable this).
        'allow_source_setup' => env('CDC_ALLOW_SOURCE_SETUP', false),
    ],

    'lag' => [
        // Heartbeat freshness: a stream silent longer than this is STALE.
        'stale_after_seconds' => env('CDC_LAG_STALE_SECONDS', 120),
        // WARN: catch-up probably needed before a window.
        'warn_seconds' => env('CDC_LAG_WARN_SECONDS', 60),
        // BLOCK: too far behind to cut over on this evidence.
        'block_seconds' => env('CDC_LAG_BLOCK_SECONDS', 300),
        // Event backlog thresholds (committed but unapplied).
        'warn_events' => env('CDC_LAG_WARN_EVENTS', 1000),
        'block_events' => env('CDC_LAG_BLOCK_EVENTS', 10000),
        // Checkpoint kinds that count as REAL log-based CDC for the cutover
        // gate. Watermark checkpoints are incremental export, NOT log CDC —
        // they never satisfy the cdc_lag gate (honest UNVERIFIED instead).
        'log_based_kinds' => ['lsn', 'binlog_position', 'binlog_gtid', 'resume_token'],
    ],
];
