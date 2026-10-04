<?php

/* ── 0.6.0 Phase B — Settings hierarchy (audit §B2) ───────────────────
   The Settings destination organizes product configuration: Members,
   AI (assistant + developer agents), Connectors, System (infrastructure).
   Deep links to every sub-page keep working; the hub is the map. */

return [
    'title' => 'Settings',
    'subtitle' => 'Platform configuration, access, AI, connectors and system health.',

    'members_title' => 'Members',
    'members_description' => 'Console users, roles and permissions.',
    'members_manage' => 'Manage members',

    'ai_title' => 'AI',
    'ai_description' => 'Configure the Nexus AI assistant and Developer Agent runtimes.',
    'ai_assistant' => 'Assistant',
    'ai_assistant_description' => 'AI provider, model and routing for the Nexus AI assistant.',
    'ai_agents' => 'Developer Agents',
    'ai_agents_description' => 'Agent runtimes, execution policy and task retention.',

    'connectors_title' => 'Connectors',
    'connectors_description' => 'Everything the platform can migrate from, and what each connector supports.',
    'connectors_browse' => 'Browse connectors',

    'system_title' => 'System',
    'system_description' => 'Infrastructure nodes, service assignments and platform health.',
    'system_nodes' => 'Nodes',
    'system_nodes_description' => 'Registered infrastructure nodes and their live status.',
    'system_services' => 'Services',
    'system_services_description' => 'Which node serves each platform service.',
    'system_health' => 'Health',
    'system_health_description' => 'Facet health across nodes and services.',
    'system_topology' => 'Topology',
    'system_topology_description' => 'How nodes and services connect.',

    'advanced_title' => 'Advanced',
];
