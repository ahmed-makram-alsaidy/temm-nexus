<?php

// ── New Project wizard — 0.4.0-rc.5 (Phase 41, Part B) ───────────────
// Product language first: Live Sync, not CDC; Connection, not
// SourceAdapter. Technical detail lives under "Advanced".

return [

    'title' => 'New Project',
    'subtitle' => 'Connect a backend in six guided steps. You can stop at any point and finish later.',

    'step_project' => 'Project',
    'step_source' => 'Source',
    'step_connection' => 'Connection',
    'step_destination' => 'Destination',
    'step_analyze' => 'Analyze',
    'step_review' => 'Review',

    'step_of' => 'Step :current of :total',

    // Step 1 — Project.
    'project_name' => 'Project name',
    'project_name_helper' => 'A human name, e.g. “Acme Website”.',
    'workspace_client' => 'Workspace / Client',
    'workspace_client_helper' => 'Which client or team owns this project.',
    'environment' => 'Environment',
    'env_helper_production' => 'Source servers are live. Transfers are read-only and extra checks apply.',

    // Step 2 — Source.
    'source_pick' => 'Where does the data live today?',
    'source_pick_helper' => 'Pick the system you are migrating from. You can add more sources later.',
    'source_migration' => 'Migration',
    'source_live_sync' => 'Live Sync',
    'source_capability_migration' => 'Moves data out',
    'source_capability_live_sync' => 'Keeps the new system in sync until cutover',
    'source_capability_read_only' => 'Read-only access enforced',

    // Step 3 — Connection.
    'connection_title' => 'Connect to :name',
    'connection_helper' => 'Only the fields the provider needs. Passwords go straight into the encrypted vault.',
    'test_first' => 'Test the connection before continuing.',
    'test_running' => 'Testing connection…',
    'test_passed' => 'Connection successful',
    'test_failed' => 'Connection failed',
    'test_unknown' => 'Could not verify the connection. You can continue, but the analysis step will fail if the details are wrong.',
    'ssl_toggle' => 'Require SSL',
    'secrets_note' => 'Passwords are stored in the project vault, encrypted at rest, and never shown again.',
    'no_fields_needed' => 'This provider needs no connection fields.',

    // Step 4 — Destination.
    'destination_title' => 'Where should the data go?',
    'destination_temm' => 'TEMM-managed infrastructure',
    'destination_temm_helper' => 'Recommended. TEMM Nexus provisions and manages the target — nothing for you to configure.',
    'destination_external' => 'External PostgreSQL server',
    'destination_external_helper' => 'You provide the target database. Used for migrations into infrastructure you manage yourself.',
    'target_host' => 'Target host',
    'target_port' => 'Target port',
    'target_database' => 'Target database',
    'target_username' => 'Target username',
    'target_password' => 'Target password',
    'target_password_helper' => 'Stored as a vault secret and referenced by name — never shown again.',
    'target_disposable' => 'This target is disposable (safe to reset)',
    'destination_note' => 'Nothing is written to the target during the analysis step. The first real write happens when you start a migration.',

    // Step 5 — Analyze.
    'analyze_title' => 'Analyzing :name',
    'analyze_helper' => 'TEMM Nexus reads the source metadata only — no data leaves the source.',
    'analyze_button' => 'Analyze source',
    'analyze_running' => 'Reading the source…',
    'analyze_summary' => 'What we found',
    'analyze_counts_tables' => 'Tables',
    'analyze_counts_views' => 'Views',
    'analyze_counts_auth' => 'Users',
    'analyze_counts_storage' => 'Storage',
    'analyze_counts_functions' => 'Functions',
    'analyze_counts_triggers' => 'Triggers',
    'analyze_counts_policies' => 'Security policies',
    'analyze_counts_realtime' => 'Realtime channels',
    'analyze_issues' => 'Potential issues',
    'analyze_issues_none' => 'No blocking issues detected.',
    'analyze_done' => 'Analysis complete.',
    'analyze_failed' => 'The analysis could not complete: :reason',

    // Step 6 — Review.
    'review_title' => 'Review the plan',
    'review_helper' => 'A plain-language readiness summary. The full technical plan stays available in the Migration Center.',
    'review_database' => 'Database',
    'review_users' => 'Users',
    'review_storage' => 'Storage',
    'review_live_sync' => 'Live Sync',
    'review_client_code' => 'Client code',
    'review_client_changes' => '{1} :count change detected|[2,*] :count changes detected',
    'review_plan_items' => '{1} The plan has :count transfer step.|[2,*] The plan has :count transfer steps.',
    'start_migration' => 'Start migration',
    'start_migration_helper' => 'Runs a dry run first — no data is written until you choose a real transfer.',
    'open_migration_center' => 'Advanced technical plan',
    'migration_started' => 'Migration run started: :status',
    'project_created' => 'Project “:name” created.',
    'review_ready' => 'Ready',
    'review_needs_review' => 'Needs review',
    'review_supported' => 'Supported',
    'review_not_supported' => 'Not supported',
    'review_scan_hint' => 'Run a client scan from the project page to detect code changes.',

    // Wizard chrome.
    'continue_disabled_hint' => 'Complete this step to continue.',
    'created_note' => 'Project and connection are saved. You can safely leave this wizard and finish later.',
    'back_warning' => 'Going back is safe: values you already entered are kept.',
    'not_sure_ask_nexus_ai' => 'Not sure what to choose? Ask Nexus AI',
];
