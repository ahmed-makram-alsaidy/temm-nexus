<?php

namespace App\Console\Commands;

use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use Illuminate\Console\Command;

/**
 * Phase 27M.1 — list registered connectors (key, name, version, trust,
 * capabilities, enabled).
 */
class ConnectorListCommand extends Command
{
    protected $signature = 'connector:list {--all : Include disabled and unverified connectors}';

    protected $description = 'List registered source connectors (Connector SDK, Phase 27)';

    public function handle(): int
    {
        $registry = ConnectorRegistry::instance();
        $descriptors = $registry->all();

        if ($descriptors === []) {
            $this->warn('No connectors registered. Check config/connectors.php discovery paths.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($descriptors as $d) {
            if (! $this->option('all') && ! $d['enabled']) {
                continue;
            }
            $rows[] = [
                $d['key'],
                $d['name'],
                $d['version'],
                $d['trust'],
                count($d['capabilities']),
                $d['enabled'] ? 'yes' : 'no',
                $d['import_flow'],
            ];
        }
        $this->table(['Key', 'Name', 'Version', 'Trust', 'Capabilities', 'Enabled', 'Import flow'], $rows);

        foreach ($registry->rejected() as $key => $reason) {
            $this->warn("Rejected package '{$key}': {$reason}");
        }

        return self::SUCCESS;
    }
}
