<?php

namespace App\Console\Commands;

use App\Models\InfrastructureNode;
use Illuminate\Console\Command;

/**
 * Phase 21B: mint/rotate a node's agent token. The plaintext token prints
 * ONCE — only its sha256 is stored. Compromised tokens are revoked by
 * rotating (old hash is overwritten, no history kept).
 */
class InfraNodeTokenCommand extends Command
{
    protected $signature = 'infra:node-token {--node= : Node name}';

    protected $description = 'Rotate a node agent token (prints once, stores hash only).';

    public function handle(): int
    {
        $node = InfrastructureNode::query()->where('name', (string) $this->option('node'))->first();
        if (! $node) {
            $this->error('Unknown node.');

            return self::FAILURE;
        }
        $plain = $node->rotateToken();
        $this->info("New token for {$node->name} (store it in the agent, it will not be shown again):");
        $this->line($plain);

        return self::SUCCESS;
    }
}
