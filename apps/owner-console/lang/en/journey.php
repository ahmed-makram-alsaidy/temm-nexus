<?php

// ── Journey states — 0.4.0-rc.5 (Phase 41) ───────────────────────────
// Presentation labels for internal enum values (C.11). Keys follow the
// JourneyState case names so label() stays a thin translation lookup.

return [

    'state_not_started' => 'Not started',
    'state_in_progress' => 'In progress',
    'state_ready' => 'Ready',
    'state_needs_attention' => 'Needs attention',
    'state_blocked' => 'Blocked',
    'state_complete' => 'Complete',

    // Journey stage names (x-nx-journey stepper).
    'stage_connect' => 'Connect',
    'stage_analyze' => 'Analyze',
    'stage_migrate' => 'Migrate',
    'stage_plan' => 'Plan',
'stage_sync' => 'Live Sync',
    'stage_validate' => 'Validate',
    'stage_cutover' => 'Cutover',

    'desc_connect' => 'Link the source you are migrating from.',
    'desc_analyze' => 'Inventory tables, rows, and compatibility.',
    'desc_plan' => 'Review the plan and the changes it will make.',
    'desc_migrate' => 'Move the initial dataset.',
    'desc_sync' => 'Keep changes flowing while you prepare to switch.',
    'desc_validate' => 'Confirm the data matches.',
    'desc_cutover' => 'Switch production over, with a rollback ready.',
];
