<?php

// ── New Project wizard — 0.6.0 Phase E ───────────────────────────────
// ONE journey in five guided steps. Product language first: "Live Sync",
// not CDC; "Connection", not SourceAdapter. Technical detail lives under
// "Technical details" / "Advanced". §E25: nothing user-visible outside
// lang/ — every key here has an Arabic counterpart.

return [

    'title' => 'New Project',
    'subtitle' => 'Connect a backend in five guided steps. Your progress is saved as you go — you can stop and finish later.',

    'step_project' => 'Project',
    'step_source' => 'Source connection',
    'step_destination' => 'Destination',
    'step_analyze' => 'Analyze',
    'step_review' => 'Review',

    'step_of' => 'Step :current of :total',

    // §E3 — a resumed draft announces itself and can be discarded.
    'resumed_title' => 'Welcome back',
    'resumed_body' => 'We kept everything you entered so far. Pick up where you left off.',
    'discard_draft' => 'Start over',
    'discard_confirm' => 'Discard the saved draft and start a fresh project?',

    // Step 1 — Project.
    'project_name' => 'Project name',
    'project_name_helper' => 'A human name, e.g. “Acme Website”.',
    'workspace_client' => 'Workspace / Client',
    'workspace_client_helper' => 'Which client or team owns this project.',
    'environment' => 'Environment',
    'env_helper_production' => 'Source servers are live. Transfers are read-only and extra checks apply.',

    // §E2 — inline workspace creation: the dead end is gone.
    'create_workspace_inline' => 'Create a new workspace',
    'workspace_needs_admin' => 'No workspace is available to you yet. An administrator needs to create one for your account.',
    'workspace_created' => 'Workspace created',
    'workspace_created_and_selected' => '“:name” is ready and selected. Nothing you entered was lost.',

    // Step 2 — Source connection.
    'source_pick' => 'Where does the data live today?',
    'source_pick_helper' => 'Pick the system you are migrating from. You can add more sources later.',
    'source_migration' => 'Migration',
    'source_live_sync' => 'Live Sync',
    // §E4 — one product sentence per connector card (no phase IDs; the full
    // technical description stays under Technical details on the same step).
    'source_desc_postgres' => 'Import any standard PostgreSQL database, with optional Live Sync.',
    'source_desc_mysql' => 'Import a MySQL or MariaDB database, with optional Live Sync.',
    'source_desc_supabase' => 'Import a Supabase project: database, auth, storage and more.',
    'source_desc_mongodb' => 'Import a MongoDB database and map it to PostgreSQL.',
    'source_desc_firebase' => 'Import a Firebase project: Firestore, auth, storage and functions.',
    'source_desc_example-json' => 'Developer example that walks a small JSON dataset through the pipeline.',

    'connection_title' => 'Connect to :name',
    'connection_helper' => 'Only the fields the provider needs. Passwords go straight into the encrypted vault.',
    'test_first' => 'Test the connection before continuing.',
    'test_running' => 'Testing connection…',
    'test_passed' => 'Connection successful',
    'test_passed_read_only' => 'Read-only access confirmed — nothing on this database can be changed.',
    'test_failed' => 'We couldn’t reach this database.',
    // §E5 — each failure kind speaks plainly; raw detail stays disclosed.
    'test_network_body' => 'Check the host, port and any firewall or VPN between TEMM and this database, then test again.',
    'test_auth_body' => 'The database refused these credentials. Check the username and password, then test again.',
    'test_invalid_body' => 'Some connection details are missing or invalid. Review the fields above, then test again.',
    'test_private_network_body' => 'This source appears to be on a private network. An administrator must allow private-network connections in System settings.',
    'test_private_network_settings' => 'Open system settings',
    'ssl_toggle' => 'Require SSL',
    'secrets_note' => 'Passwords are stored in the project vault, encrypted at rest, and never shown again.',
    'resume_secret_note' => 'For security, a saved draft never keeps passwords — re-enter the password if this step needs it again.',
    'no_fields_needed' => 'This provider needs no connection fields.',

    // Step 3 — Destination.
    'destination_title' => 'Where should the data go?',
    'destination_recommended' => 'Recommended',
    'destination_temm' => 'TEMM-managed infrastructure',
    'destination_temm_helper' => 'TEMM Nexus provisions and manages the target — nothing for you to configure.',
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

    // Step 4 — Analyze: a real operation with real recorded stages (§E7).
    'analyze_title' => 'Analyzing :name',
    'analyze_helper' => 'TEMM Nexus reads the source metadata only — no data leaves the source.',
    'analyze_button' => 'Analyze source',
    'analyze_rerun' => 'Run analysis again',
    'analyze_running' => 'Reading the source…',
    'analyze_cancel' => 'Cancel',
    'analyze_cancel_note' => 'Cancelling is safe — nothing has been changed.',
    'analyze_cancelled_title' => 'Analysis cancelled.',
    'analyze_cancelled_body' => 'Nothing was changed. Run the analysis again whenever you are ready.',
    // Real stage labels — they come from the engine's telemetry, not the UI.
    'stage_preparing' => 'Preparing',
    'stage_inspecting' => 'Inspecting schema',
    'stage_classifying' => 'Checking compatibility',
    'stage_reviewing_risks' => 'Reviewing risks',
    'stage_running' => 'In progress…',

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
    'analyze_failed_body' => 'The analysis could not complete. Nothing was changed — you can re-run it after checking the source connection.',

    // §E8 — warnings are warnings. The analysis still succeeded. The warning
    // copy itself is shared with the Migration journey in lang/*/migration.php
    // (one mapping — AnalysisOutcomeClassifier::present()).
    'analysis_completed_warnings' => '{1} Analysis completed with one warning.|[2,*] Analysis completed with :count warnings.',

    // Step 5 — Review: deterministic readiness (§E10).
    'review_title' => 'Review the plan',
    'review_helper' => 'A plain-language readiness summary. The full technical plan stays available in the Migration Center.',
    'review_source' => 'Source',
    'review_destination' => 'Destination',
    'review_found' => 'What TEMM found',
    'review_not_analyzed' => 'Analysis has not completed yet.',
    'review_tables_found' => '{1} :count table discovered.|[2,*] :count tables discovered.',
    'review_views_found' => '{1} :count view|[2,*] :count views',
    'review_ready_items' => 'Plan',
    'review_all_ready' => 'Everything needed for the transfer is prepared.',
    'review_plan_items' => '{1} The plan has :count transfer step.|[2,*] The plan has :count transfer steps.',
    'review_live_sync' => 'Live Sync',
    'review_supported' => 'Supported',
    'review_not_supported' => 'Not supported by this connector',
    'review_blocked_items' => '{1} :count :kind object cannot be migrated automatically.|[2,*] :count :kind objects cannot be migrated automatically.',
    // §E10 — the verdict is derived, never a badge over nothing.
    'review_ready' => 'Ready to start your migration',
    'review_not_ready' => 'Not ready yet',
    'review_reason_no_analysis' => 'The analysis has not completed yet — run it to discover your schema.',
    'review_reason_no_tables' => 'No tables were discovered, so there is nothing to transfer yet.',
    'review_reason_no_plan' => 'The transfer plan is still empty — re-run the analysis to build it.',
    'review_recovery_hint' => 'Fixing this is the next step — the analysis result above says exactly what is missing.',
    'start_migration' => 'Start migration',
    'start_migration_helper' => 'Runs a dry run first — no data is written until you choose a real transfer.',

    // §E11 — starting can refuse, but never with a bare "Error".
    'start_blocked_no_project' => 'Migration can’t start yet.',
    'start_blocked_no_project_detail' => 'The project record is missing — start a fresh wizard and complete the source step.',
    'start_blocked_no_analysis' => 'Migration can’t start yet — the analysis has not completed. Run the analysis first.',
    'start_blocked_no_tables' => 'Migration can’t start yet — no tables were discovered during the analysis. Run the analysis again after checking the connection.',
    'start_failed_body' => 'The migration could not start. Nothing was written — fix the issue or try again.',

    'migration_started' => 'Migration run started: :status',
    'project_created' => 'Project “:name” created.',

    // Wizard chrome.
    'continue_disabled_hint' => 'Complete this step to continue.',
    'created_note' => 'The project and connection are saved. You can safely leave the wizard and finish later.',
    'back_warning' => 'Going back is safe: the values you entered are kept.',
    'not_sure_ask_nexus_ai' => 'Not sure? Ask Nexus AI',
];
