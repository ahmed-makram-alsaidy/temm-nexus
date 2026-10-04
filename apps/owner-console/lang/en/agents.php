<?php

/*
|--------------------------------------------------------------------------
| Developer Agent / Agent Runtime (Phase 43)
|--------------------------------------------------------------------------
|
| User-facing strings for the Developer Agent runtime platform. Technical
| identifiers (model ids, revisions, paths, commands, endpoints) are always
| rendered LTR and are never translated.
*/

return [
    // Navigation & headings
    'nav_settings' => 'Developer Agents',
    'nav_workbench' => 'Developer Agent',
    'settings_title' => 'Developer Agents',
    'settings_subtitle' => 'Configure coding-agent runtimes. Tasks run in isolated workspaces and never touch source without your approval.',
    'workbench_title' => 'Developer Agent',
    'workbench_subtitle' => 'Delegate a coding task, watch it work in an isolated workspace, review the diff, then approve and apply.',

    // Runtimes list
    'runtimes_title' => 'Configured runtimes',
    'runtimes_empty' => 'No agent runtime is configured yet.',
    'status_untested' => 'Untested',
    'unknown' => 'Unknown',
    'model_runtime_default_none' => 'Runtime default (not set)',
    'test_never' => 'Never tested',
    'mode_managed' => 'Managed',
    'mode_external' => 'External',
    'managed_secret' => 'Service authentication',
    'managed_secret_set' => 'Configured via stack secret',
    'managed_secret_missing' => 'OPENCODE_SERVER_PASSWORD is not set',

    // Runtime form
    'new_runtime_title' => 'Add a runtime',
    'edit_runtime_title' => 'Edit runtime',
    'field_driver' => 'Runtime',
    'field_display_name' => 'Display name',
    'field_mode' => 'Deployment mode',
    'mode_helper' => 'Managed runs inside this platform’s Docker stack; External points at a server you operate yourself.',
    'field_endpoint' => 'Endpoint URL',
    'endpoint_helper' => 'External endpoints must use HTTPS. Managed runtimes use the internal service address.',
    'endpoint_required' => 'An endpoint URL is required for external runtimes.',
    'field_auth_secret' => 'Authentication password',
    'auth_secret_helper' => 'Write-only. Leave empty to keep the stored value. Stored encrypted, never displayed again.',
    'field_default_model' => 'Default model',
    'default_model_helper' => 'Canonical provider/model id, exactly as the runtime reports it (e.g. google/gemini-2.5-pro).',
    'field_timeout' => 'Task time budget (seconds)',
    'field_concurrency' => 'Max concurrent tasks',
    'field_retention' => 'Workspace retention (days)',
    'retention_helper' => 'Isolated workspaces are deleted after this many days. Zero retains them until reviewed and cleaned manually.',
    'field_version' => 'Runtime version',
    'field_last_test' => 'Last connection test',

    // Runtime actions
    'edit' => 'Edit',
    'test_connection' => 'Test connection',
    'test_passed' => 'Connection passed',
    'test_failed' => 'Connection failed',
    'discover_models' => 'Discover models',
    'models_failed' => 'Model discovery failed',
    'models_discovered' => ':count models discovered (showing first 20).',
    'models_truncated' => 'List truncated for display.',
    'saved' => 'Runtime saved.',

    // Execution policy
    'policy_title' => 'Execution policy (v1)',
    'policy_help' => 'What an agent runtime may do. These boundaries are enforced by the platform, not by the interface.',
    'policy_column' => 'Capability',
    'verdict_column' => 'Boundary',
    'policy_read' => 'Read files',
    'policy_write' => 'Write files',
    'policy_execute' => 'Execute commands',
    'policy_network' => 'Network access',
    'policy_apply' => 'Apply to source',
    'policy_deploy' => 'Deploy',
    'verdict_runtime' => 'Inside the assigned workspace only',
    'verdict_approval' => 'Explicit human approval required',
    'verdict_denied' => 'Not available in this release',

    // Workbench — new task
    'new_task_title' => 'Start a task',
    'new_task_help' => 'The agent works in a fresh isolated copy of your project. Nothing reaches the real source until you approve the diff.',
    'new_task_unavailable' => 'You need an enabled runtime and a project you can run agents on.',
    'field_project' => 'Project',
    'field_runtime' => 'Runtime',
    'field_model' => 'Model',
    'model_runtime_default' => 'Runtime default',
    'model_helper' => 'Model ids come from the runtime. Leave empty to use the runtime default.',
    'field_title' => 'Title (optional)',
    'field_prompt' => 'Task',
    'start_task' => 'Start task',
    'task_created' => 'Task :code created.',
    'task_create_failed' => 'Could not create the task.',

    // Workbench — task list & detail
    'tasks_title' => 'Recent tasks',
    'tasks_empty' => 'No agent tasks yet.',
    'field_base_revision' => 'Source revision',
    'field_error' => 'Error',
    'field_usage' => 'Usage',
    'approve' => 'Approve changeset',
    'reject' => 'Reject',
    'apply' => 'Apply to source',
    'cancel_task' => 'Cancel task',
    'approval_granted' => 'Changeset approved.',
    'approval_rejected' => 'Changeset rejected.',
    'approval_failed' => 'Approval action failed.',
    'applied' => 'Changeset applied to source.',
    'apply_failed' => 'Apply failed.',
    'cancelled' => 'Task cancelled.',
    'cancel_failed' => 'Cancellation failed.',

    // Task statuses
    'status_queued' => 'Queued',
    'status_starting' => 'Starting',
    'status_running' => 'Running',
    'status_awaiting_approval' => 'Waiting for approval',
    'status_applying' => 'Applying',
    'status_verifying' => 'Verifying',
    'status_completed' => 'Completed',
    'status_failed' => 'Failed',
    'status_cancelled' => 'Cancelled',
    'status_stale' => 'Stale',

    // Activity stream
    'activity_title' => 'Activity',
    'activity_empty' => 'No activity yet.',
    'event_status' => 'Status',
    'event_thinking' => 'Reasoning',
    'event_reading' => 'Reading',
    'event_editing' => 'Editing',
    'event_file_changed' => 'File changed',
    'event_command' => 'Command',
    'event_testing' => 'Testing',
    'event_plan' => 'Plan',
    'event_permission' => 'Permission',
    'event_message' => 'Message',
    'event_error' => 'Error',
    'event_completed' => 'Completed',

    // Commands
    'commands_title' => 'Commands executed',
    'commands_empty' => 'No commands were executed.',
    'field_command' => 'Command',
    'field_exit_code' => 'Exit',
    'field_duration' => 'Duration',
    'field_output' => 'Output',
    'field_status' => 'Status',
    'command_status_running' => 'Running',
    'command_status_completed' => 'Completed',
    'command_status_failed' => 'Failed',
    'command_status_timeout' => 'Timed out',
    'command_status_cancelled' => 'Cancelled',
    'commands_privacy_note' => 'Command output content is never stored — only size and truncation state.',
    'truncated' => 'truncated',

    // Diff
    'diff_title' => 'Changeset',
    'diff_files' => 'Files (added / modified / deleted)',
    'diff_fingerprint' => 'Changeset fingerprint',
    'diff_truncated' => 'This changeset exceeds the size limit and cannot be applied automatically.',

    // Verification
    'verification_title' => 'Verification',
    'verification_passed' => 'Passed',
    'verification_failed' => 'Failed',
    'verification_error' => 'Error',
    'verification_skipped' => 'Skipped (no commands configured)',
];
