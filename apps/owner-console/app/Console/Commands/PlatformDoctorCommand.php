<?php

namespace App\Console\Commands;

use App\Services\Platform\Doctor;
use Illuminate\Console\Command;

/**
 * Phase 26.1D — deployment doctor.
 *
 * php artisan platform:doctor          human-readable report
 * php artisan platform:doctor --json   machine-readable (used by support bundle)
 *
 * Exit codes: 0 healthy · 1 warnings (incl. NOT_CONFIGURED) · 2 blocking failures.
 * Secret-safe by construction: presence/absence only, never values.
 */
class PlatformDoctorCommand extends Command
{
    protected $signature = 'platform:doctor {--json : Output machine-readable JSON}';

    protected $description = 'Diagnose the platform deployment (secret-safe; exit 0=healthy, 1=warnings, 2=failures)';

    public function handle(): int
    {
        $report = Doctor::run();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $decorated = $this->output->isDecorated();
            $colors = ['PASS' => 'green', 'WARNING' => 'yellow', 'FAIL' => 'red', 'NOT_CONFIGURED' => 'blue', 'NOT_APPLICABLE' => 'gray'];
            foreach ($report['checks'] as $c) {
                $status = $decorated ? "<fg={$colors[$c['status']]}>{$c['status']}</>" : $c['status'];
                $this->line(sprintf('  [%s] %-28s %s', $status, $c['label'], $c['detail']));
            }
            $s = $report['summary'];
            $this->newLine();
            $this->line("  {$s['pass']} pass · {$s['warning']} warnings · {$s['fail']} failures · {$s['not_configured']} not configured · {$s['not_applicable']} n/a");
        }

        return $report['summary']['exit_code'];
    }
}
