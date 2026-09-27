<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Runs an ALLOWLISTED artisan command inside a project's own checkout
 * (/projects/<slug>, booted with the project's own .env).
 * The environment is scrubbed (env -i) so console secrets can never leak in.
 * Every invocation should be audit logged by the caller.
 */
class ProjectArtisan
{
    public const ALLOWLIST = [
        'route:list',
        'schedule:list',
        'cache:clear',
        'queue:work',
        'queue:retry',
        'queue:forget',
        'schedule:run',
        'pulse:check',
        'about',
        // Phase 20S migration visibility + controlled runs (page-gated with
        // confirmations, backup precondition for rollback, fully audited).
        // Structural reads are safe; migrate/rollback execute in the PROJECT
        // checkout only, never the console.
        'migrate:status',
        'migrate',
        'migrate:rollback',
        // Demo-project-only helpers (defined in the demo app, harmless by design).
        'demo:throw-test-exception',
        'demo:emit-test-event',
        'demo:dispatch-test-job',
    ];

    public const TIMEOUT_SECONDS = 120;

    public static function run(Project $project, string $command, array $args = []): array
    {
        abort_unless(in_array($command, self::ALLOWLIST, true), 403, 'Command not permitted from the control plane.');

        $dir = ControlPlanePaths::projectDir($project->slug);
        abort_unless(is_file($dir.'/artisan'), 422, 'Project checkout not present on this host.');

        $full = array_merge(['php', 'artisan', $command], $args);
        // Scrub environment: the child boots ONLY from the project's own .env.
        $process = new SymfonyProcess($full, $dir, ['PATH' => '/usr/local/bin:/usr/bin:/bin'], null, self::TIMEOUT_SECONDS);
        $process->run();
        $output = mb_substr(trim($process->getOutput()."\n".$process->getErrorOutput()), 0, 8000);

        return [
            'ok' => $process->isSuccessful(),
            'exit' => $process->getExitCode(),
            'output' => LogSanitizer::sanitize($output),
        ];
    }
}
