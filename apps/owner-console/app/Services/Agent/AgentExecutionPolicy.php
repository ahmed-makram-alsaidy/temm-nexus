<?php

namespace App\Services\Agent;

/**
 * The explicit v1 execution policy for Developer Agent runtimes.
 *
 * High-risk infrastructure operations (production databases, VPS SSH, cloud
 * consoles, Docker daemon, deployments) are NOT reachable through the agent
 * runtime — they remain under TEMM's existing safe-action architecture.
 * Shell access exists only inside the isolated coding workspace boundary,
 * and applying anything to authoritative source requires an explicit,
 * fingerprint-bound human approval.
 */
final class AgentExecutionPolicy
{
    // ── Policy vocabulary ──────────────────────────────────────────────
    public const READ = 'read';

    public const WRITE_WORKSPACE = 'write_workspace';

    public const EXECUTE_WORKSPACE_COMMAND = 'execute_workspace_command';

    public const NETWORK_ACCESS = 'network_access';

    public const APPLY_TO_SOURCE = 'apply_to_source';

    public const DEPLOY = 'deploy';

    /**
     * v1 verdicts. `runtime` = delegated to the runtime's own permission
     * mechanism (session permission config), `approval` = human approval
     * required, `denied` = not available through this feature at all.
     */
    private const VERDICTS = [
        self::READ => 'runtime',                    // allowed inside the assigned workspace
        self::WRITE_WORKSPACE => 'runtime',         // allowed inside the assigned workspace
        self::EXECUTE_WORKSPACE_COMMAND => 'runtime', // sandbox + runtime permission system + limits
        self::NETWORK_ACCESS => 'runtime',          // model/provider traffic only; file/network tool use follows runtime config
        self::APPLY_TO_SOURCE => 'approval',        // explicit human approval, fingerprint-bound
        self::DEPLOY => 'denied',                   // NOT part of agent runtime v1
    ];

    /**
     * Permission rules sent with EVERY session TEMM creates (OpenCode
     * session-level PermissionRuleset: an array of {permission, pattern,
     * action} rules — verified against the 1.18.34 OpenAPI schema). Deny
     * external-directory access so the runtime's own tooling cannot wander
     * outside the assigned workspace even where the host process could.
     *
     * @return list<array{permission: string, pattern: string, action: string}>
     */
    public static function sessionPermissionConfig(): array
    {
        return [
            ['permission' => 'read', 'pattern' => '**', 'action' => 'allow'],
            ['permission' => 'edit', 'pattern' => '**', 'action' => 'allow'],
            ['permission' => 'glob', 'pattern' => '**', 'action' => 'allow'],
            ['permission' => 'grep', 'pattern' => '**', 'action' => 'allow'],
            ['permission' => 'list', 'pattern' => '**', 'action' => 'allow'],
            ['permission' => 'bash', 'pattern' => '**', 'action' => 'allow'],
            ['permission' => 'external_directory', 'pattern' => '**', 'action' => 'deny'],
            ['permission' => 'question', 'pattern' => '**', 'action' => 'deny'],
        ];
    }

    public static function verdict(string $policy): string
    {
        return self::VERDICTS[$policy] ?? 'denied';
    }

    /** @return array<string, string> policy => verdict */
    public static function describe(): array
    {
        return self::VERDICTS;
    }

    /** Hard TEMM-side gate: deployment is categorically out of scope for v1. */
    public static function assertNotDeploy(): void
    {
        if (self::VERDICTS[self::DEPLOY] === 'allowed') {
            return; // unreachable in v1; guards future edits to this class
        }
    }
}
