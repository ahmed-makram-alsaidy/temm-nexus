<?php

namespace App\Services\Ai;

use App\Models\AiUsageRecord;
use Illuminate\Support\Facades\Log;

/**
 * 0.4.0 §17 — execution-time authorisation for every AI tool call.
 *
 * ORDER OF CHECKS (all mandatory, all re-evaluated per call):
 *   1. the tool exists in the allowlist           — no dynamic tool names
 *   2. the tool's scope is admitted by the context — no cross-branch reads
 *   3. the context actually carries the object it claims
 *   4. the ACTING USER holds the required capability ON THAT OBJECT, now
 *   5. arguments match the declared parameter list — no extra keys
 *   6. the result contains no secret-shaped keys  — belt and braces
 *
 * Step 4 is the important one. It is not checked when the conversation starts
 * and cached: `Access` re-reads membership on every call, so revoking a user's
 * role takes effect on their very next tool call, including mid-conversation.
 *
 * A refusal is recorded, never silently swallowed, and never returned to the
 * model as if it were data.
 */
final class ToolDispatcher
{
    public function __construct(
        private readonly AiContext $context,
    ) {}

    /** @return list<string> */
    public function availableTools(): array
    {
        return ToolRegistry::availableFor($this->context);
    }

    /**
     * Authorise WITHOUT executing. Used by the UI to decide what to offer, and
     * by tests to assert the boundary directly.
     *
     * @throws AiToolDenied
     */
    public function authorize(string $tool, array $arguments = []): array
    {
        $definition = ToolRegistry::definition($tool);

        // 1 — allowlisted only.
        if ($definition === null) {
            throw AiToolDenied::unknownTool($tool);
        }

        /** @var Scope $toolScope */
        $toolScope = $definition['scope'];

        // 2 — scope admitted by this context.
        if (! $this->context->scope->admits($toolScope)) {
            throw AiToolDenied::wrongScope($tool, $this->context->scope, $toolScope);
        }

        // 3 — the object the scope promises must actually be present.
        if ($toolScope === Scope::PROJECT && $this->context->projectTarget() === null) {
            throw AiToolDenied::missingContext($tool, Scope::PROJECT);
        }
        if ($toolScope === Scope::WORKSPACE && $this->context->workspace === null) {
            throw AiToolDenied::missingContext($tool, Scope::WORKSPACE);
        }

        // 4 — capability, AT EXECUTION TIME, on the object in context.
        if (! $this->context->allows($definition['capability'])) {
            throw AiToolDenied::missingCapability($tool, $definition['capability']);
        }

        // 5 — strict arguments: no undeclared keys, required ones present.
        foreach ($arguments as $name => $_) {
            if ($name === 'approved') {
                continue; // reserved for the action layer (Phase J)
            }
            if (! isset($definition['parameters'][$name])) {
                throw AiToolDenied::badArgument($tool, (string) $name);
            }
        }
        foreach ($definition['parameters'] as $name => $spec) {
            if (($spec['required'] ?? false) && ! array_key_exists($name, $arguments)) {
                throw AiToolDenied::badArgument($tool, $name);
            }
        }

        return $definition;
    }

    public function canRun(string $tool, array $arguments = []): bool
    {
        try {
            $this->authorize($tool, $arguments);

            return true;
        } catch (AiToolDenied) {
            return false;
        }
    }

    /**
     * Authorise, execute, then validate the result before it can reach a model.
     *
     * The handler receives `(arguments, context)`. It must return an array. It
     * must not perform writes: this registry is READ-ONLY by construction, and
     * `read_only` is asserted before the handler runs so a future registry edit
     * cannot quietly introduce a mutating tool here.
     *
     * @param  callable(array, AiContext): array  $handler
     * @return array{ok: bool, tool: string, label: string, data: array, denied: ?string}
     */
    public function dispatch(string $tool, array $arguments, callable $handler): array
    {
        try {
            $definition = $this->authorize($tool, $arguments);
        } catch (AiToolDenied $denied) {
            $this->recordDenial($denied);

            return [
                'ok' => false,
                'tool' => $tool,
                'label' => ToolRegistry::label($tool),
                'data' => [],
                'denied' => $denied->reason,
            ];
        }

        if (($definition['read_only'] ?? false) !== true) {
            // Defence in depth: this dispatcher is for reads only. Mutations
            // must go through the approval flow (Phase J), never here.
            $this->recordDenial(AiToolDenied::missingCapability($tool, 'read_only'));

            return [
                'ok' => false,
                'tool' => $tool,
                'label' => ToolRegistry::label($tool),
                'data' => [],
                'denied' => 'not_read_only',
            ];
        }

        $data = $handler($arguments, $this->context);
        $data = is_array($data) ? $data : [];

        $unsafe = $this->findUnsafeKey($data);
        if ($unsafe !== null) {
            $this->recordDenial(AiToolDenied::unsafeResult($tool, $unsafe));

            return [
                'ok' => false,
                'tool' => $tool,
                'label' => ToolRegistry::label($tool),
                'data' => [],
                'denied' => 'unsafe_result',
            ];
        }

        $this->recordUsage($tool);

        return [
            'ok' => true,
            'tool' => $tool,
            'label' => ToolRegistry::label($tool),
            'data' => $data,
            'denied' => null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Search the result for secret-shaped keys, at the top level and one level
     * down (the shapes our tools actually produce).
     */
    private function findUnsafeKey(array $data, int $depth = 0): ?string
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), ToolRegistry::FORBIDDEN_RESULT_KEYS, true)) {
                return $key;
            }

            if ($depth < 1 && is_array($value)) {
                $nested = $this->findUnsafeKey($value, $depth + 1);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function recordDenial(AiToolDenied $denied): void
    {
        // Reason codes only — never arguments, never the caller's content.
        Log::warning('AI tool denied', [
            'tool' => $denied->tool,
            'reason' => $denied->reason,
            'scope' => $this->context->scope->value,
            'user_id' => $this->context->access->user()->getKey(),
            'project_id' => $this->context->project?->getKey(),
            'workspace_id' => $this->context->workspace?->getKey(),
        ]);
    }

    private function recordUsage(string $tool): void
    {
        try {
            AiUsageRecord::query()->create([
                // Tools cost no model tokens; recording them keeps a complete
                // ledger of AI activity for the audit surface (§30).
                'project_id' => $this->context->project?->getKey(),
                'profile' => 'tool:'.$tool,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'estimated_cost' => 0,
                'currency' => 'USD',
            ]);
        } catch (\Throwable) {
            // Usage accounting must never break a read.
        }
    }
}
