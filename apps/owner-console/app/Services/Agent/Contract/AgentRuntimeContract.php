<?php

namespace App\Services\Agent\Contract;

use App\Models\AgentRuntime;

/**
 * The runtime abstraction every coding-agent adapter implements.
 *
 * Implementations NEVER touch TEMM storage or UI concerns: they translate the
 * runtime's official API into the normalized vocabulary above. Everything
 * downstream (tasks, workspaces, approvals, audit, diff, verification, UI,
 * CLI) is adapter-agnostic, so future runtimes (Codex CLI, Claude Code, ACP
 * agents) slot in without rewriting the product.
 */
interface AgentRuntimeContract
{
    /** Registry key — the ONLY place the driver name appears. */
    public function id(): string;

    /** Human display name. */
    public function label(): string;

    public function capabilities(AgentRuntime $runtime): AgentRuntimeCapabilities;

    /** Verify connectivity + version; classify every failure. */
    public function testConnection(AgentRuntime $runtime): AgentRuntimeConnection;

    /** @return list<AgentRuntimeModel> */
    public function models(AgentRuntime $runtime): array;

    /**
     * Create a session bound to an isolated workspace directory.
     *
     * @param  string  $directory  absolute path of the isolated workspace
     * @param  string|null  $model  canonical provider/model
     * @param  array<string, mixed>  $permission  runtime-native permission config from the execution policy
     * @param  string|null  $instructions  optional system-level instructions
     * @return string session id
     */
    public function startSession(AgentRuntime $runtime, string $directory, ?string $model, array $permission, ?string $instructions = null): string;

    /** Fire a task prompt without blocking on completion (progress arrives via events()). */
    public function sendPrompt(AgentRuntime $runtime, string $sessionId, string $prompt, ?string $model = null): void;

    /**
     * Stream normalized events for a task until the session goes idle or the
     * stream ends. Implementations must apply their own idle/total timeouts
     * and translate failures into AgentRuntimeException.
     *
     * @return \Generator<int, AgentRuntimeEvent>
     */
    public function events(AgentRuntime $runtime, string $sessionId, string $directory, int $idleTimeoutSeconds): \Generator;

    /**
     * Deterministic per-session file diff as reported by the runtime.
     *
     * @return list<array{path: string, status: string, additions: int, deletions: int, patch: string}>
     */
    public function diff(AgentRuntime $runtime, string $sessionId): array;

    public function abort(AgentRuntime $runtime, string $sessionId): void;
}
