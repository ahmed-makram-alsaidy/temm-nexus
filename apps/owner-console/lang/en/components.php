<?php

// ── Inspect Mode component labels (rc.5) ─────────────────────────────

return [
    'home.hero' => [
        'label' => 'Home header',
        'description' => 'The greeting, the one-sentence platform state, and the primary call to action.',
    ],
    'home.summary' => [
        'label' => 'Summary figures',
        'description' => 'The five headline numbers: projects, active migrations, live syncs, ready for cutover, needs attention.',
    ],
    'home.attention' => [
        'label' => 'Needs attention',
        'description' => 'Projects whose journey is blocked or degraded, with the reason for each.',
    ],
    'home.recent_projects' => [
        'label' => 'Recent projects',
        'description' => 'The projects you can reach, with environment, overall state, and migration progress.',
    ],
    'home.platform_health' => [
        'label' => 'Platform health',
        'description' => 'Backup coverage across your projects, in product language.',
    ],
    'project.overview.progress' => [
        'label' => 'Migration progress card',
        'description' => 'The progress percentage, its bar, and the current journey stage.',
    ],
    'project.overview.facts' => [
        'label' => 'Readiness facts',
        'description' => 'Health, Live Sync, last backup and readiness for this project.',
    ],
    'project.overview.journey' => [
        'label' => 'Migration journey',
        'description' => 'The seven-stage stepper showing where this migration is.',
    ],
    'project.overview.attention' => [
        'label' => 'Project attention list',
        'description' => 'What is blocking this project and what needs review.',
    ],
    'project.overview.activity' => [
        'label' => 'Recent activity',
        'description' => 'Recent audited actions taken on this project.',
    ],
    'project.overview.advanced' => [
        'label' => 'Advanced details',
        'description' => 'The raw technical telemetry, collapsed by default.',
    ],
    'cutover.overall' => [
        'label' => 'Overall readiness',
        'description' => 'The single READY / WARNING / BLOCKED answer for this cutover window.',
    ],
    'cutover.gates' => [
        'label' => 'Readiness gates',
        'description' => 'Every gate with its state and the evidence behind it.',
    ],
    'cutover.approvals' => [
        'label' => 'Human approvals',
        'description' => 'Which production-affecting gates have been decided, and by whom.',
    ],
    'cutover.plan' => [
        'label' => 'Ordered cutover plan',
        'description' => 'The ordered cutover steps, with operator-owned ones marked.',
    ],
    'ai.transcript' => [
        'label' => 'Conversation',
        'description' => 'The conversation so far, including which tools ran.',
    ],
    'ai.tools' => [
        'label' => 'Available read tools',
        'description' => 'Exactly the read tools you are permitted to run at the current scope.',
    ],
    'ai.scope_banner' => [
        'label' => 'Context banner',
        'description' => 'The scope the assistant is currently operating in.',
    ],
    'workspace.projects' => [
        'label' => 'Workspace projects',
        'description' => 'The projects in this workspace, with migration progress and stage.',
    ],
    'workspace.members' => [
        'label' => 'Workspace members',
        'description' => 'Who has access to this workspace and at what role.',
    ],
    'connectors.grid' => [
        'label' => 'Connector catalogue',
        'description' => 'Installed connectors with their capabilities, trust level and status.',
    ],
];
