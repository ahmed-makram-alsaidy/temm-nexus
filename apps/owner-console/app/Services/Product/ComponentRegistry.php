<?php

namespace App\Services\Product;

/**
 * 0.4.0 Phase I — the INSPECTABLE COMPONENT REGISTRY.
 *
 * Inspect Mode lets a user click a UI component and attach its identity to the
 * Nexus AI conversation, so "this card is crowded" or "this number is not
 * updating" has a referent that is precise and safe.
 *
 * WHY A REGISTRY, AND WHY SERVER-SIDE
 * The browser sends a component KEY, never a description. That key is resolved
 * HERE. So the model receives a platform-authored description of a component
 * this platform defines — not attacker-controlled text typed into a data
 * attribute. A page cannot invent a component, and a manipulated DOM cannot
 * smuggle instructions into the prompt through the inspect channel.
 *
 * Each entry declares:
 *   label          what a person calls it
 *   page           the screen it appears on
 *   scope          which of PLATFORM / WORKSPACE / PROJECT it belongs to
 *   data_source    the server-side origin of the numbers it renders
 *   capability     the capability a user must hold to inspect it, so Inspect
 *                  Mode cannot reveal the existence of data you cannot see
 *   description    one honest sentence about what it shows
 *   adjustments    the structured UI preferences that may be applied to it.
 *                  This list EXACTLY matches what the views implement — an
 *                  adjustment that is declared here must be previewable and
 *                  appliable, never a promise the UI cannot keep.
 */
final class ComponentRegistry
{
    /**
     * Component key => definition.
     *
     * @var array<string, array{
     *     label: string, page: string, scope: string, data_source: string,
     *     capability: string, description: string,
     *     adjustments: list<string>
     * }>
     */
    public const COMPONENTS = [
        // ── Platform Home ──────────────────────────────────────────────
        'home.hero' => [
            'label' => 'Home header',
            'page' => 'Home',
            'scope' => 'platform',
            'data_source' => 'PlatformPulse (local counts, no network)',
            'capability' => 'ai.use',
            'description' => 'The greeting, the one-sentence platform state, and the primary call to action.',
            'adjustments' => ['density'],
        ],
        'home.summary' => [
            'label' => 'Summary figures',
            'page' => 'Home',
            'scope' => 'platform',
            'data_source' => 'PlatformPulse::summary() — scoped to your reachable projects',
            'capability' => 'ai.use',
            'description' => 'The five headline numbers: projects, active migrations, live syncs, ready for cutover, needs attention.',
            'adjustments' => ['visibility', 'density'],
        ],
        'home.attention' => [
            'label' => 'Needs attention',
            'page' => 'Home',
            'scope' => 'platform',
            'data_source' => 'ProjectPulse::blockers() and ::warnings() per reachable project',
            'capability' => 'ai.use',
            'description' => 'Projects whose journey is blocked or degraded, with the reason for each.',
            'adjustments' => ['visibility'],
        ],
        'home.recent_projects' => [
            'label' => 'Recent projects',
            'page' => 'Home',
            'scope' => 'platform',
            'data_source' => 'Access::accessibleProjects()',
            'capability' => 'projects.view',
            'description' => 'The projects you can reach, with environment, overall state, and migration progress.',
            'adjustments' => ['visibility', 'density'],
        ],
        'home.platform_health' => [
            'label' => 'Platform health',
            'page' => 'Home',
            'scope' => 'platform',
            'data_source' => 'PlatformPulse::backupSummary()',
            'capability' => 'backups.view',
            'description' => 'Backup coverage across your projects, in product language.',
            'adjustments' => ['visibility'],
        ],

        // ── Project Overview ───────────────────────────────────────────
        'project.overview.progress' => [
            'label' => 'Migration progress card',
            'page' => 'Project Overview',
            'scope' => 'project',
            'data_source' => 'ProjectPulse::progressPercent() and ::currentStage()',
            'capability' => 'migrations.view',
            'description' => 'The progress percentage, its bar, and the current journey stage.',
            'adjustments' => ['position'],
        ],
        'project.overview.facts' => [
            'label' => 'Readiness facts',
            'page' => 'Project Overview',
            'scope' => 'project',
            'data_source' => 'ProjectPulse — health, liveSync(), backup(), blockers()',
            'capability' => 'projects.view',
            'description' => 'Health, Live Sync, last backup and readiness for this project.',
            'adjustments' => ['visibility'],
        ],
        'project.overview.journey' => [
            'label' => 'Migration journey',
            'page' => 'Project Overview',
            'scope' => 'project',
            'data_source' => 'ProjectPulse::journey() — seven stages derived from real records',
            'capability' => 'migrations.view',
            'description' => 'The seven-stage stepper showing where this migration is.',
            'adjustments' => ['density'],
        ],
        'project.overview.attention' => [
            'label' => 'Project attention list',
            'page' => 'Project Overview',
            'scope' => 'project',
            'data_source' => 'ProjectPulse::blockers() and ::warnings()',
            'capability' => 'projects.view',
            'description' => 'What is blocking this project and what needs review.',
            'adjustments' => ['visibility'],
        ],
        'project.overview.activity' => [
            'label' => 'Recent activity',
            'page' => 'Project Overview',
            'scope' => 'project',
            'data_source' => 'AdminAuditEntry for this project',
            'capability' => 'audit.view',
            'description' => 'Recent audited actions taken on this project.',
            'adjustments' => ['visibility'],
        ],
        'project.overview.advanced' => [
            'label' => 'Advanced details',
            'page' => 'Project Overview',
            'scope' => 'project',
            'data_source' => 'ProjectOverviewData — database, Pulse, queues, storage, functions',
            'capability' => 'projects.view',
            'description' => 'The raw technical telemetry, collapsed by default.',
            'adjustments' => ['expanded_by_default'],
        ],

        // ── Cutover ────────────────────────────────────────────────────
        'cutover.overall' => [
            'label' => 'Overall readiness',
            'page' => 'Cutover',
            'scope' => 'project',
            'data_source' => 'CutoverReadiness::overall() over the persisted preflight gates',
            'capability' => 'cutover.view',
            'description' => 'The single READY / WARNING / BLOCKED answer for this cutover window.',
            'adjustments' => ['density'],
        ],
        'cutover.gates' => [
            'label' => 'Readiness gates',
            'page' => 'Cutover',
            'scope' => 'project',
            'data_source' => 'CutoverCenterService::preflight() — each gate carries its own evidence',
            'capability' => 'cutover.view',
            'description' => 'Every gate with its state and the evidence behind it.',
            'adjustments' => ['visibility', 'density'],
        ],
        'cutover.approvals' => [
            'label' => 'Human approvals',
            'page' => 'Cutover',
            'scope' => 'project',
            'data_source' => 'CutoverApproval rows for the current plan',
            'capability' => 'cutover.view',
            'description' => 'Which production-affecting gates have been decided, and by whom.',
            'adjustments' => ['visibility'],
        ],
        'cutover.plan' => [
            'label' => 'Ordered cutover plan',
            'page' => 'Cutover',
            'scope' => 'project',
            'data_source' => 'CutoverCenterService ordered steps',
            'capability' => 'cutover.view',
            'description' => 'The ordered cutover steps, with operator-owned ones marked.',
            'adjustments' => ['visibility'],
        ],

        // ── Nexus AI ───────────────────────────────────────────────────
        'ai.transcript' => [
            'label' => 'Conversation',
            'page' => 'Nexus AI',
            'scope' => 'platform',
            'data_source' => 'The current session transcript',
            'capability' => 'ai.use',
            'description' => 'The conversation so far, including which tools ran.',
            'adjustments' => ['density'],
        ],
        'ai.tools' => [
            'label' => 'Available read tools',
            'page' => 'Nexus AI',
            'scope' => 'platform',
            'data_source' => 'ToolDispatcher::availableTools() for the acting user',
            'capability' => 'ai.use',
            'description' => 'Exactly the read tools you are permitted to run at the current scope.',
            'adjustments' => ['visibility'],
        ],
        'ai.scope_banner' => [
            'label' => 'Context banner',
            'page' => 'Nexus AI',
            'scope' => 'platform',
            'data_source' => 'AiContext, derived from the route',
            'capability' => 'ai.use',
            'description' => 'The scope the assistant is currently operating in.',
            'adjustments' => ['density'],
        ],

        // ── Workspace ──────────────────────────────────────────────────
        'workspace.projects' => [
            'label' => 'Workspace projects',
            'page' => 'Workspace',
            'scope' => 'workspace',
            'data_source' => 'Access::accessibleProjects() filtered to this workspace + ProjectPulse',
            'capability' => 'projects.view',
            'description' => 'The projects in this workspace, with migration progress and stage.',
            'adjustments' => ['visibility', 'density'],
        ],
        'workspace.members' => [
            'label' => 'Workspace members',
            'page' => 'Workspace',
            'scope' => 'workspace',
            'data_source' => 'WorkspaceMember rows',
            'capability' => 'workspace.members.view',
            'description' => 'Who has access to this workspace and at what role.',
            'adjustments' => ['visibility'],
        ],

        // ── Connectors ─────────────────────────────────────────────────
        'connectors.grid' => [
            'label' => 'Connector catalogue',
            'page' => 'Connectors',
            'scope' => 'platform',
            'data_source' => 'ConnectorRegistry manifests through ConnectorCatalogView',
            'capability' => 'connectors.view',
            'description' => 'Installed connectors with their capabilities, trust level and status.',
            'adjustments' => ['density'],
        ],
    ];

    /**
     * Every adjustment this release implements, with its value contract.
     * The views and `UiPreferenceService` agree on this list; anything else
     * is a CODE change and belongs to the isolated patch workflow.
     *
     * @var array<string, string>
     */
    public const ADJUSTMENTS = [
        'visibility' => 'boolean — false hides the component for this user',
        'density' => 'comfortable | compact | spacious',
        'expanded_by_default' => 'boolean — disclosure panels start open',
        'position' => 'first | last — placement within its group',
    ];

    public static function exists(string $key): bool
    {
        return isset(self::COMPONENTS[$key]);
    }

    /** @return array<string, mixed>|null */
    public static function definition(string $key): ?array
    {
        return self::COMPONENTS[$key] ?? null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::COMPONENTS);
    }

    /**
     * The structured adjustments a component permits.
     *
     * @return list<string>
     */
    public static function adjustmentsFor(string $key): array
    {
        return self::definition($key)['adjustments'] ?? [];
    }

    /** Does this component permit this adjustment? The Phase I whitelist. */
    public static function permits(string $key, string $adjustment): bool
    {
        return in_array($adjustment, self::adjustmentsFor($key), true);
    }

    /** Is this adjustment one the platform implements at all? */
    public static function isAdjustment(string $adjustment): bool
    {
        return isset(self::ADJUSTMENTS[$adjustment]);
    }

    /**
     * The value an adjustment takes when the user has not set one — i.e. what
     * the view renders for everyone by default.
     */
    public static function defaultFor(string $adjustment): mixed
    {
        return match ($adjustment) {
            'visibility' => true,
            'expanded_by_default' => false,
            'density' => 'comfortable',
            'position' => null,
            default => null,
        };
    }

    /** The capability a user must hold to inspect this component. */
    public static function capabilityFor(string $key): string
    {
        return self::definition($key)['capability'] ?? 'ai.inspect';
    }

    /** @return list<array<string, mixed>> components declared on one page */
    public static function forPage(string $page): array
    {
        $out = [];
        foreach (self::COMPONENTS as $key => $definition) {
            if ($definition['page'] === $page) {
                $out[] = ['key' => $key] + $definition;
            }
        }

        return $out;
    }

    /**
     * Validate a browser-supplied component key.
     *
     * Anything unknown is REJECTED rather than passed through, so a tampered
     * DOM cannot inject free text into the assistant's context.
     */
    public static function validate(string $key): ?string
    {
        return self::exists($key) ? $key : null;
    }
}
