<?php

namespace App\Console\Commands;

use App\Services\ControlPlane\Connectors\ConnectorNotSupported;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Connectors\Testing\ConnectorContractTester;
use Illuminate\Console\Command;

/**
 * Phase 27M.3 — run the Phase 27L contract test battery against a
 * connector (or all registered ones).
 */
class ConnectorTestCommand extends Command
{
    protected $signature = 'connector:test {key? : Connector key — omit to test all registered connectors}';

    protected $description = 'Run Connector SDK contract tests against a connector';

    public function handle(): int
    {
        $registry = ConnectorRegistry::instance();
        $keys = $this->argument('key') !== null ? [(string) $this->argument('key')] : array_keys($registry->allConnectors());

        $overall = self::SUCCESS;
        foreach ($keys as $key) {
            try {
                $connector = $registry->connector($key);
            } catch (ConnectorNotSupported $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $results = ConnectorContractTester::run($connector);
            $summary = ConnectorContractTester::summarize($results);

            $this->info('Connector: '.$key.' — '.$summary['status'].' ('.$summary['detail'].')');
            foreach ($results as $check => $result) {
                $style = $result['status'] === 'PASS' ? 'info' : ($result['status'] === 'FAIL' ? 'error' : 'comment');
                $this->line(sprintf('  [%s] %-26s %s', $result['status'], $check, $result['detail']), $style);
            }
            $this->line('');
            if ($summary['status'] !== 'PASS') {
                $overall = self::FAILURE;
            }
        }

        return $overall;
    }
}
