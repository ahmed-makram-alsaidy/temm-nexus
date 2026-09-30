<?php

use App\Services\ControlPlane\TaskRunner;
use Illuminate\Support\Facades\Schedule;

// Template scheduler examples (enable per project needs):
// - framework housekeeping
Schedule::command('horizon:snapshot')->everyFiveMinutes();
// `pulse:check` without --once is a supervised daemon: it loops forever, so
// schedule:run — which executes events sequentially — blocks on it and every
// task registered after it never runs (18J lesson, docs/SCHEDULER.md). The
// --once form is a bounded snapshot that exits immediately, keeping the
// schedule loop free.
Schedule::command('pulse:check --once')->everyMinute();
// - product jobs live here, e.g.:
// Schedule::job(new \App\Jobs\NightlyReportJob)->dailyAt('02:00')->onOneServer();

// Phase 20O: project cron jobs due-check (DB-backed tasks, every minute).
Schedule::call(fn () => TaskRunner::runDue())->everyMinute()->name('cp-cron-due-sweep');

// Phase 20P: webhook retry sweep (due deliveries, every minute).
Schedule::call(fn () => \App\Services\ControlPlane\WebhookService::processRetries())->everyMinute()->name('cp-webhook-retries');
// Phase 21B: node stale sweep (heartbeat liveness → degraded/offline).
Schedule::call(fn () => \App\Services\ControlPlane\NodeHeartbeatService::markStale())->everyMinute()->name('cp-node-stale-sweep');
// Phase 24D: backup policy sweep — run policies whose cron schedule is due.
Schedule::call(function () {
    \App\Models\BackupPolicy::query()->where('status', 'active')
        ->whereNotNull('last_run_at')
        ->get()
        ->each(function ($policy) {
            $due = \App\Services\ControlPlane\CronService::nextRuns($policy->schedule, 1, $policy->last_run_at);
            if (($due[0] ?? null) !== null && $due[0] <= now()) {
                \App\Services\ControlPlane\BackupCenterService::runBackup($policy, 'schedule');
            }
        });
})->everyFiveMinutes()->name('cp-backup-policy-sweep');

// Phase 26.1D: scheduler heartbeat for platform:doctor. Written by whichever
// process executes the schedule (scheduler:work / cron); staleness is the
// doctor's honest signal that the scheduler container/service is down.
Schedule::call(function () {
    \Illuminate\Support\Facades\Cache::put('platform.scheduler.heartbeat', now(), now()->addMinutes(6));
})->everyFiveMinutes()->name('cp-scheduler-heartbeat');
