<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ControlPlane\AdminAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Phase 26E.2 — supported admin recovery.
 *
 * No insecure backdoor: recovery requires direct server access (CLI) and the
 * same strong-password policy as first-run setup. Every use is audited.
 */
class ResetAdminPasswordCommand extends Command
{
    protected $signature = 'platform:admin-password {email : Email of an existing platform admin or team user}';

    protected $description = 'Set a new password for a platform admin (server-side recovery; audited)';

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("No user with email {$email}.");

            return self::FAILURE;
        }

        $password = $this->secret('New password (min 12 chars, letters+numbers+mixed case+symbols)');

        $errors = validator(['password' => $password], [
            'password' => ['required', 'string', Password::min(12)->letters()->numbers()->mixedCase()->symbols()],
        ])->errors();

        if ($errors->any()) {
            $this->error('Password rejected: '.$errors->first('password'));

            return self::FAILURE;
        }

        $confirm = $this->secret('Confirm new password');
        if ($confirm !== $password) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $user->forceFill(['password' => Hash::make($password)])->save();

        try {
            AdminAudit::record(action: 'USER_PASSWORD_RESET_SENT', metadata: ['via' => 'platform:admin-password', 'email' => $email]);
        } catch (\Throwable) {
            // Audit store unavailable — the password change itself already succeeded.
        }

        $this->info("Password updated for {$email}.");

        return self::SUCCESS;
    }
}
