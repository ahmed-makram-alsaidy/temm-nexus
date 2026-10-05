<?php

// ── Migration journey — 0.6.0 Phase E (§E12–§E21) ────────────────────
// The project Migration tab is ONE journey: Connect → Analyze → Plan →
// Sync → Verify → Cutover. Stage states come from ProjectPulse (the
// canonical journey model — no second interpretation layer). Analysis
// warning copy lives here too, shared with the New Project wizard (§E8).

return [

    'title' => 'Migration',

    // The six stages of the journey (stage tabs).
    'stage_connect' => 'Connect',
    'stage_analyze' => 'Analyze',
    'stage_plan' => 'Plan',
    'stage_sync' => 'Sync',
    'stage_verify' => 'Verify',
    'stage_cutover' => 'Cutover',

    'stage_of' => 'Stage: :current — :state',

    // ── Connect stage (§E14) ──────────────────────────────────────────
    'connect_empty_title' => 'No source connected yet',
    'connect_empty_body' => 'Connect the database you are migrating FROM. TEMM reads it read-only — nothing on it ever changes.',
    'connect_add_source' => 'Connect a source',
    'connect_source_heading' => 'Source connection',
    'connect_destination_heading' => 'Destination',
    'connect_destination_managed' => 'Managed by TEMM (:database)',
    'connect_destination_external' => 'External PostgreSQL (:database)',
    'connect_destination_none' => 'Not chosen yet',
    'connect_last_test' => 'Last successful connection test',
    'connect_last_test_none' => 'Not tested yet',
    'connect_continue_analyze' => 'Continue to Analyze',
    'connect_fix_connection' => 'Fix connection',
    'connect_source_status' => 'Status',
    'connect_source_connector' => 'Connector',
    'connect_last_error' => 'The last connection attempt failed. Check the credentials, then test again.',

    // Create-source form (the same domain logic as the Migration Center).
    'connect_new_source' => 'New source',
    'connect_source_name' => 'Source name',
    'connect_source_name_hint' => 'e.g. “Production database”.',
    'connect_source_type' => 'Type',
    'connect_source_host' => 'Host',
    'connect_source_port' => 'Port',
    'connect_source_database' => 'Database',
    'connect_source_username' => 'Username',
    'connect_source_password_secret' => 'Password vault secret name',
    'connect_source_password_secret_hint' => 'Never paste the password — reference a vault secret.',
    'connect_source_created' => 'Source created (read-only)',
    'connect_cancel' => 'Cancel',

    // ── Analyze stage (§E15) ──────────────────────────────────────────
    'analyze_empty_title' => 'No analysis yet',
    'analyze_empty_body' => 'Run an analysis to discover your source schema and migration risks.',
    'analyze_run' => 'Run analysis',
    'analyze_rerun' => 'Re-run analysis',
    'analyze_continue_plan' => 'Continue to Plan',
    'analyze_completed_frag' => 'Analysis completed — :count objects inventoried.',
    'analyze_failed_title' => 'The analysis could not complete.',
    'analyze_failed_body' => 'Nothing was changed — check the source connection, then run the analysis again.',
    'analyze_progress_title' => 'Analysis in progress',
    'analyze_stage_preparing' => 'Preparing',
    'analyze_stage_inspecting' => 'Inspecting schema',
    'analyze_stage_classifying' => 'Checking compatibility',
    'analyze_stage_reviewing_risks' => 'Reviewing risks',
    'analyze_stage_running' => 'In progress…',
    'analyze_stage_done' => 'Done',
    'analyze_stage_failed' => 'Failed',
    'analyze_cancel' => 'Cancel',
    'analyze_cancel_note' => 'Cancelling is safe — nothing has been changed.',
    'analyze_cancelled_title' => 'Analysis cancelled.',
    'analyze_blockers_title' => 'Blocking compatibility issues',
    'analyze_warnings_title' => 'Needs review',

    // Shared warning copy (§E8) — also used by the New Project wizard.
    'counts_tables' => 'tables',
    'counts_views' => 'views',
    'counts_auth' => 'auth',
    'counts_storage' => 'storage',
    'counts_realtime' => 'realtime',
    'counts_schemas' => 'schemas',
    'counts_extensions' => 'extensions',
    'counts_postgres' => 'server objects',
    'counts_matviews' => 'materialized views',
    'counts_enums' => 'enum types',
    'counts_functions' => 'functions',
    'counts_triggers' => 'triggers',
    'counts_policies' => 'security policies',
    'counts_edge_functions' => 'edge functions',
    'counts_client' => 'client dependencies',
    'counts_cron' => 'cron jobs',
    'warning_domain_not_present' => 'This source has no :domain domain',
    'warning_domain_not_present_detail' => 'Nothing to import there — the migration plan simply skips it.',
    'warning_postgres' => [
        'large_objects' => 'Large object metadata could not be inspected',
        'extensions' => 'The extension list could not be read',
    ],
    'warning_optional_detail' => 'A non-essential check was skipped because of source permissions. The migration plan still uses everything that was read successfully.',

    // ── Plan stage (§E16) ─────────────────────────────────────────────
    'plan_empty_title' => 'No plan yet',
    'plan_empty_body' => 'Generate the migration plan from the latest analysis — it orders the transfer by dependencies.',
    'plan_generate' => 'Generate plan',
    'plan_regenerate' => 'Generate a new plan',
    'plan_continue_sync' => 'Continue to Sync',
    'plan_heading' => 'What will move',
    'plan_stages' => 'Transfer steps',
    'plan_step' => 'Step :n',
    'plan_items_frag' => ':count items, ordered by dependencies (auth first).',
    'plan_not_moving_title' => 'What may not move automatically',
    'plan_not_moving_empty' => 'Everything the analysis found can move automatically.',
    'plan_scope_title' => 'Scope',
    'plan_created' => 'Plan created: :count items.',

    // ── Sync stage (§E17) ─────────────────────────────────────────────
    'sync_empty_title' => 'No migration runs yet',
    'sync_empty_body' => 'Start your migration when the plan is ready. A dry run writes nothing.',
    'sync_start_run' => 'Start migration',
    'sync_start_dry_run' => 'Dry run — no writes, full validation of the transfer path.',
    'sync_mode' => 'Mode',
    'sync_mode_dry_run' => 'Dry run (no writes)',
    'sync_mode_rehearsal' => 'Rehearsal (disposable target)',
    'sync_progress' => 'Progress',
    'sync_target' => 'Target',
    'sync_source_to_target' => ':source → :target',
    'sync_last_activity' => 'Last activity',
    'sync_no_runs_hint' => 'Generate a plan first — the transfer follows the plan.',
    'sync_runs_history' => 'Run history',
    'sync_guard_plain' => 'Production targets are protected: transfers to a project marked production are refused, destructive resets require a target marked disposable, and the source and target can never be the same database.',
    'sync_started_frag' => 'Run started: :status.',
    'sync_current_phase' => 'Current phase',
    'sync_technical_details' => 'Technical details',
    'sync_target_heading' => 'Target connection',
    'sync_run_heading' => 'Runs',

    // ── Verify stage (§E18) — validation + readiness in ONE place ─────
    'verify_empty_title' => 'Nothing to verify yet',
    'verify_empty_body' => 'Verification compares what moved with the source. Run a transfer first, then evaluate readiness here.',
    'verify_evaluate' => 'Evaluate now',
    'verify_passed' => 'Passed',
    'verify_needs_review' => 'Needs review',
    'verify_blocked' => 'Blocked',
    'verify_not_applicable' => 'Not applicable',
    'verify_check_evidence' => 'Evidence',
    'verify_check_blocks' => 'Blocks production cutover',
    'verify_acknowledge' => 'Acknowledge',
    'verify_summary_frag' => ':green passed · :yellow need review · :red blocked',

    // ── Cutover stage (§E19) — the existing gate model, preserved ─────
    'cutover_stage_note' => 'Cutover keeps its own gates, evidence and approvals — unchanged safety model, now one stage of the journey.',

    // ── Copilot (§E21) — contextual assistance, not a destination ─────
    'copilot_panel_title' => 'Migration Copilot',
    'copilot_panel_hint' => 'Ask about this analysis: why something is blocked or what a warning means.',
    'copilot_explain_blockers' => 'Explain what is blocked',
    'copilot_open' => 'Open the Copilot',
    'copilot_done' => 'Copilot finished. See the run history in the Copilot.',

    // ── Errors (§E23) ─────────────────────────────────────────────────
    'error_generic_title' => 'This action could not complete.',
    'error_generic_body' => 'Nothing was changed. Fix the issue or try again.',
    'error_missing_source' => 'The source is missing — connect one on the Connect stage first.',
    'error_no_completed_analysis' => 'A completed analysis is needed first — run it on the Analyze stage.',
];
