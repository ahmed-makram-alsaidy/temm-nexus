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
    // H7: operator-level connection plumbing and runtime limits live behind
    // Advanced (endpoint and secret are promoted to the grid for an external
    // runtime, where they are the operator's primary setup fields).
    'advanced_section' => 'Advanced',
    'advanced_section_hint' => 'Connection plumbing and runtime limits — most setups never need these.',
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
    'verification_title' => 'Tests',
    'verification_passed' => 'Tests passed',
    'verification_failed' => 'Failed',
    'verification_failed_title' => ':count check(s) failed',
    'verification_error' => 'Error',
    'verification_skipped' => 'Skipped (no checks configured)',
    'verification_passed_body' => 'All verification checks passed after the changes were applied.',
    'verification_failed_body' => 'The changes were applied, but verification needs attention. Nothing was rolled back.',
    'verification_output_note' => 'Command output content is never stored — only each check\'s result, size and duration.',

    // ── 0.6.0 Phase G — status-first workbench ──────────────────────

    // Human task states (§G5) — the stored enums are never rewritten.
    'human_status_queued' => 'Waiting to start',
    'human_status_starting' => 'Starting',
    'human_status_planning' => 'Planning',
    'human_status_editing' => 'Editing files',
    'human_status_testing' => 'Running tests',
    'human_status_working' => 'Working',
    'human_status_awaiting_approval' => 'Ready for review',
    'human_status_applying' => 'Applying approved changes',
    'human_status_verifying' => 'Verifying',
    'human_status_completed' => 'Completed',
    'human_status_failed' => 'Needs attention',
    'human_status_cancelled' => 'Cancelled',
    'human_status_stale' => 'Needs re-review',

    // What happens next, per state (§G1).
    'next_queued' => 'Waiting for a free slot on the runtime.',
    'next_starting' => 'Preparing the isolated workspace and starting the agent.',
    'next_running' => 'The agent is working in the isolated copy of your project.',
    'next_awaiting_approval' => 'Review the changes below and decide — nothing is applied until you approve.',
    'next_applying' => 'Applying your approved changes to the repository.',
    'next_verifying' => 'Running verification checks against the applied changes.',
    'next_completed' => 'Done. The changes are in your repository.',
    'next_failed' => 'See what failed below — nothing harmful was applied.',
    'next_cancelled' => 'Cancelled. Nothing was applied.',
    'next_stale' => 'The reviewed changes changed after review — review again before approving.',

    // Activity timeline (§G6) — human sentences; raw events stay in Technical details.
    'timeline_status' => 'Task updated',
    'timeline_approved' => 'Approved by :name',
    'timeline_declined' => 'Changes declined — nothing was applied',
    'timeline_applied' => 'Changes applied',
    'timeline_task_started' => 'Task started',
    'timeline_session_started' => 'Agent session started',
    'timeline_changeset_ready' => 'Changes ready for review',
    'timeline_planning' => 'Planning the change',
    'timeline_reading' => 'Reading the project',
    'timeline_reading_path' => 'Reading :path',
    'timeline_editing' => 'Editing files',
    'timeline_updated' => 'Updated files',
    'timeline_updated_path' => 'Updated :path',
    'timeline_ran_command' => 'Ran :command',
    'timeline_ran_command_short' => 'Ran a command',
    'timeline_testing' => 'Running tests',
    'timeline_plan' => 'Shared a plan',
    'timeline_permission_allowed' => 'Platform allowed a runtime action',
    'timeline_permission_refused' => 'Platform refused a runtime action',
    'timeline_message' => 'Agent message',
    'timeline_error' => 'Task failed — :reason',
    'timeline_completed' => 'Task finished',
    'timeline_bounded' => 'Showing the latest :shown of :total events. Raw events are in Technical details.',

    // Failed task (§G18) — classified categories, never raw internals.
    'error_runtime_unavailable' => 'The agent runtime is unavailable.',
    'error_runtime_auth_failed' => 'The agent runtime refused the connection.',
    'error_model_unavailable' => 'The selected model is not available on the runtime.',
    'error_session_failed' => 'The agent session could not complete.',
    'error_workspace_failed' => 'The isolated workspace could not be prepared.',
    'error_command_failed' => 'A verification or apply step failed.',
    'error_task_cancelled' => 'The task was cancelled.',
    'error_invalid_runtime_response' => 'The agent runtime returned an unreadable response.',
    'error_timeout' => 'The task exceeded its time budget.',
    'error_generic' => 'The task could not complete.',
    'error_stage_line' => 'This happened at: :stage.',
    'stage_runtime_connection' => 'Runtime connection',
    'stage_workspace' => 'Preparing the isolated workspace',
    'stage_model' => 'Model selection',
    'stage_verification' => 'Verification',
    'stage_session' => 'The agent session',
    'stage_cancelled' => 'Cancellation',

    // Runtime state (§G4) + recovery (§G3).
    'runtime_connected' => 'Runtime connected',
    'runtime_unavailable' => 'Runtime unavailable',
    'runtime_needs_configuration' => 'Runtime needs configuration',
    'recovery_title' => 'Developer Agent isn\'t configured yet.',
    'recovery_body_admin' => 'Set up a runtime to start coding tasks.',
    'recovery_body_user' => 'Developer Agent isn\'t available yet. Ask an administrator to configure a runtime.',
    'recovery_no_project' => 'You need a project you can run agents on. Create or be added to a project first.',
    'recovery_cta' => 'Set up a runtime',

    // Decision card (§G10–§G12).
    'decision_title' => 'Ready for review',
    'decision_impact' => 'Approving applies this exact reviewed changeset to the authoritative repository:',
    'decision_explainer' => 'Approving applies this exact reviewed changeset to the authoritative repository and then runs verification.',
    'decision_no_deploy' => 'No deployment will happen automatically.',
    'approve_and_apply' => 'Approve & apply',
    'apply_approved' => 'Apply approved changes',
    'approve_without_apply_note' => 'Your role can approve, but applying the approved changes needs an operator with apply permission.',
    'apply_pending' => 'Approved — applying needs an operator with apply permission.',
    'reject_note_label' => 'Reason (optional)',
    'stale_title' => 'The proposed changes changed after review.',
    'stale_body' => 'Review the new diff before approving again.',

    // Cancel (§G17).
    'cancel_title' => 'Stop this task?',
    'cancel_copy' => 'Stops the agent task. Changes already applied are not automatically reverted.',

    // Retry (§G19).
    'retry_new_task' => 'Start a new task from this prompt',

    // History (§G20) + empty state (§G21).
    'filter_label' => 'Filter tasks',
    'filter_all' => 'All',
    'filter_active' => 'Active',
    'filter_ready' => 'Ready for review',
    'filter_completed' => 'Completed',
    'filter_failed' => 'Failed',
    'filter_cancelled' => 'Cancelled',
    'tasks_empty_body' => 'Ask Developer Agent to make a change in an isolated workspace. You review the diff before anything is applied.',
    'prompt_disclosure' => 'Show the task prompt',

    // Diff (§G7).
    'diff_files_changed' => 'files changed',
    'diff_view' => 'View diff',

    // Technical details (§G15/§G16).
    'tech_task_id' => 'Task id',
    'tech_session_id' => 'Agent session id',
    'tech_runtime' => 'Runtime',
    'tech_workspace_path' => 'Isolated workspace path',
    'tech_raw_events' => 'Raw events',
    'tech_approval' => 'Approval',
    'tech_approval_consumed' => 'used',
];
