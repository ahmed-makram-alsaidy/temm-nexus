<?php

namespace App\Services\ControlPlane\Ai\Conversion;

use App\Models\ClientRepository;

/**
 * Phase 33E — the AI test loop runs ONLY allowlisted commands, chosen by the
 * DETECTED project type. No AI-generated command ever reaches a shell: the
 * AI selects a verification step by name, the allowlist resolves it, and
 * anything outside the list is refused and recorded.
 */
class TestCommandAllowlist
{
    /** Project type → allowed verification commands (fixed binaries + args). */
    protected const ALLOWLIST = [
        'node' => [
            'test' => ['npm', 'test'],
            'lint' => ['npm', 'run', 'lint'],
            'build' => ['npm', 'run', 'build'],
        ],
        'php' => [
            'test' => ['composer', 'test'],
            'lint' => ['composer', 'lint'],
        ],
        'flutter' => [
            'analyze' => ['flutter', 'analyze'],
            'test' => ['flutter', 'test'],
        ],
    ];

    /** Detect the project type from the repository root (deterministic). */
    public function projectType(ClientRepository $repo): ?string
    {
        $root = (string) $repo->root_path;
        if (is_file($root.'/pubspec.yaml')) {
            return 'flutter';
        }
        if (is_file($root.'/package.json')) {
            return 'node';
        }
        if (is_file($root.'/composer.json')) {
            return 'php';
        }

        return null;
    }

    /**
     * Resolve a verification step NAME to a fixed command. Anything not on
     * the allowlist is refused (33E — no arbitrary generated commands).
     *
     * @return array{allowed: bool, command?: list<string>, reason?: string}
     */
    public function resolve(ClientRepository $repo, string $step): array
    {
        $type = $this->projectType($repo);
        if ($type === null) {
            return ['allowed' => false, 'reason' => 'no known project type detected'];
        }
        $command = self::ALLOWLIST[$type][$step] ?? null;
        if ($command === null) {
            return ['allowed' => false, 'reason' => "step '{$step}' is not allowlisted for {$type} projects"];
        }

        return ['allowed' => true, 'command' => $command];
    }

    /** The steps the UI may offer for this repository (never AI-chosen freely). */
    public function stepsFor(ClientRepository $repo): array
    {
        $type = $this->projectType($repo);

        return $type !== null ? array_keys(self::ALLOWLIST[$type]) : [];
    }
}
