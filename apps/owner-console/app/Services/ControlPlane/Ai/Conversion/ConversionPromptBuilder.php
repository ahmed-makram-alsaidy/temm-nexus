<?php

namespace App\Services\ControlPlane\Ai\Conversion;

use App\Models\ClientRepository;
use App\Services\ControlPlane\Repository\ClientRepositoryService;

/**
 * Phase 33D/33F — AI prompt construction with HARD safety rules.
 *
 * - File contents passed to the AI are read through the repository's
 *   traversal-guarded path service (33C repo containment);
 * - lines matching secret markers are REPLACED with a SECRET_PRESENT
 *   placeholder — the AI never sees a secret value (33F);
 * - the system rules forbid shell, network, git and file access outside the
 *   repo, and require unified-diff-only output (33D).
 */
class ConversionPromptBuilder
{
    public const SECRET_PLACEHOLDER = '/* SECRET_PRESENT — value redacted */';

    /** Max files included per prompt (33C budget). */
    public const MAX_FILES = 20;

    /** System rules — 33D tool policy, restated on every call. */
    public const SYSTEM_RULES = <<<'RULES'
You convert legacy provider client code to the platform client SDK.
HARD RULES:
- Output ONLY unified diffs (--- a/<path> / +++ b/<path> format). No prose, no shell, no commands.
- Never invent file paths outside the repository files you are shown.
- Never include secret values; lines marked SECRET_PRESENT must stay unchanged or be replaced by an environment-variable reference.
- Do not change program semantics beyond the provider call conversion.
RULES;

    /** @return array{system: string, messages: list<array{role: string, content: string}>} */
    public function build(ClientRepository $repo, array $plan, array $budget = []): array
    {
        $maxFiles = $budget['max_files'] ?? self::MAX_FILES;
        $byFile = [];
        foreach ($plan['items'] as $item) {
            $byFile[$item['file']][] = $item;
        }
        $files = array_slice(array_keys($byFile), 0, $maxFiles);

        $sections = [];
        $index = 0;
        foreach ($files as $relative) {
            $index++;
            $absolute = ClientRepositoryService::safePath($repo, $relative);
            $content = @file_get_contents($absolute);
            if ($content === false) {
                continue; // unreadable files are skipped honestly
            }
            $sections[] = sprintf(
                "FILE %d: %s\nCALLSITES:\n%s\nCONTENT (secrets redacted):\n```%s\n```",
                $index,
                $relative,
                implode("\n", array_map(fn ($item) => sprintf(
                    '- line %d: %s (%s)%s',
                    $item['line'], $item['category'], $item['strategy'], $item['target'] !== '' ? ' target: '.$item['target'] : ''
                ), $byFile[$relative])),
                $this->redactSecrets((string) $content)
            );
        }

        $prompt = "Convert the following files from their legacy provider SDK to the platform client SDK.\n\n"
            ."STACK: {$plan['stack']}\n"
            .'SKIPPED (out of scope, leave untouched): '.(count($plan['skipped']))." callsite(s)\n\n"
            .implode("\n\n", $sections)."\n\n"
            .'Respond with one unified diff converting every listed callsite.';

        return [
            'system' => self::SYSTEM_RULES,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
    }

    /**
     * 33F — replace any line matching a secret marker with the
     * SECRET_PRESENT placeholder. Values never reach the AI.
     */
    public function redactSecrets(string $content): string
    {
        $markers = implode('|', array_map('preg_quote', [
            'SUPABASE_SERVICE', 'SERVICE_ROLE', 'FIREBASE_SERVICE_ACCOUNT', 'FIREBASE_PRIVATE_KEY',
            'DATABASE_PASSWORD', 'DB_PASSWORD', 'CLIENT_SECRET', 'API_KEY', 'SECRET_KEY', 'private_key',
        ]));

        return (string) preg_replace(
            '/^.*(?:'.$markers.').*$/mi',
            self::SECRET_PLACEHOLDER,
            $content
        );
    }
}
