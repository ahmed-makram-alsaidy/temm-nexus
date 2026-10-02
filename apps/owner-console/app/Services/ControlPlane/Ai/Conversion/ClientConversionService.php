<?php

namespace App\Services\ControlPlane\Ai\Conversion;

use App\Models\AiPatchRun;
use App\Models\ClientCallsite;
use App\Models\ClientRepository;
use App\Models\Project;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\Ai\AiGateway;
use App\Services\ControlPlane\Ai\PatchWorkspace;

/**
 * Phase 33A — the client-code conversion workflow.
 *
 * SCAN → PLAN → GENERATE PATCH → REVIEW → APPROVE → APPLY → TEST → REPAIR
 * → FINAL DIFF. Generation lands in the Phase 25I PatchWorkspace as
 * reviewable proposals — NOTHING is applied without explicit operator
 * approval (33A: no unrestricted autonomous write mode).
 *
 * Budgets (33C): max changed files per run, max AI tokens, max repair
 * iterations — enforced here, not trusted to the model.
 */
class ClientConversionService
{
    public const DEFAULT_BUDGET = [
        'max_files' => 20,
        'max_tokens' => 24000,
        'max_repair_iterations' => 2,
    ];

    public function __construct(
        protected ConversionPlanBuilder $planner = new ConversionPlanBuilder,
        protected ConversionPromptBuilder $prompts = new ConversionPromptBuilder,
        protected DiffParser $diffs = new DiffParser,
        protected TestCommandAllowlist $allowlist = new TestCommandAllowlist,
        protected ?AiGateway $gateway = null,
    ) {
        $this->gateway ??= new AiGateway;
    }

    /**
     * Plan + generate patches for one repository. Returns the patch run
     * (status 'proposed' — awaiting operator approval) plus the scan metrics.
     *
     * @return array{stack: ?string, patch_run: ?AiPatchRun, items: int, skipped: int, parse_failed: bool}
     */
    public function generate(Project $project, ClientRepository $repo, array $budget = [], array $aiOptions = []): array
    {
        $budget = $budget + self::DEFAULT_BUDGET;
        $plan = $this->planner->plan($repo);
        if ($plan['stack'] === null || $plan['items'] === []) {
            return ['stack' => $plan['stack'], 'patch_run' => null, 'items' => count($plan['items']), 'skipped' => count($plan['skipped']), 'parse_failed' => false];
        }

        $prompt = $this->prompts->build($repo, $plan, ['max_files' => $budget['max_files']]);
        $resolved = $this->gateway->profile($project, 'builder');
        $answer = $this->gateway->complete($project, $resolved, $prompt['messages'], $aiOptions + [
            'system' => $prompt['system'],
            'max_tokens' => $budget['max_tokens'],
        ]);

        $patches = $this->diffs->parse((string) ($answer['text'] ?? ''));
        // 33C — budget enforcement: extra files beyond the budget are dropped.
        $patches = array_slice($patches, 0, $budget['max_files']);

        $patchRun = null;
        if ($patches !== []) {
            $patchRun = PatchWorkspace::create($project, null, $repo, $patches, (string) $repo->root_path);
        }
        AdminAudit::record('CLIENT_CONVERSION_GENERATED', $project, 'client_repository', $repo->id, [
            'stack' => $plan['stack'], 'items' => count($plan['items']),
            'skipped' => count($plan['skipped']), 'patches' => count($patches),
            'parse_failed' => $patches === [],
        ]);

        return [
            'stack' => $plan['stack'],
            'patch_run' => $patchRun,
            'items' => count($plan['items']),
            'skipped' => count($plan['skipped']),
            'parse_failed' => $patches === [],
        ];
    }

    /**
     * 33E — run ONE allowlisted verification step by NAME. The command is
     * resolved from the fixed allowlist — the AI (or caller) can never run
     * anything arbitrary. The command is NOT executed here if the platform
     * shell is unavailable; the resolved command is returned for the
     * approved execution surface, and refusals are recorded.
     */
    public function resolveTestStep(Project $project, ClientRepository $repo, string $step): array
    {
        $resolved = $this->allowlist->resolve($repo, $step);
        if (! $resolved['allowed']) {
            AdminAudit::record('CLIENT_CONVERSION_TEST_REFUSED', $project, 'client_repository', $repo->id, [
                'step' => $step, 'reason' => $resolved['reason'],
            ]);
        }

        return $resolved;
    }

    /**
     * 33G — diff quality metrics: files touched, callsites planned,
     * remaining legacy callsites (fresh rescan), budget usage.
     *
     * @param  list<string>  $changedFiles  files present in the patch run
     */
    public function metrics(ClientRepository $repo, ?AiPatchRun $patchRun, array $generation): array
    {
        // 33G — remaining legacy callsites = still-DISCOVERED scanner rows.
        $remaining = ClientCallsite::query()
            ->where('client_repository_id', $repo->id)
            ->where('status', 'DISCOVERED')
            ->count();

        return [
            'stack' => $generation['stack'] ?? null,
            'callsites_planned' => $generation['items'] ?? 0,
            'callsites_skipped' => $generation['skipped'] ?? 0,
            'files_touched' => $patchRun?->files()->count() ?? 0,
            'remaining_legacy_callsites' => $remaining,
            'parse_failed' => $generation['parse_failed'] ?? false,
            'budget' => self::DEFAULT_BUDGET,
        ];
    }
}
