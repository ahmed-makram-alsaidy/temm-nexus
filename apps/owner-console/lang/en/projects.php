<?php

// ── Projects experience — 0.6.0 Phase D (audit §D1–§D11) ─────────────
// Everything user-visible on the Projects index and the Project Overview
// goes through this file (and its AR twin). Nothing on these surfaces may
// be hardcoded English: the problem strings, stage details, status words
// and facts all live here so Arabic reads as a first-class product.

return [

    // ── Index: header meta ──────────────────────────────────────────────
    'count_projects' => '{1} :count project|[2,*] :count projects',
    'count_needs_attention' => '{1} :count needs attention|[2,*] :count need attention',
    'count_in_progress' => '{1} :count in progress|[2,*] :count in progress',

    // ── Index: filters, search, view modes ──────────────────────────────
    'filter_all' => 'All',
    'filter_attention' => 'Needs attention',
    'filter_in_progress' => 'In progress',
    'filter_completed' => 'Completed',
    'filter_environment' => 'Environment',
    'filter_workspace' => 'Workspace / client',
    'filter_all_environments' => 'All environments',
    'filter_all_workspaces' => 'All workspaces',
    'search_label' => 'Search projects',
    'search_placeholder' => 'Search by name, client, or identifier…',
    'view_cards' => 'Cards',
    'view_compact' => 'Compact',

    // ── Index: project card / row ───────────────────────────────────────
    'card_open' => 'Open',
    'last_active_never' => 'No activity yet',
    'last_active_at' => 'Active :when',

    // ── Index: empty and no-match states ────────────────────────────────
    'empty_title' => 'No projects yet',
    'empty_body' => 'Connect your first backend to start migrating or managing it with TEMM.',
    'empty_cta' => 'Connect your first project',
    'empty_viewer_title' => 'No projects to show yet',
    'empty_viewer_body' => 'Projects you can open will appear here once your team connects one.',
    'empty_no_matches_title' => 'No projects match',
    'empty_no_matches_body' => 'No project matches the current search or filter.',
    'clear_filters' => 'Clear search and filters',

    // ── Index: compact view columns ─────────────────────────────────────
    'col_project' => 'Project',
    'col_workspace' => 'Workspace',
    'col_environment' => 'Environment',
    'col_stage' => 'Stage',
    'col_status' => 'Status',
    'col_progress' => 'Progress',
    'col_activity' => 'Last activity',

    // ── Index: pagination ───────────────────────────────────────────────
    'pagination_page' => 'Page :current of :last',
    'pagination_previous' => 'Previous',
    'pagination_next' => 'Next',

    // ── Overview: the one primary action (§D2) ──────────────────────────
    'cta_connect' => 'Connect a source',
    'cta_analyze' => 'Analyze the source',
    'cta_review_plan' => 'Review the plan',
    'cta_continue_migration' => 'Continue migration',
    'cta_check_sync' => 'Check Live Sync',
    'cta_run_validation' => 'Run validation',
    'cta_review_cutover' => 'Review cutover',
    'cta_review_attention' => 'Review what needs attention',

    // ── Overview: state summary facts (§D3) ─────────────────────────────
    'fact_progress' => 'Migration progress',
    'fact_stage' => 'Current stage',
    'fact_health' => 'Health',
    'fact_source' => 'Source connection',
    'fact_sync' => 'Live Sync',
    'fact_backup' => 'Last backup',
    'fact_readiness' => 'Readiness',
    'source_connected' => 'Connected',
    'source_problem' => 'Needs review',
    'source_not_connected' => 'Not connected',
    'readiness_not_run' => 'Not run yet',
    'readiness_blocking' => '{1} :count blocking check|[2,*] :count blocking checks',
    'readiness_review' => '{1} :count check to review|[2,*] :count checks to review',
    'readiness_all_pass' => 'All :count checks pass',

    // ── Overview: journey, attention, activity (§D4–§D6) ────────────────
    'journey_title' => 'Migration journey',
    'attention_title' => 'Needs attention',
    'attention_open' => 'Open',
    'activity_title' => 'Recent activity',
    'activity_empty' => 'No activity recorded yet. Actions taken in this project will appear here.',
    'activity_view' => 'View project activity',
    'activity_backup_state' => 'Backup :state',
    'activity_schema_change' => 'Schema change: :what',

    // ── Overview: technical details disclosure (§D7) ────────────────────
    'technical_title' => 'Technical details',
    'tech_project_id' => 'Project ID',
    'tech_slug' => 'Slug',
    'tech_workspace' => 'Workspace',
    'tech_environment' => 'Environment',
    'tech_db_name' => 'Database name',
    'tech_redis_prefix' => 'Redis prefix',
    'tech_api_domain' => 'API domain',
    'tech_domain' => 'Domain',
    'tech_deployed' => 'Last deployed',
    'tech_never_deployed' => 'Never deployed',
    'tech_database' => 'Database',
    'tech_reachable' => 'Reachable',
    'tech_unreachable' => 'Unreachable',
    'tech_unavailable' => 'Unavailable',
    'tech_no_result' => 'No result',
    'tech_connections' => '{1} :count connection|[2,*] :count connections',
    'tech_no_connection_data' => 'no connection data',
    'tech_api_requests' => 'API requests',
    'tech_pulse_note' => '{1} :count error · Pulse snapshot|[2,*] :count errors · Pulse snapshot',
    'tech_queue' => 'Queue',
    'tech_queue_pending_failed' => ':pending pending · :failed failed',
    'tech_db_size' => 'Database size',
    'tech_storage' => 'Storage',
    'tech_app_users' => 'Application users',
    'tech_functions' => 'Runtime functions',

    // ── Overview: environments ──────────────────────────────────────────
    'env_local' => 'Local',
    'env_development' => 'Development',
    'env_staging' => 'Staging',
    'env_production' => 'Production',

    // ── Index degradation (§D11) ────────────────────────────────────────
    'error_body' => 'The project list could not be loaded. Your projects are safe — try again in a moment.',

    // ── Project pulse: attention items (§D5/§D9) ────────────────────────
    'problem_generic' => 'Something needs a look.',
    'problem_check_blocks' => 'This check blocks production cutover.',
    'problem_run_failed_title' => 'The last transfer run failed',
    'problem_run_failed_detail' => 'Review the run for the cause.',
    'problem_sync_far_title' => 'Live Sync is far behind',
    'problem_sync_far_detail' => 'The last change was applied :when. Cutover is unsafe until it catches up.',
    'problem_sync_behind_title' => 'Live Sync is behind',
    'problem_sync_behind_detail' => 'Last change applied :when.',
    'problem_health_unknown_title' => 'No health result yet',
    'problem_health_unknown_detail' => 'Run a health check to get a conclusive status.',
    'problem_backup_missing_title' => 'No backup recorded',
    'problem_backup_missing_detail' => 'Cutover needs a verified backup to be reversible.',
    'problem_backup_stale_title' => 'The last backup is old',
    'problem_backup_stale_detail' => 'Taken :when.',
    'problem_backup_unverified_title' => 'The last backup was never restore-tested',
    'problem_backup_unverified_detail' => 'A backup you have not restored is not yet proven.',

    // ── Project pulse: journey stage details (§D4) ──────────────────────
    'stage_connect_none' => 'No source connected yet.',
    'stage_connect_count' => '{1} :count source connected.|[2,*] :count sources connected.',
    'stage_analyze_none' => 'Not analyzed yet.',
    'stage_analyze_done' => 'Last analyzed :when.',
    'stage_plan_none' => 'No plan yet.',
    'stage_plan_done' => 'Plan prepared :when.',
    'stage_migrate_none' => 'No transfer run yet.',
    'stage_migrate_count' => '{1} :count run, latest is :state.|[2,*] :count runs, latest is :state.',
    'stage_sync_none' => 'Live Sync has not started.',
    'stage_sync_unsupported' => 'This source connector does not support Live Sync — the transfer is one-shot; validate and cut over when ready.',
    'stage_sync_no_events' => 'No change has been applied yet.',
    'stage_sync_last' => 'Last synced :when.',
    'stage_validate_none' => 'No validation has run yet.',
    'stage_validate_failed' => '{1} :count check failed.|[2,*] :count checks failed.',
    'stage_validate_warning' => '{1} :count check needs review.|[2,*] :count checks need review.',
    'stage_validate_pass' => 'All :count checks pass.',
    'stage_cutover_none' => 'No cutover plan yet.',
    'stage_cutover_blocking' => '{1} :count item still blocks cutover.|[2,*] :count items still block cutover.',
    'stage_cutover_status' => 'Plan status: :state.',

    // ── Project pulse: Live Sync + backup facts ─────────────────────────
    'sync_up_to_date' => 'Up to date',
    'sync_starting' => 'Starting',
    'sync_behind' => 'Behind',
    'sync_stopped' => 'Stopped',
    'sync_not_running' => 'Not running',
    'sync_unsupported' => 'Not supported by this connector',
    'backup_never' => 'Never',
    'backup_none_detail' => 'No backup has been taken.',
    'backup_verified_detail' => 'Restore verified.',
    'backup_unverified_detail' => 'Not restore-tested.',

    // ── Settings hub field labels ───────────────────────────────────────
    'field_name' => 'Name',
    'field_status' => 'Status',
    'field_environment' => 'Environment',
    'field_domain' => 'Domain',
    'field_api_domain' => 'API domain',
    'field_timezone' => 'Timezone',
    'field_locale' => 'Locale',
    'field_storage_disk' => 'Storage disk',
    'field_notes' => 'Notes',

    // ── Overview notifications + settings hub (§D8) ─────────────────────
    'notify_healthy' => 'Project healthy',
    'notify_unhealthy' => 'Project unhealthy — see Overview',
    'notify_cache_cleared' => 'Cleared :count keys under :prefix',
    'settings_intro' => 'Project configuration, grouped. Technical identifiers live under Advanced.',
    'hub_general' => 'General',
    'hub_general_desc' => 'Name, environment, domains, and defaults.',
    'hub_environments' => 'Environments',
    'hub_environments_desc' => 'Environment targets and promotion between them.',
    'hub_connections' => 'Connections',
    'hub_connections_desc' => 'Database connection details, pooling, and rotation.',
    'hub_secrets' => 'Secrets',
    'hub_secrets_desc' => 'Vault-managed credentials. Values are never displayed.',
    'hub_team' => 'Team & access',
    'hub_team_desc' => 'Members, roles, and permissions for this project.',
    'hub_advanced' => 'Advanced',
    'hub_advanced_desc' => 'Low-level technical details and diagnostics.',
    'secret_states_title' => 'Secret states (values never displayed)',
    'secret_configured' => 'Configured',
    'secret_not_configured' => 'Not configured',
    'secret_no_env' => 'No .env found',
    'secret_rotation_hint' => 'Rotate via the create-project and rotation runbooks; values are never shown here.',
];
