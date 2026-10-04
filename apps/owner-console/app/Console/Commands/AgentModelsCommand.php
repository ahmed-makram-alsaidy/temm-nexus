<?php

namespace App\Console\Commands;

use App\Models\AgentRuntime;
use App\Services\Agent\AgentRuntimeManager;
use App\Services\Agent\Contract\AgentRuntimeException;
use Illuminate\Console\Command;

/**
 * Phase 43 — list models a runtime reports (verbatim ids, no pricing claims).
 */
class AgentModelsCommand extends Command
{
    protected $signature = 'agent:models
        {runtime? : Runtime display name or id (defaults to the only enabled runtime)}
        {--filter= : Substring filter on the canonical provider/model id}';

    protected $description = 'List models reported by an agent runtime';

    public function handle(AgentRuntimeManager $manager): int
    {
        $runtime = $this->resolveRuntime($this->argument('runtime'));

        if ($runtime === null) {
            $this->error('No matching enabled runtime found.');

            return self::FAILURE;
        }

        try {
            $models = $manager->forRuntime($runtime)->models($runtime);
        } catch (AgentRuntimeException $e) {
            $this->error($e->category.': '.$e->getMessage());

            return self::FAILURE;
        }

        $filter = mb_strtolower((string) $this->option('filter'));
        $rows = [];
        foreach ($models as $model) {
            $canonical = $model->canonicalId();
            if ($filter !== '' && ! str_contains(mb_strtolower($canonical), $filter)) {
                continue;
            }
            $rows[] = [$canonical, $model->name ?? '', $model->contextLimit !== null ? number_format($model->contextLimit) : '—'];
        }

        if ($rows === []) {
            $this->warn('No models matched.');

            return self::SUCCESS;
        }

        $this->table(['provider/model', 'Name', 'Context'], $rows);

        return self::SUCCESS;
    }

    protected function resolveRuntime(?string $key): ?AgentRuntime
    {
        $query = AgentRuntime::where('enabled', true);

        if ($key === null || $key === '') {
            return $query->orderByDesc('created_at')->first();
        }

        // Same PostgreSQL uuid-guard as AgentTask::findByCodeOrId.
        $isUuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key);

        return $query->where(fn ($q) => $q->where('display_name', $key)->when($isUuid, fn ($qq) => $qq->orWhere('id', $key)))->first();
    }
}
