<?php

namespace App\Console\Commands;

use App\Models\AgentTask;
use App\Services\Access\Access;
use App\Services\Access\Capability;
use Illuminate\Console\Command;

/**
 * Phase 43 — show task state: status, recent activity, commands, changeset
 * summary, approval and verification results.
 */
class AgentShowCommand extends Command
{
    protected $signature = 'agent:show
        {code : Task code (AGT-XXXX) or UUID}
        {--events=20 : Number of recent activity entries}
        {--user= : Restrict to this user\'s access boundary (optional for read-only diagnostics)}';

    protected $description = 'Show a Developer Agent task';

    public function handle(): int
    {
        $task = AgentTask::where('code', $this->argument('code'))
            ->orWhere('id', $this->argument('code'))
            ->first();

        if ($task === null) {
            $this->error('Task not found.');

            return self::FAILURE;
        }

        // Read access follows the same boundary as the UI when a user is
        // supplied; without one this is a server-side operator diagnostic.
        $userEmail = (string) $this->option('user');
        if ($userEmail !== '') {
            $user = \App\Models\User::where('email', $userEmail)->first();
            if ($user === null) {
                $this->error('User not found.');

                return self::FAILURE;
            }
            if (! Access::for($user)->allows(Capability::AGENTS_VIEW, 'project', $task->workspace_id, $task->project_id)) {
                $this->error('You do not have access to this project.');

                return self::FAILURE;
            }
        }

        $this->line('<fg=cyan>'.$task->code.'</> — '.$task->title);
        $this->table([], [
            ['Status', $task->status],
            ['Model', $task->model ?? 'runtime default'],
            ['Runtime', $task->runtime?->display_name ?? '—'],
            ['Base revision', \Illuminate\Support\Str::limit((string) $task->base_revision, 12, '')],
            ['Session', $task->runtime_session_id ?? '—'],
            ['Attempts', $task->attempts],
            ['Error', $task->error_category ? $task->error_category.': '.$task->error_message : '—'],
            ['Created', $task->created_at?->format('Y-m-d H:i:s')],
            ['Finished', $task->finished_at?->format('Y-m-d H:i:s') ?? '—'],
        ]);

        $changeset = $task->changesets()->latest('created_at')->first();
        if ($changeset !== null) {
            $this->line('Changeset: +'.$changeset->files_added.'/~'.$changeset->files_modified.'/−'.$changeset->files_deleted
                .' (+'.$changeset->additions.'/−'.$changeset->deletions.') fingerprint='.substr($changeset->fingerprint, 0, 12)
                .($changeset->truncated ? ' [TRUNCATED]' : ''));
        }

        $verification = $task->verifications()->latest('created_at')->first();
        if ($verification !== null) {
            $this->line('Verification: '.$verification->status);
            foreach ($verification->commands ?? [] as $command) {
                $this->line('  $ '.$command['command'].' → exit '.($command['exit_code'] ?? '—').' ('.($command['duration_ms'] ?? '—').'ms)');
            }
        }

        $events = $task->events()->orderByDesc('seq')->limit((int) $this->option('events'))->get()->reverse();
        if ($events->isNotEmpty()) {
            $this->newLine();
            $this->line('<fg=cyan>Activity</>');
            foreach ($events as $event) {
                $this->line(sprintf('  [%s] %-12s %s', str_pad((string) $event->seq, 3), $event->type, \Illuminate\Support\Str::limit((string) $event->summary, 140)));
            }
        }

        return self::SUCCESS;
    }
}
