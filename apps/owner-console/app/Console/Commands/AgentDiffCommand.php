<?php

namespace App\Console\Commands;

use App\Models\AgentTask;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Phase 43 — show the deterministic changeset diff of a task (read-only).
 */
class AgentDiffCommand extends Command
{
    protected $signature = 'agent:diff
        {code : Task code (AGT-XXXX) or UUID}
        {--raw : Print only the raw diff body}';

    protected $description = 'Show the changeset diff of a Developer Agent task';

    public function handle(): int
    {
        $task = AgentTask::findByCodeOrId((string) $this->argument('code'));

        if ($task === null) {
            $this->error('Task not found.');

            return self::FAILURE;
        }

        $changeset = $task->changesets()->latest('created_at')->first();

        if ($changeset === null) {
            $this->warn('No changeset yet — the task has not produced a diff.');

            return self::FAILURE;
        }

        if ($this->option('raw')) {
            $this->line($changeset->diff);

            return self::SUCCESS;
        }

        $this->line('<fg=cyan>'.$task->code.'</> base='.Str::limit((string) $changeset->base_revision, 12, '')
            .' fingerprint='.substr($changeset->fingerprint, 0, 12)
            .' +'.$changeset->files_added.'/~'.$changeset->files_modified.'/−'.$changeset->files_deleted
            .' (+'.$changeset->additions.'/−'.$changeset->deletions.')'
            .($changeset->truncated ? ' <fg=red>[TRUNCATED — manual review required]</>' : ''));
        $this->newLine();
        $this->line($changeset->diff);

        return self::SUCCESS;
    }
}
