<?php

namespace App\Services\ControlPlane;

use App\Models\Project;

/**
 * Known scheduled tasks for a project.
 * Source 1 (always): routes/console.php parsed for Schedule:: references
 * (command/job class + frequency literal) — clearly labeled as static analysis.
 * Source 2: runtime heartbeat rows the demo scheduler writes (last run proof).
 */
class SchedulerInfo
{
    public function __construct(protected Project $project) {}

    public static function for(Project $project): self
    {
        return new self($project);
    }

    /** @return list<array{task:string,schedule:string,source:string}> */
    public function definedTasks(): array
    {
        $file = ControlPlanePaths::projectDir($this->project->slug).'/routes/console.php';
        if (! is_file($file)) {
            return [];
        }
        $tasks = [];
        $src = file_get_contents($file);
        foreach (explode("\n", $src) as $line) {
            if (str_contains($line, 'Schedule::') && ! str_starts_with(ltrim($line), '//') && ! str_starts_with(ltrim($line), '*')) {
                $tasks[] = [
                    'task' => trim($line),
                    'schedule' => self::guessFrequency($line),
                    'source' => 'routes/console.php (static)',
                ];
            }
        }

        return $tasks;
    }

    /** @return list<array{key:string,last_run:?string,runs:int}> from project cache table */
    public function runHistory(): array
    {
        try {
            if (! ProjectConnectionManager::ping($this->project)) {
                return [];
            }
            $conn = ProjectConnectionManager::connection($this->project);
            $qb = \Illuminate\Support\Facades\DB::connection($conn)->table('cache')
                ->where('key', 'like', 'schedule-heartbeat:%');
            if (! $qb->exists()) {
                // Fallback: dedicated table used by the demo scheduler.
                if (\Illuminate\Support\Facades\DB::connection($conn)->getSchemaBuilder()->hasTable('scheduler_heartbeats')) {
                    return \Illuminate\Support\Facades\DB::connection($conn)->table('scheduler_heartbeats')
                        ->select(['name as key', 'last_run_at as last_run', 'run_count as runs'])
                        ->orderBy('name')->get()->map(fn ($r) => (array) $r)->all();
                }

                return [];
            }

            return $qb->select(['key'])->get()->map(fn ($r) => [
                'key' => $r->key, 'last_run' => null, 'runs' => 1,
            ])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    protected static function guessFrequency(string $line): string
    {
        foreach (['everySecond', 'everyMinute', 'everyTwoMinutes', 'everyFiveMinutes', 'hourly', 'daily', 'dailyAt', 'weekly', 'monthly', 'cron'] as $freq) {
            if (str_contains($line, '->'.$freq)) {
                return $freq;
            }
        }

        return 'see definition';
    }

    /** Owner console's own scheduled tasks (for the Infrastructure view). */
    public static function consoleTasks(): array
    {
        $tasks = [];
        $file = base_path('routes/console.php');
        if (is_file($file)) {
            foreach (explode("\n", file_get_contents($file)) as $line) {
                if (str_contains($line, 'Schedule::') && ! str_starts_with(ltrim($line), '//')) {
                    $tasks[] = ['task' => trim($line), 'schedule' => self::guessFrequency($line), 'source' => 'owner routes/console.php (static)'];
                }
            }
        }

        return $tasks;
    }
}
