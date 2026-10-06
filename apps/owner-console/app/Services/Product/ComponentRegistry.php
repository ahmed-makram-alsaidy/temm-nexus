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
        'home.continue' => [
            'label' => 'Continue where you left off',
            'page' => 'Home',
            'scope' => 'platform',
            'data_source' => 'PlatformPulse::continueTarget() — newest active run or latest real event',
            'capability' => 'ai.use',
            'description' => 'The one project worth resuming, with its journey stage and last real activity.',
            'adjustments' => ['visibility'],
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
        'home.activity' => [
            'label' => 'Recent activity',
            'page' => 'Home',
            'scope' => 'platform',
            'data_source' => 'PlatformPulse::recentActivity() — humanized audit events',
            'capability' => 'audit.view',
            'description' => 'The latest real events in your projects, in human sentences.',
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

        // ── Migration journey (0.6.0 Phase E) ──────────────────────────
        'migration.stages' => [
            'label' => 'Migration stage navigation',
            'page' => 'Migration',
            'scope' => 'project',
            'data_source' => 'ProjectPulse::stageState() — the canonical journey model',
            'capability' => 'migrations.view',
            'description' => 'The six journey stages (Connect → Cutover) with their real states.',
            'adjustments' => ['visibility'],
        ],
        'migration.connect' => [
            'label' => 'Connect stage',
            'page' => 'Migration',
            'scope' => 'project',
            'data_source' => 'MigrationSource rows + the source-connection status model',
            'capability' => 'migrations.view',
            'description' => 'Source connection and destination status, with the last successful test.',
            'adjustments' => ['visibility'],
        ],
        'migration.analyze' => [
            'label' => 'Analyze stage',
            'page' => 'Migration',
            'scope' => 'project',
            'data_source' => 'MigrationAnalysis rows — counts, warnings and real stage telemetry',
            'capability' => 'migrations.view',
            'description' => 'The latest analysis summary, its warnings and its real progress stages.',
            'adjustments' => ['visibility'],
        ],
        'migration.plan' => [
            'label' => 'Plan stage',
            'page' => 'Migration',
            'scope' => 'project',
            'data_source' => 'MigrationPlan items grouped by dependency stage',
            'capability' => 'migrations.view',
            'description' => 'What the transfer will move, in dependency order, and what it may not.',
            'adjustments' => ['visibility'],
        ],
        'migration.sync' => [
            'label' => 'Sync stage',
            'page' => 'Migration',
            'scope' => 'project',
            'data_source' => 'MigrationRun rows — latest run plus a bounded history slice',
            'capability' => 'migrations.view',
            'description' => 'The current transfer run: status, progress, direction and mode.',
            'adjustments' => ['visibility'],
        ],
        'migration.verify' => [
            'label' => 'Verify stage',
            'page' => 'Migration',
            'scope' => 'project',
            'data_source' => 'ReadinessService::summary() — validation + readiness evidence',
            'capability' => 'projects.view',
            'description' => 'Passed / needs review / blocked checks with their evidence, in one place.',
            'adjustments' => ['visibility'],
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

        // ── Developer Agent (0.6.0 Phase G) ─────────────────────────────
        // The workbench surfaces are inspectable: the registry describes what
        // each one IS and WHERE its data comes from — never runtime secrets,
        // session ids, or workspace paths. The page rejects a client-supplied
        // description the same way every other page does.
        'agent.new_task' => [
            'label' => 'Start a task',
            'page' => 'Developer Agent',
            'scope' => 'platform',
            'data_source' => 'Runnable projects + AgentRuntimeManager model discovery',
            'capability' => 'agents.run',
            'description' => 'The form that hands a coding task to the agent runtime.',
            'adjustments' => ['visibility'],
        ],
        'agent.tasks' => [
            'label' => 'Task history',
            'page' => 'Developer Agent',
            'scope' => 'platform',
            'data_source' => 'AgentTask rows within the caller\'s project reach',
            'capability' => 'agents.view',
            'description' => 'Recent agent tasks with their human state and last activity.',
            'adjustments' => ['visibility', 'density'],
        ],
        'agent.detail' => [
            'label' => 'Task detail',
            'page' => 'Developer Agent',
            'scope' => 'platform',
            'data_source' => 'AgentTask + AgentStatusPresenter (status-first facts)',
            'capability' => 'agents.view',
            'description' => 'The selected task: what it is, its human state, and what happens next.',
            'adjustments' => ['density'],
        ],
        'agent.diff' => [
            'label' => 'What changed',
            'page' => 'Developer Agent',
            'scope' => 'platform',
            'data_source' => 'AgentChangeset — the exact diff bound to approval',
            'capability' => 'agents.view',
            'description' => 'The changeset summary, its file list, and the real diff.',
            'adjustments' => ['visibility'],
        ],
        'agent.verification' => [
            'label' => 'Tests',
            'page' => 'Developer Agent',
            'scope' => 'platform',
            'data_source' => 'AgentVerification results',
            'capability' => 'agents.view',
            'description' => 'Verification checks and their outcomes after an apply.',
            'adjustments' => ['visibility'],
        ],
        'agent.decision' => [
            'label' => 'Your decision',
            'page' => 'Developer Agent',
            'scope' => 'platform',
            'data_source' => 'AgentTask approval state + the capability rules',
            'capability' => 'agents.approve',
            'description' => 'The review card: what approval means, and the approve/reject decision.',
            'adjustments' => ['visibility'],
        ],
        'agent.activity' => [
            'label' => 'Activity',
            'page' => 'Developer Agent',
            'scope' => 'platform',
            'data_source' => 'AgentTaskEvent stream, humanized by AgentStatusPresenter',
            'capability' => 'agents.view',
            'description' => 'What the agent has been doing, in human sentences.',
            'adjustments' => ['visibility', 'density'],
        ],
        'agent.technical' => [
            'label' => 'Technical details',
            'page' => 'Developer Agent',
            'scope' => 'platform',
            'data_source' => 'Session, workspace, fingerprint, commands, raw events (disclosure)',
            'capability' => 'agents.view',
            'description' => 'Level-3/4 internals: session ids, command ledger, raw events.',
            'adjustments' => ['visibility'],
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
        $definition = self::COMPONENTS[$key] ?? null;

        return $definition === null ? null : self::localized($key, $definition);
    }

    /**
     * rc.5 localization (C.1): labels/descriptions translate at READ time —
     * the const stays a compile-time-safe canonical English fallback.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private static function localized(string $key, array $definition): array
    {
        $definition['label'] = __("components.{$key}.label") !== "components.{$key}.label"
            ? __("components.{$key}.label")
            : $definition['label'];
        $definition['description'] = __("components.{$key}.description") !== "components.{$key}.description"
            ? __("components.{$key}.description")
            : $definition['description'];

        return $definition;
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
                $out[] = ['key' => $key] + self::localized($key, $definition);
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
