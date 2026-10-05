<?php

// ── Home (platform dashboard) — 0.4.0-rc.5 (Phase 41) ────────────────

return [

    'title' => 'Home',

    'greeting_night' => 'Good night',
    'greeting_morning' => 'Good morning',
    'greeting_afternoon' => 'Good afternoon',
    'greeting_evening' => 'Good evening',

    'state_no_projects' => 'No projects yet — create one to start your first migration.',
    'state_all_clear' => 'All clear — nothing needs you right now.',

    'start_a_migration' => 'Start a migration',
    'clients_and_workspaces' => 'Clients & workspaces',

    'empty_title' => 'No projects yet',
    'empty_body' => 'A project is one backend you migrate — a website, an API, a CRM. Each project connects to a source, moves its data, keeps it in sync, and switches over when you are ready. Create one to begin.',
    'create_new_project' => 'Create new project',
    'import_existing_project' => 'Import existing project',

    'needs_attention' => 'Needs attention',
    'attention_phrase' => '{1} :count project needs attention|[2,*] :count projects need attention',
    'migrations_running_phrase' => '{1} :count migration running|[2,*] :count migrations running',
    'ready_for_cutover_phrase' => '{1} :count ready for cutover|[2,*] :count ready for cutover',
    'attention_empty' => 'Nothing needs you right now.',
    'open' => 'Open',

    'in_flight' => 'In flight',
    'transfers_running' => '{1} :count transfer running right now.|[2,*] :count transfers running right now.',
    'ready_for_cutover_line' => ':name is ready for cutover',
    'ready_for_cutover_body' => 'All gates pass and nothing is blocking.',
    'review_cutover' => 'Review cutover',

    'recent_projects' => 'Recent projects',

    'platform_health' => 'Platform health',
    'backups_taken' => 'Backups taken',
    'backups_never' => '{1} :count project never backed up|[2,*] :count projects never backed up',
    'backups_last' => 'last :when',
    'backups_no_runs' => 'no runs recorded',
    'backups_needing_review' => 'Backups needing review',
    'backups_old' => ':count old',
    'backups_not_restore_tested' => ':count not restore-tested',

    'recent_activity' => 'Recent activity',
    'activity_empty' => 'No activity recorded yet. Actions taken in your projects will appear here.',

    // ── 0.6.0 Phase C — command-center Home (§C1–§C15) ──────────────────
    'cta_connect_first' => 'Connect your first project',
    'cta_review_issues' => 'Review issues',
    'cta_review_issue' => 'Review',
    'cta_continue_migration' => 'Continue migration',
    'cta_continue' => 'Continue',
    'cta_verify_migration' => 'Verify migration',
    'cta_new_migration' => 'Start a migration',
    'cta_open_projects' => 'Open projects',
    'learn_how' => 'Learn how TEMM works',

    'all_clear_line' => 'All clear — no projects need your attention right now.',
    'attention_view_all' => '{1} View the :count project needing attention|[2,*] View all :count projects needing attention',

    'continue_context' => ':stage · last active :when',
    'continue_recently' => 'recently',

    'projects_title' => 'Projects',
    'projects_view_all' => 'All projects',

    'activity_view_all' => 'View all activity',

    'system_attention_title' => 'System health needs attention',
    'system_attention_body' => 'One or more platform components report a degraded state (:facets).',
    'system_normal' => 'All systems normal.',
    'system_degraded' => 'System health needs attention.',
    'system_details' => 'View system details',

    // Legacy summary keys (kept for stored UI preferences; no longer rendered).
    'summary_projects' => 'Projects',
    'summary_healthy' => 'healthy',
    'summary_active_migrations' => 'Active migrations',
    'summary_transfers_running' => 'transfers running now',
    'summary_live_syncs' => 'Live syncs',
    'summary_behind' => 'behind',
    'summary_all_up_to_date' => 'all up to date',
    'summary_ready_for_cutover' => 'Ready for cutover',
    'summary_blocking_items' => 'blocking item(s) elsewhere',
    'summary_nothing_blocking' => 'nothing blocking',
    'summary_projects_to_look_at' => 'projects to look at',
];

