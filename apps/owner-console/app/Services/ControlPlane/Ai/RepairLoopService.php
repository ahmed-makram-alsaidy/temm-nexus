<?php

namespace App\Services\ControlPlane\Ai;

use App\Models\AiPatchRun;
use App\Models\AiRepairLoop;
use App\Models\CopilotRun;
use App\Models\Project;
use App\Services\ControlPlane\AdminAudit;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Phase 25J — automated test / repair loop.
 *
 * The AI NEVER supplies commands. Test commands come from this platform
 * allowlist only. Structured failures (exit code, test names, summaries,
 * short stack excerpts) are captured, redacted, and fed back to the Copilot
 * builder — whose repair patches still require review + approval before the
 * next test round. Hard iteration/AI-call limits prevent runaway loops.
 */
class RepairLoopService
{
    /** Allowlisted command templates (id → [binary, ...args]). */
    public const COMMANDS = [
        'phpunit' => ['php', 'vendor/bin/phpunit', '--stop-on-failure'],
        'phpunit_file' => ['php', 'vendor/bin/phpunit'],
        'flutter_analyze' => ['flutter', 'analyze', '--no-pub'],
        'flutter_test' => ['flutter', 'test', '--no-pub'],
        'npm_test' => ['npm', 'test'],
        'dart_analyze' => ['dart', 'analyze'],
        'php_lint' => ['php', '-l'],
    ];

    public const DEFAULT_MAX_ITERATIONS = 3;

    public const DEFAULT_MAX_AI_CALLS = 6;

    /** Start a loop for an approved+applied patch run (or a bare tree check). */
    public static function start(Project $project, ?AiPatchRun $patchRun, string $commandId, ?array $overrides = []): AiRepairLoop
    {
        abort_if(! isset(self::COMMANDS[$commandId]), 422, "Test command '{$commandId}' is not allowlisted.");
        $loop = AiRepairLoop::create([
            'project_id' => $project->id,
            'ai_patch_run_id' => $patchRun?->id,
            'test_command' => $commandId,
            'max_iterations' => min(10, $overrides['max_iterations'] ?? self::DEFAULT_MAX_ITERATIONS),
            'max_ai_calls' => min(20, $overrides['max_ai_calls'] ?? self::DEFAULT_MAX_AI_CALLS),
            'status' => 'running',
        ]);
        AdminAudit::record('REPAIR_LOOP_RUN', $project, 'ai_repair_loop', $loop->id, ['command' => $commandId]);

        return $loop;
    }

    /** Execute one round: run tests, capture structured failures. */
    public static function runTests(AiRepairLoop $loop, string $cwd): array
    {
        abort_if(! isset(self::COMMANDS[$loop->test_command]), 422, 'Command not allowlisted.');
        abort_if($loop->status !== 'running', 422, 'Loop is not running.');
        abort_if(! is_dir($cwd), 422, 'Working directory unavailable.');
        abort_if($loop->iterations >= $loop->max_iterations, 422, 'Iteration limit reached.');

        $argv = self::COMMANDS[$loop->test_command];
        $binary = $argv[0];
        if (self::which($binary) === null && ! in_array($binary, ['php'], true)) {
            // Honest unavailability — never fabricated.
            $result = ['exit_code' => -1, 'summary' => "command '{$binary}' is not available on this host", 'failures' => [], 'available' => false];
        } else {
            $proc = Process::path($cwd)->timeout(600)->run($argv);
            $output = $proc->output()."\n".$proc->errorOutput();
            $result = [
                'exit_code' => $proc->exitCode() ?? -1,
                'summary' => Str::limit(AiContextBuilder::redact($output), 2000),
                'failures' => self::extractFailures($output),
                'available' => true,
            ];
        }

        $loop->iterations++;
        $history = $loop->history ?? [];
        $history[] = [
            'iteration' => $loop->iterations,
            'exit_code' => $result['exit_code'],
            'failures' => $result['failures'],
            'summary' => Str::limit($result['summary'], 300),
        ];
        $loop->update([
            'history' => $history,
            'status' => ($result['exit_code'] === 0 && $result['available']) ? 'passed' : ($loop->iterations >= $loop->max_iterations ? 'limit_reached' : 'running'),
        ]);
        AdminAudit::record('TEST_RUN', $loop->project, 'ai_repair_loop', $loop->id, [
            'iteration' => $loop->iterations, 'exit_code' => $result['exit_code'],
        ]);

        return $result;
    }

    /** Extract a compact failure structure from common test outputs. */
    protected static function extractFailures(string $output): array
    {
        $failures = [];
        // PHPUnit style: "1) Tests\Foo::test_bar"
        if (preg_match_all('/^\d+\)\s+([A-Za-z0-9_\\\\:]+)::?([A-Za-z0-9_]*)$/m', $output, $m, PREG_SET_ORDER)) {
            foreach (array_slice($m, 0, 20) as $hit) {
                $failures[] = ['class' => $hit[1], 'test' => $hit[2] ?? ''];
            }
        }
        // "FAILURES!" / "Tests: X, Assertions: Y" summary lines.
        if (preg_match('/Tests:\s+(\d+),\s+Assertions:\s+(\d+)/', $output, $s)) {
            $failures[] = ['summary' => "tests={$s[1]} assertions={$s[2]}"];
        }
        // Dart/flutter analyzer errors count.
        if (preg_match('/(\d+) issues? found/', $output, $d)) {
            $failures[] = ['summary' => "analyzer issues={$d[1]}"];
        }

        return array_slice($failures, 0, 25);
    }

    /** Ask the Copilot builder for a repair proposal (consumes AI budget). */
    public static function proposeRepair(AiRepairLoop $loop, MigrationCopilot $copilot, array $testResult): ?CopilotRun
    {
        abort_if($loop->ai_calls >= $loop->max_ai_calls, 422, 'AI call budget exhausted.');
        $loop->ai_calls++;
        $loop->save();

        $patchRun = $loop->patchRun;

        return $copilot->run($loop->project, 'fix_failures', [
            'repository' => $patchRun?->clientRepository,
            'failures' => $testResult['failures'] ?? [],
        ]);
    }

    protected static function which(string $binary): ?string
    {
        $result = Process::timeout(10)->run('command -v '.$binary);
        $out = trim($result->output());

        return $out !== '' ? $out : null;
    }
}
