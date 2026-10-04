<?php

/* ── 0.6.0 Phase A — Humanized activity (audit §A6) ───────────────────
   Maps raw audit action verbs to short human sentences for product
   surfaces (Home activity, project overview, Activity page labels).
   The RAW action verb is always preserved in audit detail views and
   technical metadata — this file only changes how activity READS.
   Unknown verbs fall back to Title Case via ActivityHumanizer. */

return [
    // Platform & onboarding
    'platform_setup_completed' => 'Platform setup completed',

    // Projects, environments, health
    'project_health_checked' => 'Ran a health check',
    'environment_created' => 'Created an environment',
    'environment_updated' => 'Updated an environment',
    'environment_switched' => 'Switched environment',
    'promotion_attempted' => 'Attempted an environment promotion',

    // Migration
    'wizard_source_connected' => 'Connected a source',
    'migration_source_created' => 'Created a migration source',
    'source_credential_changed' => 'Updated source credentials',
    'migration_analysis_run' => 'Analyzed the source',
    'migration_plan_created' => 'Created a migration plan',
    'migration_run_started' => 'Started a migration run',
    'migration_run_cancelled' => 'Cancelled a migration run',
    'migration_run_failed' => 'A migration run failed',
    'migration_validated' => 'Validated migrated data',
    'wizard_migration_started' => 'Started a migration',
    'cutover_event' => 'Recorded a cutover event',
    'clean_rehearsal_run' => 'Ran a clean rehearsal',
    'schema_snapshot_created' => 'Created a schema snapshot',
    'schema_diff_run' => 'Compared schema snapshots',
    'readiness_acknowledged' => 'Acknowledged a readiness item',
    'readiness_snapshot_recorded' => 'Recorded a readiness snapshot',

    // Database & records
    'records_exported' => 'Exported table records',
    'write_mode_enabled' => 'Enabled SQL write mode',

    // Secrets & keys
    'secret_created' => 'Created a secret',
    'secret_rotated' => 'Rotated a secret',
    'secret_deleted' => 'Deleted a secret',
    'secret_revealed' => 'Revealed a secret value',
    'apikey_created' => 'Created an API key',
    'apikey_rotated' => 'Rotated an API key',
    'apikey_revoked' => 'Revoked an API key',

    // Client connectors
    'client_connection_tested' => 'Tested a client connection',
    'client_scan_run' => 'Scanned client code',
    'client_conversion_generated' => 'Generated a client conversion',
    'client_conversion_test_refused' => 'Refused an unsafe client conversion test',
    'connector_instance_disabled' => 'Disabled a connector',
    'connector_instance_removed' => 'Removed a connector',
    'connector_package_rejected' => 'Rejected a connector package',
    'supabase_account_connected' => 'Connected a Supabase account',
    'supabase_connection_tested' => 'Tested a Supabase connection',
    'supabase_project_selected' => 'Selected a Supabase project',
    'supabase_account_deleted' => 'Removed a Supabase account',
    'repository_linked' => 'Linked a client repository',

    // Functions, tasks, webhooks, storage
    'function_deployed' => 'Deployed a function',
    'function_rolled_back' => 'Rolled back a function',
    'function_invoked' => 'Invoked a function',
    'task_run' => 'Ran a task',
    'test_run' => 'Ran a test',
    'webhook_delivered' => 'Delivered a webhook',
    'storage_file_downloaded' => 'Downloaded a storage file',

    // Backups & restore
    'backup_destination_created' => 'Created a backup destination',
    'backup_policy_created' => 'Created a backup policy',
    'restore_drill_requested' => 'Requested a restore drill',
    'restore_drill_passed' => 'Restore drill passed',
    'restore_drill_failed' => 'Restore drill failed',

    // Infrastructure
    'node_token_rotated' => 'Rotated a node token',
    'resource_threshold_updated' => 'Updated resource thresholds',
    'cost_entry_updated' => 'Updated a cost estimate',

    // AI & agents
    'ai_provider_saved' => 'Saved the AI provider settings',
    'copilot_run' => 'Ran the migration copilot',
    'patch_generated' => 'Generated a patch',
    'patch_approved' => 'Approved a patch',
    'patch_rejected' => 'Rejected a patch',
    'patch_applied' => 'Applied a patch',
    'agent_runtime_created' => 'Created an agent runtime',
    'agent_runtime_updated' => 'Updated an agent runtime',
    'agent_runtime_tested' => 'Tested an agent runtime',
    'agent_task_created' => 'Created an agent task',
    'agent_task_cancelled' => 'Cancelled an agent task',
    'agent_task_failed' => 'An agent task failed',
    'agent_changeset_generated' => 'The agent generated changes',
    'agent_changeset_applied' => 'Applied the agent changes',
    'agent_file_applied' => 'Applied an agent file change',
    'agent_approval_granted' => 'Approved agent changes',
    'agent_approval_rejected' => 'Rejected agent changes',
    'agent_verification_recorded' => 'Recorded agent verification',
    'agent_apply_failed' => 'Applying agent changes failed',

    // Team & users
    'team_role_assigned' => 'Assigned a team role',
    'user_password_reset_sent' => 'Sent a password reset',
    'onboarding_started' => 'Started onboarding',
    'onboarding_completed' => 'Completed onboarding',
];
