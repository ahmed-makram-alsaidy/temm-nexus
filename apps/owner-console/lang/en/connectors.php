<?php

// ── Connector catalog — 0.4.0-rc.5 (Phase 41) ────────────────────────
// Product-language capability labels (C.10: protocol identifiers like WAL
// are not translated; the surrounding explanation is).

return [

    'title' => 'Connectors',
    'subtitle' => 'The sources TEMM Nexus can connect to, migrate from, and keep in sync.',

    'capability_live_sync' => 'Live Sync',
    'capability_sync_checkpoints' => 'Sync checkpoints',
    'capability_schema_inspection' => 'Schema inspection',
    'capability_data_extraction' => 'Data extraction',
    'capability_auth_users' => 'Auth users',
    'capability_storage_files' => 'Storage files',
    'capability_db_functions' => 'Database functions',
    'capability_security_policies' => 'Security policies',
    'capability_realtime_channels' => 'Realtime channels',
    'capability_scheduled_jobs' => 'Scheduled jobs',
    'capability_client_code_scan' => 'Client code scan',
    'capability_read_only' => 'Read-only enforced',
    'capability_source_fingerprint' => 'Source fingerprint',
    'capability_incremental_export' => 'Incremental export',
    'capability_consistent_snapshot' => 'Consistent snapshot',
    'capability_resume' => 'Resumable transfers',
    'capability_change_capture' => 'Live Sync',
    'capability_account_discovery' => 'Account discovery',
    'capability_project_discovery' => 'Project discovery',

    'trust_first_party' => 'First-party',
    'trust_partner' => 'Partner',
    'trust_community' => 'Community',

    'category_database' => 'Database',
    'category_baas' => 'Backend-as-a-Service',
    'category_file' => 'Files',
    'category_other' => 'Other',

    'filter_all' => 'All',
    'empty' => 'No connectors match this filter.',
    'view_docs' => 'Docs',
    'version_suffix' => 'v:version',

    'subtitle' => 'Everything you can migrate from, and what each one supports.',
    'search_placeholder' => 'Search connectors by name, capability, or vendor…',
    'ready_only' => 'Ready to use only',
    'clear_filters' => 'Clear filters',
    'showing' => '{1} Showing :shown of :total connector.|[2,*] Showing :shown of :total connectors.',
    'empty_none' => 'No connectors installed',
    'empty_none_body' => 'A connector is what lets the platform read from a source system — a database, a Firebase project, a MongoDB cluster. Without one there is nothing to migrate from.',
    'empty_filter' => 'No connectors match those filters',
    'empty_filter_body' => ':total connector(s) are installed, but none satisfy every filter you selected. Try removing one.',
    'trust' => 'Trust',
    'reads_only' => 'Reads only. The platform never writes to the source.',
    'key' => 'Key',
    'import_flow' => 'Import flow',
    'category' => 'Category',
    'capabilities' => 'Capabilities',
    'not_available' => 'Not available on this platform',
    'connect_in_project' => 'Connect in a project',
    'create_project_to_connect' => 'Create a project to connect',
];
