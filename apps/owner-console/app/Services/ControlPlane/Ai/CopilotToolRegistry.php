<?php

namespace App\Services\ControlPlane\Ai;

/**
 * Phase 25H — explicit AI tool / permission model.
 *
 * The Copilot NEVER receives shell or unrestricted filesystem access. Every
 * capability it exercises goes through this registry: allowlisted names only,
 * project-scoped, each classified read/write, some requiring operator
 * approval. Project content can never expand tool permissions (prompt
 * injection defense — the registry is code, not text).
 */
class CopilotToolRegistry
{
    /** mode → tool names the mode may use. */
    public const MODE_TOOLS = [
        'advisor' => [
            'read_analysis', 'read_schema', 'read_policy', 'read_function',
            'read_client_file', 'search_client_calls', 'read_api_catalog',
        ],
        'builder' => [
            'read_analysis', 'read_schema', 'read_policy', 'read_function',
            'read_client_file', 'search_client_calls', 'read_api_catalog',
            'generate_patch', 'apply_patch_to_sandbox',
        ],
        'validator' => [
            'read_analysis', 'read_validation_result', 'run_allowed_tests',
            'run_rehearsal', 'read_client_file',
        ],
    ];

    /** Definition: [read_only, requires_approval, description]. */
    public const TOOLS = [
        'read_analysis' => [true, false, 'Read the migration analysis inventory for this project'],
        'read_schema' => [true, false, 'Read table/schema fragments from the analysis'],
        'read_policy' => [true, false, 'Read RLS policy inventory'],
        'read_function' => [true, false, 'Read DB function/RPC inventory'],
        'read_client_file' => [true, false, 'Read one file inside the approved client repository root (redacted)'],
        'search_client_calls' => [true, false, 'Search the client callsite manifest'],
        'read_api_catalog' => [true, false, 'Read the platform API catalog for mapping targets'],
        'generate_patch' => [false, false, 'Stage proposed file patches into the isolated AI workspace (review required before apply)'],
        'apply_patch_to_sandbox' => [false, true, 'Apply approved patches to the approved working tree'],
        'run_allowed_tests' => [false, true, 'Run an allowlisted test command in the approved root'],
        'run_rehearsal' => [false, true, 'Run a migration rehearsal through the Phase 24 engine'],
        'read_validation_result' => [true, false, 'Read structured validation/test results'],
    ];

    /** Permanently forbidden capabilities — never callable regardless of input. */
    public const FORBIDDEN = [
        'shell', 'exec', 'bash', 'command', 'delete_file', 'rm', 'write_file_outside_patch',
        'install_package', 'deploy_production', 'mutate_dns', 'write_production_db',
        'supabase_write', 'reveal_secret', 'browse_filesystem', 'read_env_secrets',
        'git_push', 'git_reset_hard', 'git_clean',
    ];

    /** Execute a tool dispatch. Unknown/forbidden → 422. Ledger appended. */
    public static function dispatch(\App\Models\CopilotRun $run, string $tool, array $args = [], ?callable $handler = null): array
    {
        // 1. Forbidden names are rejected structurally.
        abort_if(in_array(strtolower($tool), self::FORBIDDEN, true), 422, "Tool '{$tool}' is forbidden for AI use.");
        // 2. Only allowlisted, mode-permitted tools run.
        abort_if(! isset(self::TOOLS[$tool]), 422, "Unknown tool '{$tool}'.");
        $modeTools = self::MODE_TOOLS[$run->mode] ?? [];
        abort_if(! in_array($tool, $modeTools, true), 422, "Tool '{$tool}' is not permitted in {$run->mode} mode.");

        // 3. Approval-gated tools require an explicit operator approval flag.
        [, $requiresApproval] = self::TOOLS[$tool];
        if ($requiresApproval) {
            abort_if(($args['approved'] ?? false) !== true, 422, "Tool '{$tool}' requires explicit operator approval.");
        }

        // 4. Tool-call budget (replay/bombing guard).
        $ledger = $run->tool_calls ?? [];
        abort_if(count($ledger) >= 50, 422, 'Tool-call budget exhausted for this run.');

        // 5. Execute with project-scoped handler; record outcome (no secrets).
        $outcome = $handler !== null ? $handler($args) : ['ok' => false, 'detail' => 'no handler bound'];
        $ledger[] = [
            'tool' => $tool, 'args_hash' => hash('sha256', json_encode($args)),
            'ok' => (bool) ($outcome['ok'] ?? false), 'at' => now()->toIso8601String(),
        ];
        $run->update(['tool_calls' => $ledger]);

        return $outcome;
    }
}
