<?php

// ── Product navigation (0.4.0-rc.5, Phase 41) ────────────────────────
// Values for `en` mirror the 0.4.0 strings exactly; ProductNavigationTest
// and phase tests assert on them. Arabic lives in lang/ar/nav.php.

return [

    // Top-level entries (ProductNavigation::PLATFORM_ENTRIES order).
    'home' => 'Home',
    'projects' => 'Projects',
    'workspaces' => 'Clients & Workspaces',
    'connectors' => 'Connectors',
    'nexus_ai' => 'Nexus AI',
    'operations' => 'Operations',
    'infrastructure' => 'Infrastructure',
    'security' => 'Security',
    'settings' => 'Settings',

    // Operations group.
    'queues' => 'Queues',
    'logs' => 'Logs',
    'monitoring' => 'Monitoring',
    'scheduler' => 'Scheduler',
    'webhooks' => 'Webhooks',
    'realtime' => 'Realtime',

    // Infrastructure group.
    'nodes' => 'Nodes',
    'services' => 'Services',
    'health' => 'Health',
    'topology' => 'Topology',

    // Security group.
    'members' => 'Members',
    'audit_log' => 'Audit log',

    // Settings group.
    'get_started' => 'Get started',
    'search' => 'Search',
    'nexus_ai_settings' => 'Nexus AI Settings',
    'language_region' => 'Language',

    // Project workspace sidebar groups.
    'group_database' => 'Database',
    'group_authentication' => 'Authentication',
    'group_build' => 'Build',
    'group_operate' => 'Operate',
    'group_project' => 'Project',

    // Project workspace sidebar items.
    'tables' => 'Tables',
    'erd' => 'ERD',
    'sql_editor' => 'SQL Editor',
    'schema' => 'Schema',
    'functions' => 'Functions',
    'inspector' => 'Inspector',
    'migrations' => 'Migrations',
    'schema_diff' => 'Schema Diff',
    'db_health' => 'Health',
    'connections' => 'Connections',
    'users' => 'Users',
    'roles' => 'Roles',
    'permissions' => 'Permissions',
    'sessions' => 'Sessions',
    'providers' => 'Providers',
    'secrets' => 'Secrets',
    'storage' => 'Storage',
    'api' => 'API',
    'connect' => 'Connect',
    'api_keys' => 'API Keys',
    'backups' => 'Backups',
    'resources' => 'Resources',
    'readiness' => 'Readiness',
    'environments' => 'Environments',
    'migration' => 'Migration',
    'migration_center' => 'Migration Center',
    'cutover' => 'Cutover',
    'client_repository' => 'Client Repository',
    'ai_copilot' => 'AI Copilot',
    'overview' => 'Overview',
    'team' => 'Team',

    // ── 0.6.0 Phase B — simplified product shell ─────────────────────────
    // Platform destinations (Home · Projects · Clients · Activity · AI ·
    // Settings) and the project context model (tabs, not sidebar links).
    'clients' => 'Clients',
    'activity' => 'Activity',
    'ai_group' => 'AI',
    'developer_agent' => 'Developer Agent',

    // Project primary tabs (ProjectTabs::TABS order).
    'tab_migration' => 'Migration',
    'tab_data' => 'Data',
    'tab_access' => 'Access',

    // Project context chrome.
    'project_context' => 'Project areas',
    'active_environment' => 'Active environment — open to switch',
    'manage_environments' => 'Manage environments',
    'inactive' => 'inactive',
];
