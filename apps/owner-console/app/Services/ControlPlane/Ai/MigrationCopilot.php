<?php

namespace App\Services\ControlPlane\Ai;

use App\Models\ClientRepository;
use App\Models\CopilotRun;
use App\Models\MigrationAnalysis;
use App\Models\Project;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\ClientSetupService;
use App\Services\ControlPlane\Repository\ClientDependencyScanner;
use Illuminate\Support\Str;

/**
 * Phase 25G — project-scoped AI Migration Copilot.
 *
 * Consumes structured platform artifacts (never raw system access) through
 * scoped context packs; produces structured recommendations that remain
 * ADVISORY until an operator accepts them. Three modes:
 *   ADVISOR  — read-only analysis/explanation/classification;
 *   BUILDER  — generates patches into the isolated workspace only;
 *   VALIDATOR— runs allowlisted tests/rehearsals, recommends repairs.
 */
class MigrationCopilot
{
    public const CLASSIFICATIONS = [
        'KEEP_POSTGRESQL', 'LARAVEL_SERVICE', 'LARAVEL_API', 'SERVER_FUNCTION',
        'QUEUE_JOB', 'SCHEDULER', 'WEBHOOK', 'EXTERNAL_INTEGRATION', 'NEEDS_REVIEW',
    ];

    public const ACTIONS = [
        'explain_blockers' => ['mode' => 'advisor', 'profile' => 'planner'],
        'propose_order' => ['mode' => 'advisor', 'profile' => 'planner'],
        'analyze_rls' => ['mode' => 'advisor', 'profile' => 'planner'],
        'classify_rpc' => ['mode' => 'advisor', 'profile' => 'planner'],
        'classify_edge' => ['mode' => 'advisor', 'profile' => 'planner'],
        'map_client_calls' => ['mode' => 'advisor', 'profile' => 'planner'],
        // Phase 28J — GENERIC migration-mapping recommendation over any
        // source connector's normalized analysis (no provider branching;
        // connector artifacts supply provider context). Recommendation-only.
        'recommend_mapping' => ['mode' => 'advisor', 'profile' => 'planner'],
        'generate_patch' => ['mode' => 'builder', 'profile' => 'builder'],
        'fix_failures' => ['mode' => 'builder', 'profile' => 'builder'],
        'validate_patch' => ['mode' => 'validator', 'profile' => 'validator'],
    ];

    protected AiGateway $gateway;

    public function __construct(?AiGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new AiGateway;
    }

    /**
     * Execute a Copilot action. Returns the persisted CopilotRun.
     * $options['fake_responses'/'fake_error'] are passed through to the
     * FakeAiDriver by the automated suite only.
     */
    public function run(Project $project, string $action, array $context = [], array $options = []): CopilotRun
    {
        $spec = self::ACTIONS[$action] ?? null;
        abort_if($spec === null, 422, "Unknown Copilot action '{$action}'");
        abort_if(! \App\Services\ControlPlane\CpAccess::allows(auth()->user(), 'copilot.run'), 403, 'Missing copilot.run permission.');

        $mode = $spec['mode'];
        $resolved = $this->gateway->profile($project, $spec['profile']);

        $run = CopilotRun::create([
            'project_id' => $project->id,
            'migration_analysis_id' => $context['analysis']->id ?? null,
            'client_repository_id' => $context['repository']->id ?? null,
            'run_id' => (string) Str::uuid(),
            'mode' => $mode,
            'action' => $action,
            'provider' => $resolved['config']->provider,
            'model' => $resolved['model'],
            'status' => 'running',
            'input_refs' => $this->inputRefs($context),
            'created_by' => auth()->id(),
        ]);

        try {
            [$messages, $schema] = $this->buildPrompt($project, $action, $context);
            $response = $this->gateway->complete($project, $resolved, $messages, $options);
            $result = $this->parseStructured($response['text'], $schema);

            // Builder actions stage patches through the guarded workspace.
            if ($mode === 'builder' && $action === 'generate_patch') {
                $result = $this->stagePatches($project, $run, $context, $result);
            }

            $run->update(['status' => 'completed', 'result' => $result]);
            AdminAudit::record('COPILOT_RUN', $project, 'copilot_run', $run->id, [
                'action' => $action, 'mode' => $mode, 'provider' => $run->provider,
            ]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'result' => ['error' => Str::limit($e->getMessage(), 300)]]);
        }

        return $run->fresh();
    }

    /** Structured prompt per action: system preamble + scoped pack + JSON schema. */
    protected function buildPrompt(Project $project, string $action, array $context): array
    {
        $analysis = $context['analysis'] ?? null;
        $repo = $context['repository'] ?? null;
        $pack = '';

        switch ($action) {
            case 'explain_blockers':
            case 'propose_order':
                $pack = $analysis ? AiContextBuilder::analysisPack($analysis, 'overview') : 'No analysis available.';
                $schema = ['summary' => 'string', 'blockers' => ['string'], 'recommended_order' => ['string'], 'risks' => ['string']];
                $instruction = 'Identify migration blockers and recommend a dependency-ordered migration plan.';
                break;

            case 'analyze_rls':
                $pack = $analysis ? AiContextBuilder::analysisPack($analysis, 'policy', 80) : 'No analysis available.';
                $schema = ['policies' => [['policy' => 'string', 'table' => 'string', 'candidate' => 'string', 'allow_test' => 'string', 'deny_test' => 'string', 'risk' => 'string']]];
                $instruction = 'Translate each RLS policy into a candidate Laravel authorization mapping with ALLOW/DENY tests. Never auto-apply security conversions — output candidates only.';
                break;

            case 'recommend_mapping':
                // 28J — structural metadata only: field paths, types, counts,
                // relationship candidates. NO raw documents, NO values (28J.1).
                $pack = $analysis ? AiContextBuilder::analysisPack($analysis, 'mapping', 100) : 'No analysis available.';
                $schema = ['mappings' => [['collection' => 'string', 'strategy' => 'string', 'confidence' => 'string', 'reason' => 'string', 'index_mapping' => 'string', 'relationship_mapping' => 'string', 'risks' => ['string']]], 'summary' => 'string'];
                $instruction = 'Recommend a PostgreSQL migration strategy (RELATIONAL_TABLE, JSONB_DOCUMENT, HYBRID, ARRAY_CHILD_TABLE, SKIP or NEEDS_REVIEW) per source collection using ONLY the provided structural metadata. Recommendations are advisory — an operator approves every mapping. Index candidates must note where a native equivalent does not exist (TTL, geospatial).';
                break;

            case 'classify_rpc':
                $pack = $analysis ? AiContextBuilder::analysisPack($analysis, 'function', 80) : 'No analysis available.';
                $schema = ['functions' => [['name' => 'string', 'classification' => 'string', 'reason' => 'string']]];
                $instruction = 'Classify each DB function/RPC into exactly one of: '.implode(', ', self::CLASSIFICATIONS).'.';
                break;

            case 'classify_edge':
                $pack = $analysis ? AiContextBuilder::analysisPack($analysis, 'edge', 40) : 'No analysis available.';
                $schema = ['functions' => [['name' => 'string', 'classification' => 'string', 'target' => 'string', 'reason' => 'string']]];
                $instruction = 'Classify each edge function (internal_logic, integration, webhook, scheduled_job, notification, obsolete) and recommend the platform target.';
                break;

            case 'map_client_calls':
                abort_if(! $repo instanceof ClientRepository, 422, 'Client repository required for client mapping.');
                $config = ClientSetupService::configFor($project);
                $catalog = [
                    'sdk' => ['auth.login', 'auth.me', 'auth.logout', 'storage.upload', 'storage.signedUrl', 'functions.invoke', 'realtime.subscribe'],
                    'api_base' => $config['api_url'], 'functions_base' => $config['functions_base_url'],
                ];
                $pack = AiContextBuilder::callsitePack($repo)."\n".json_encode($catalog);
                // Phase 27I.3 — provider-neutral schema/instruction: the AI
                // core never references a specific migration source provider.
                $schema = ['mappings' => [['file' => 'string', 'line' => 'integer', 'provider_call' => 'string', 'platform_target' => 'string', 'gap' => 'boolean', 'note' => 'string']]];
                $instruction = 'Map each source-provider client call to the platform SDK/API target using ONLY the provided catalog. If no target exists, set gap=true — never invent endpoints.';
                break;

            case 'generate_patch':
                abort_if(! $repo instanceof ClientRepository, 422, 'Client repository required for patch generation.');
                $pack = AiContextBuilder::callsitePack($repo, 120)
                    ."\n".(! empty($context['focus_files']) ? implode("\n", array_map(fn ($f) => AiContextBuilder::clientFile($repo, $f, 4000), array_slice((array) $context['focus_files'], 0, 3))) : '');
                $schema = ['patches' => [['path' => 'string', 'action' => 'string', 'reason' => 'string', 'risk' => 'string', 'content' => 'string', 'diff' => 'string', 'tests' => 'string']]];
                $instruction = 'Propose file patches (Laravel code, mapping manifests, client data-source swaps) relative to the approved root. action=create requires full content; action=modify requires a unified diff. Paths must stay inside the approved root.';
                break;

            case 'fix_failures':
                $pack = "Structured failures:\n".json_encode($context['failures'] ?? [], JSON_UNESCAPED_UNICODE);
                if ($repo instanceof ClientRepository) {
                    $pack .= "\n".AiContextBuilder::callsitePack($repo, 80);
                }
                $schema = ['patches' => [['path' => 'string', 'action' => 'string', 'reason' => 'string', 'risk' => 'string', 'content' => 'string', 'diff' => 'string', 'tests' => 'string']], 'analysis' => 'string'];
                $instruction = 'Propose minimal repair patches for the failing tests. Only files inside the approved root.';
                break;

            case 'validate_patch':
                $pack = 'Validation context: '.json_encode($context['validation'] ?? [], JSON_UNESCAPED_UNICODE);
                $schema = ['verdict' => 'string', 'issues' => ['string'], 'recommended_repairs' => ['string']];
                $instruction = 'Assess the validation results and recommend repairs. You cannot mutate any source.';
                break;

            default:
                abort(422, 'Unhandled action');
        }

        $system = AiContextBuilder::SYSTEM_PREAMBLE."\n".$instruction."\nRespond with STRICT JSON matching this schema (no prose): ".json_encode($schema);

        return [
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => "Project: {$project->name}\n\n{$pack}"],
            ],
            $schema,
        ];
    }

    /** Defensive JSON extraction from the model output. */
    protected function parseStructured(string $text, array $schema): array
    {
        $decoded = json_decode(trim($text), true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // Tolerate fenced/prose-wrapped JSON: take the outermost {...}.
        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return ['raw' => Str::limit($text, 4000), 'parse_error' => true];
    }

    /** Stage builder output as a reviewable patch run (never applied directly). */
    protected function stagePatches(Project $project, CopilotRun $run, array $context, array $result): array
    {
        $patches = $result['patches'] ?? [];
        if ($patches === []) {
            return $result + ['patch_run' => null, 'note' => 'no patches proposed'];
        }
        $repo = $context['repository'] ?? null;
        $targetRoot = $repo?->root_path;
        abort_if($targetRoot === null || ! is_dir($targetRoot), 422, 'Approved client repository root required as patch target.');
        $patchRun = PatchWorkspace::create($project, $run, $repo, $patches, $targetRoot);

        return $result + ['patch_run_id' => $patchRun->id, 'patch_status' => 'proposed (awaiting review)'];
    }

    /** Artifact references only — raw content never stored in the run. */
    protected function inputRefs(array $context): array
    {
        return [
            'analysis_id' => $context['analysis']->id ?? null,
            'analysis_run_id' => $context['analysis']->run_id ?? null,
            'repository_id' => $context['repository']->id ?? null,
            'plan_id' => $context['plan']->id ?? null,
            'failures_digest' => isset($context['failures']) ? hash('sha256', json_encode($context['failures'])) : null,
        ];
    }

    /**
     * 25G.8 — client mapping with API GAP detection (deterministic helper
     * used for verification regardless of AI output).
     */
    public static function mapCallsiteToPlatform(array $callsite): array
    {
        $targetByCategory = [
            'auth' => 'backend.auth.login / auth.me (SDK)',
            'database' => 'backend.http (platform REST /api/v1) or functions.invoke for domain logic',
            'rpc' => 'backend.functions.invoke (if an equivalent server function exists)',
            'functions' => 'backend.functions.invoke (requires re-implemented function)',
            'storage' => 'backend.storage.upload / signedUrl',
            'realtime' => 'backend.realtime.subscribe (Reverb channel)',
        ];
        $target = $targetByCategory[$callsite['category']] ?? null;
        $gap = $target === null || ($callsite['category'] === 'rpc' && ($callsite['target'] ?? null) === null);

        return ['callsite' => $callsite, 'platform_target' => $target, 'gap' => $gap];
    }
}
