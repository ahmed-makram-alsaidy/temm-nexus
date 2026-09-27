<?php

// ── Phase 27 — Connector SDK configuration ──────────────────────────────
// Plugin/connector architecture defaults. Operators may override via env.

return [

    /*
    | Connector package discovery paths (27J.1). First-party packages ship
    | under app/Connectors; scaffolded connectors land in the same root.
    | Remote/third-party package installation is NOT implemented (27J.4).
    */
    'paths' => [
        app_path('Connectors'),
    ],

    /*
    | Trust levels allowed to auto-enable at discovery (27K.1). Only
    | FIRST_PARTY is enabled by default; TRUSTED/UNVERIFIED packages would
    | require explicit operator action (config change, not UI).
    */
    'enabled_trust_levels' => env('CONNECTOR_ENABLED_TRUST_LEVELS', 'first_party') === 'all'
        ? ['first_party', 'trusted', 'unverified']
        : ['first_party'],

    /*
    | 27K.4 — private/loopback network targets are refused for connector
    | outbound HTTP unless the operator explicitly allows local sources
    | (self-hosted Supabase/Mongo etc.). The connector manifest must ALSO
    | declare the network.local_source permission.
    */
    'allow_private_networks' => env('CONNECTOR_ALLOW_PRIVATE_NETWORKS', false),

    /*
    | Limits protecting the core from oversized connector metadata (27T).
    */
    'limits' => [
        'max_definitions' => 100,          // 27U registry scale guard
        'max_analysis_items' => 10000,     // 27U normalization scale guard
        'max_dataset_files' => 50,         // example JSON connector
        'max_dataset_file_kb' => 10240,    // 10 MB per JSON dataset file
    ],

];
