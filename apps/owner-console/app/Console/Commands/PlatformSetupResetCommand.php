<?php

namespace App\Console\Commands;

use App\Models\PlatformSetting;
use App\Services\Platform\SetupState;
use Illuminate\Console\Command;

/**
 * Phase 26D.5 — authorized reopening of first-run setup.
 *
 * /setup locks permanently after bootstrap. This command is the ONLY supported
 * way to reopen it, and it requires direct server access plus an explicit
 * typed confirmation. Existing data is preserved; completing setup again
 * creates the admin only if no admin exists (SetupState is idempotent).
 */
class PlatformSetupResetCommand extends Command
{
    protected $signature = 'platform:setup-reset';

    protected $description = 'Reopen the first-run setup wizard (authorized server action; existing data is preserved)';

    public function handle(): int
    {
        if (! SetupState::initialized()) {
            $this->info('Setup is not locked — /setup is already open.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Reopening setup temporarily unlocks first-run configuration. Admin users and data are preserved. Type confirmation below to continue.')) {
            $this->line('Aborted.');

            return self::FAILURE;
        }

        if ($this->ask('Type RESET to confirm') !== 'RESET') {
            $this->line('Aborted.');

            return self::FAILURE;
        }

        PlatformSetting::query()->where('key', SetupState::KEY_COMPLETED)->delete();
        \Illuminate\Support\Facades\Cache::forget('platform.initialized');

        $this->warn('Setup unlocked. Visit /setup to review configuration. It will lock again after completion.');

        return self::SUCCESS;
    }
}
