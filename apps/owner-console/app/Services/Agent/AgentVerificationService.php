<?php

namespace App\Services\Agent;

use App\Models\AgentTask;
use App\Models\AgentVerification;
use App\Services\Agent\Contract\AgentRuntimeException;
use App\Services\ControlPlane\AdminAudit;
use Illuminate\Support\Str;

/**
 * Post-apply verification: runs the PROJECT'S OWN verification policy inside
 * the authoritative source and records honest results (command, exit code,
 * duration, output byte size). Deploy is categorically NOT part of
 * verification.
 *
 * The policy is operator-defined per project (config('agent.verification'),
 * keyed by project slug, with a default). The agent cannot propose or alter
 * these commands — only the code it changed is under test.
 */
class AgentVerificationService
{
    /**
     * @return AgentVerification
     */
    public function verify(AgentTask $task): AgentVerification
    {
        if ($task->applied_at === null) {
            throw AgentRuntimeException::make(AgentRuntimeException::SESSION_FAILED, 'Verification requires an applied changeset.');
        }

        $source = AgentWorkspaceService::authoritativeRoot($task->project);
        $commands = $this->policyFor($task);

        if ($commands === []) {
            // Honest outcome: nothing was configured to verify, so nothing
            // "passed". The task still completes (apply already happened).
            $verification = AgentVerification::create([
                'agent_task_id' => $task->id,
                'status' => AgentVerification::STATUS_SKIPPED,
                'commands' => [],
                'summary' => 'No verification commands configured for this project.',
            ]);

            $task->update([
                'status' => AgentTask::STATUS_COMPLETED,
                'verified_at' => now(),
                'finished_at' => now(),
            ]);

            AdminAudit::record('AGENT_VERIFICATION_RECORDED', $task->project, 'agent_task', $task->id, [
                'status' => AgentVerification::STATUS_SKIPPED,
                'commands' => 0,
            ]);

            return $verification;
        }

        $results = [];
        $status = AgentVerification::STATUS_PASSED;

        foreach ($commands as $command) {
            $startedAt = now();
            $start = microtime(true);

            try {
                // Operator-defined policy commands run through the shell in
                // the source directory (quoting preserved — never re-assembled
                // from a naive space-split), with a bounded timeout.
                $process = \Symfony\Component\Process\Process::fromShellCommandline(
                    $command,
                    $source,
                    null,
                    null,
                    (float) config('agent.verification.timeout_seconds', 900)
                );
                $process->run();
            } catch (\Throwable $e) {
                $results[] = [
                    'command' => $command, 'exit_code' => null, 'duration_ms' => (int) ((microtime(true) - $start) * 1000),
                    'started_at' => $startedAt->toIso8601String(), 'truncated' => false,
                    'output_bytes' => 0, 'error' => 'verification command failed to start',
                ];
                $status = AgentVerification::STATUS_ERROR;

                continue;
            }

            $output = $process->getErrorOutput().$process->getOutput();
            $maxBytes = (int) config('agent.limits.max_output_bytes_per_command', 262144);

            $results[] = [
                'command' => $command,
                'exit_code' => $process->getExitCode(),
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
                'started_at' => $startedAt->toIso8601String(),
                'output_bytes' => strlen($output),
                'truncated' => strlen($output) > $maxBytes,
            ];

            if ($process->getExitCode() !== 0) {
                $status = AgentVerification::STATUS_FAILED;
            }
        }

        $verification = AgentVerification::create([
            'agent_task_id' => $task->id,
            'status' => $status,
            'commands' => $results,
            'summary' => match ($status) {
                AgentVerification::STATUS_PASSED => 'All verification commands passed.',
                AgentVerification::STATUS_FAILED => 'One or more verification commands failed.',
                default => 'Verification could not run to completion.',
            },
        ]);

        $task->update([
            'status' => $status === AgentVerification::STATUS_PASSED
                ? AgentTask::STATUS_COMPLETED
                : AgentTask::STATUS_FAILED,
            'verified_at' => now(),
            'finished_at' => now(),
            'error_category' => $status === AgentVerification::STATUS_PASSED ? null : AgentRuntimeException::COMMAND_FAILED,
            'error_message' => $status === AgentVerification::STATUS_PASSED ? null : $verification->summary,
        ]);

        AdminAudit::record('AGENT_VERIFICATION_RECORDED', $task->project, 'agent_task', $task->id, [
            'status' => $status,
            'commands' => count($results),
        ]);

        return $verification;
    }

    /** Verification policy for a project (operator-configured, never agent-proposed). */
    public function policyFor(AgentTask $task): array
    {
        $config = (array) config('agent.verification.projects', []);
        $slug = $task->project->slug;

        if (array_key_exists($slug, $config) && is_array($config[$slug])) {
            return array_values(array_filter($config[$slug], 'is_string'));
        }

        $default = config('agent.verification.default', []);

        return is_array($default) ? array_values(array_filter($default, 'is_string')) : [];
    }
}
