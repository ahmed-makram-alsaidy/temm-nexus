<?php

namespace App\Services\Ai;

use App\Services\Access\Capability;

/**
 * 0.4.0 §18 — the typed READ tool registry for Nexus AI.
 *
 * Every tool is:
 *   - **narrow** — one question, one shape of answer;
 *   - **typed** — a declared parameter list and a structured result;
 *   - **scope-declared** — which context it may run in;
 *   - **capability-declared** — the exact capability the acting user must hold
 *     *at execution time*, on the object in context.
 *
 * There is deliberately NO general-purpose tool here: no "run SQL", no "read
 * file", no "query the database". A tool that could express an arbitrary query
 * would make every other guarantee in this file meaningless.
 *
 * Results are structured arrays of product facts. They must never contain
 * secret values, connection strings, password hashes, or customer rows — see
 * `FORBIDDEN_RESULT_KEYS`, enforced by `ToolDispatcher`.
 */
final class ToolRegistry
{
    /**
     * tool name => [
     *   scope:        Scope this tool belongs to,
     *   capability:   Capability the caller must hold,
     *   description:  one-line, user-facing explanation,
     *   parameters:   [name => ['type' => ..., 'required' => bool, 'description' => ...]],
     *   read_only:    always true in this registry,
     *   label:        progressive-disclosure label shown while running,
     * ]
     */
    public const TOOLS = [
        // ── PLATFORM scope ─────────────────────────────────────────────
        'get_platform_health' => [
            'scope' => Scope::PLATFORM,
            'capability' => Capability::INFRASTRUCTURE_VIEW,
            'description' => 'Overall platform health: services, queue, database, storage.',
            'parameters' => [],
            'read_only' => true,
            'label' => 'Checking platform health',
        ],
        'list_accessible_workspaces' => [
            'scope' => Scope::PLATFORM,
            'capability' => Capability::WORKSPACES_VIEW,
            'description' => 'The workspaces the signed-in user may see.',
            'parameters' => [],
            'read_only' => true,
            'label' => 'Listing your workspaces',
        ],
        'list_accessible_projects' => [
            'scope' => Scope::PLATFORM,
            'capability' => Capability::PROJECTS_VIEW,
            'description' => 'The projects the signed-in user may see.',
            'parameters' => [],
            'read_only' => true,
            'label' => 'Listing your projects',
        ],
        'get_infrastructure_health' => [
            'scope' => Scope::PLATFORM,
            'capability' => Capability::INFRASTRUCTURE_VIEW,
            'description' => 'Node and service health across the installation.',
            'parameters' => [],
            'read_only' => true,
            'label' => 'Checking infrastructure',
        ],
        'get_resource_summary' => [
            'scope' => Scope::PLATFORM,
            'capability' => Capability::INFRASTRUCTURE_VIEW,
            'description' => 'CPU, memory, and disk headroom, with thresholds.',
            'parameters' => [],
            'read_only' => true,
            'label' => 'Checking resources',
        ],
        'get_failed_jobs' => [
            'scope' => Scope::PLATFORM,
            'capability' => Capability::OPERATIONS_VIEW,
            'description' => 'Failed queue jobs, newest first.',
            'parameters' => [
                'limit' => ['type' => 'int', 'required' => false, 'description' => 'How many to return (default 10, max 50).'],
            ],
            'read_only' => true,
            'label' => 'Checking failed jobs',
        ],
        'get_recent_errors' => [
            'scope' => Scope::PLATFORM,
            'capability' => Capability::LOGS_VIEW,
            'description' => 'Recent application errors, redacted.',
            'parameters' => [
                'limit' => ['type' => 'int', 'required' => false, 'description' => 'How many to return (default 10, max 50).'],
            ],
            'read_only' => true,
            'label' => 'Reviewing recent errors',
        ],
        'get_audit_events' => [
            'scope' => Scope::PLATFORM,
            'capability' => Capability::AUDIT_VIEW,
            'description' => 'Recent audit events, including AI actions.',
            'parameters' => [
                'limit' => ['type' => 'int', 'required' => false, 'description' => 'How many to return (default 20, max 100).'],
            ],
            'read_only' => true,
            'label' => 'Reading the audit log',
        ],
        'get_connector_capabilities' => [
            'scope' => Scope::PLATFORM,
            'capability' => Capability::CONNECTORS_VIEW,
            'description' => 'Which connectors support migration, Live Sync, and CDC.',
            'parameters' => [
                'connector' => ['type' => 'string', 'required' => false, 'description' => 'A connector key, e.g. "postgres".'],
            ],
            'read_only' => true,
            'label' => 'Checking connector capabilities',
        ],

        // ── PROJECT scope ──────────────────────────────────────────────
        'get_project_summary' => [
            'scope' => Scope::PROJECT,
            'capability' => Capability::PROJECTS_VIEW,
            'description' => 'Identity, health, and migration state of the project in context.',
            'parameters' => [],
            'read_only' => true,
            'label' => 'Reading the project summary',
        ],
        'get_migration_state' => [
            'scope' => Scope::PROJECT,
            'capability' => Capability::MIGRATIONS_VIEW,
            'description' => 'Where the migration is: stage, progress, and blocking items.',
            'parameters' => [],
            'read_only' => true,
            'label' => 'Checking the migration state',
        ],
        'get_cdc_status' => [
            'scope' => Scope::PROJECT,
            'capability' => Capability::CDC_VIEW,
            'description' => 'Live Sync status: streaming, lag, and last applied event.',
            'parameters' => [],
            'read_only' => true,
            'label' => 'Checking Live Sync',
        ],
        'get_validation_summary' => [
            'scope' => Scope::PROJECT,
            'capability' => Capability::VALIDATION_VIEW,
            'description' => 'Validation results: row counts, checksums, and failures.',
            'parameters' => [],
            'read_only' => true,
            'label' => 'Checking validation',
        ],
        'get_cutover_readiness' => [
            'scope' => Scope::PROJECT,
            'capability' => Capability::CUTOVER_VIEW,
            'description' => 'Cutover readiness, and exactly what is blocking it.',
            'parameters' => [],
            'read_only' => true,
            'label' => 'Checking cutover readiness',
        ],
        'get_backup_status' => [
            'scope' => Scope::PROJECT,
            'capability' => Capability::BACKUPS_VIEW,
            'description' => 'Last backup, its age, and whether a restore was ever verified.',
            'parameters' => [],
            'read_only' => true,
            'label' => 'Checking backups',
        ],
        'get_project_activity' => [
            'scope' => Scope::PROJECT,
            'capability' => Capability::AUDIT_VIEW,
            'description' => 'Recent activity and audit events for this project.',
            'parameters' => [
                'limit' => ['type' => 'int', 'required' => false, 'description' => 'How many to return (default 20, max 100).'],
            ],
            'read_only' => true,
            'label' => 'Reading project activity',
        ],
    ];

    /**
     * Result keys that must never reach a model. `ToolDispatcher` rejects any
     * result containing one of these at the top level or one level down.
     * Belt-and-braces alongside the tools simply not selecting them.
     */
    public const FORBIDDEN_RESULT_KEYS = [
        'password', 'password_hash', 'secret', 'secret_encrypted', 'secret_ref',
        'api_key', 'token', 'access_token', 'refresh_token', 'private_key',
        'connection_string', 'dsn', 'credentials', 'authorization',
        'aws_secret_access_key', 'service_role_key', 'database_url',
    ];

    /** @return array<string, mixed>|null */
    public static function definition(string $tool): ?array
    {
        return self::TOOLS[$tool] ?? null;
    }

    public static function exists(string $tool): bool
    {
        return isset(self::TOOLS[$tool]);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::TOOLS);
    }

    /**
     * Tools usable from a given context: declared for an admitted scope AND
     * permitted by the acting user's capabilities.
     *
     * @return list<string>
     */
    public static function availableFor(AiContext $context): array
    {
        $out = [];

        foreach (self::TOOLS as $name => $definition) {
            /** @var Scope $toolScope */
            $toolScope = $definition['scope'];

            if (! $context->scope->admits($toolScope)) {
                continue;
            }

            if ($context->allows($definition['capability'])) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Tool descriptors safe to send to a model: no capability internals beyond
     * what the model needs to choose a tool.
     *
     * @return list<array<string, mixed>>
     */
    public static function schemaFor(AiContext $context): array
    {
        $out = [];

        foreach (self::availableFor($context) as $name) {
            $definition = self::TOOLS[$name];
            $out[] = [
                'name' => $name,
                'description' => $definition['description'],
                'parameters' => $definition['parameters'],
                'read_only' => true,
            ];
        }

        return $out;
    }

    /** User-facing label shown while a tool runs (0.4.0 §29). */
    public static function label(string $tool): string
    {
        return self::TOOLS[$tool]['label'] ?? 'Working';
    }
}
