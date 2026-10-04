<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Services\ControlPlane\AdminAudit;
use App\Services\Platform\SetupCompletedException;
use App\Services\Platform\SetupLockedException;
use App\Services\Platform\SetupState;
use App\Services\Platform\SystemCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * Phase 26D — first-run setup wizard.
 *
 * 12 steps; every step re-verifies server-side state before rendering or
 * accepting input (no trust in the client). Progress lives in
 * platform_settings — the server is the single source of truth.
 *
 * Idempotence (26D.4): re-submitting any step upserts settings, never
 * duplicates the admin (created exactly once inside SetupState::complete),
 * never destroys the database, never regenerates APP_KEY.
 */
class SetupController extends Controller
{
    public const STEPS = [
        1 => 'Welcome & System Check',
        2 => 'Platform Identity',
        3 => 'Database Verification',
        4 => 'Redis Verification',
        5 => 'Storage',
        6 => 'Admin Account',
        7 => 'Domain / URL',
        8 => 'Mail (Optional)',
        9 => 'Backup Configuration',
        10 => 'AI Provider (Optional)',
        11 => 'Security Summary',
        12 => 'Complete',
    ];

    public function index(Request $request)
    {
        return redirect()->to($this->stepUrl($this->currentStep()));
    }

    public function show(Request $request, int $step)
    {
        if (! isset(self::STEPS[$step])) {
            abort(404);
        }

        if ($step > 1 && $this->gatingChecksFailed($step)) {
            return redirect()->to('/setup/step/1')
                ->with('setup_error', __('setup.gate_error'));
        }

        $view = [
            'step' => $step,
            'label' => self::STEPS[$step],
            'total' => count(self::STEPS),
            'progress' => SetupState::progress(),
            'checks' => SystemCheck::run(),
            'settings' => $this->wizardSettings(),
            'error' => $request->session()->get('setup_error'),
        ];

        return view("setup.step-{$step}", $view);
    }

    public function store(Request $request, int $step)
    {
        if (! isset(self::STEPS[$step])) {
            abort(404);
        }

        if ($step > 1 && $this->gatingChecksFailed($step)) {
            return redirect()->to('/setup/step/1')
                ->with('setup_error', __('setup.gate_error'));
        }

        $progress = SetupState::progress();

        switch ($step) {
            case 2: // Platform identity
                $data = $request->validate([
                    'platform_name' => ['required', 'string', 'max:80'],
                    'brand_name' => ['nullable', 'string', 'max:80'],
                    'support_url' => ['nullable', 'url', 'max:255'],
                    // 0.4.0-rc.5 (C.4/C.5): the default platform language is
                    // chosen during first setup; every new user inherits it.
                    'platform_locale' => ['nullable', 'string', 'in:'.implode(',', \App\Services\Localization\LocaleManager::AVAILABLE)],
                ]);
                SetupState::set('platform.name', $data['platform_name']);
                // brand_name is nullable and may be absent entirely (API
                // clients, partial form posts) — read it defensively.
                SetupState::set('platform.brand', ($data['brand_name'] ?? '') ?: $data['platform_name']);
                if (($data['support_url'] ?? null) !== null) {
                    SetupState::set('platform.support_url', $data['support_url']);
                }
                SetupState::set(\App\Services\Localization\LocaleManager::PLATFORM_DEFAULT_KEY, $data['platform_locale'] ?? 'en');
                $progress[2] = 'done';
                break;

            case 6: // Admin account (validated now; created atomically at completion)
                $data = $request->validate([
                    'admin_name' => ['required', 'string', 'max:120'],
                    'admin_email' => ['required', 'email:rfc', 'max:255', 'not_in:admin@example.com,admin@localhost'],
                    'admin_password' => ['required', 'string', Password::min(12)->letters()->numbers()->mixedCase()->symbols()],
                    'admin_password_confirmation' => ['required', 'same:admin_password'],
                ]);
                // Encrypted at rest (PlatformSetting encrypted cast). The user
                // row is only created at final completion — exactly once.
                SetupState::set('setup.pending_admin_name', $data['admin_name'], secret: true);
                SetupState::set('setup.pending_admin_email', strtolower($data['admin_email']), secret: true);
                SetupState::set('setup.pending_admin_hash', Hash::make($data['admin_password']), secret: true);
                $progress[6] = 'done';
                break;

            case 7: // Domain / URL
                $data = $request->validate([
                    'platform_url' => ['required', 'url', 'max:255'],
                    'https_confirmed' => ['nullable', 'boolean'],
                ]);
                SetupState::set('platform.url', rtrim($data['platform_url'], '/'));
                $progress[7] = 'done';
                break;

            case 8: // Mail (optional)
                $data = $request->validate([
                    'mail_configured' => ['nullable', 'boolean'],
                ]);
                SetupState::set('platform.mail_configured', (bool) ($data['mail_configured'] ?? false));
                $progress[8] = 'done';
                break;

            case 9: // Backups — informational; Backup Center is configured in the UI
                $progress[9] = 'done';
                break;

            case 10: // AI — optional BYOK; keys are added later in the admin UI
                $data = $request->validate([
                    'ai_enabled' => ['nullable', 'boolean'],
                ]);
                SetupState::set('ai.enabled', (bool) ($data['ai_enabled'] ?? false));
                $progress[10] = 'done';
                break;

            case 11: // Security acknowledgement
                $request->validate(['security_ack' => ['accepted']]);
                $progress[11] = 'done';
                break;

            case 12: // Complete — atomic bootstrap
                return $this->complete($request, $progress);

            case 1: // Welcome — nothing to persist
                $progress[1] = 'done';
                break;

            default:
                $progress[$step] = 'done';
        }

        SetupState::saveProgress($progress);

        return redirect()->to($this->stepUrl(min($step + 1, 12)));
    }

    protected function complete(Request $request, array $progress)
    {
        $name = (string) SetupState::get('setup.pending_admin_name', '');
        $email = (string) SetupState::get('setup.pending_admin_email', '');
        $hash = (string) SetupState::get('setup.pending_admin_hash', '');

        if ($name === '' || $email === '' || $hash === '') {
            return redirect()->to('/setup/step/6')
                ->with('setup_error', 'Create the admin account (step 6) before completing setup.');
        }

        try {
            $admin = SetupState::complete($name, $email, $hash, $this->wizardSettings() + [
                'setup.completed_at' => now()->toIso8601String(),
            ], preHashed: true);
        } catch (SetupCompletedException) {
            // Setup replay after completion: locked, nothing duplicated.
            return redirect()->to('/admin/login');
        } catch (SetupLockedException $e) {
            return redirect()->to('/setup/step/12')->with('setup_error', $e->getMessage());
        }

        // Cleanup: pending credentials never outlive bootstrap.
        foreach (['setup.pending_admin_name', 'setup.pending_admin_email', 'setup.pending_admin_hash'] as $k) {
            try {
                \App\Models\PlatformSetting::query()->where('key', $k)->delete();
            } catch (Throwable) {
            }
        }

        AdminAudit::record(
            action: 'PLATFORM_SETUP_COMPLETED',
            metadata: ['operator' => $email, 'platform' => (string) config('platform.version')],
        );

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return view('setup.complete', [
            'platform' => config('platform.name'),
            'version' => config('platform.version'),
            'owner' => $admin->email,
        ]);
    }

    /** @return array<string,string> persisted wizard settings (no secrets). */
    protected function wizardSettings(): array
    {
        return [
            'name' => (string) (SetupState::get('platform.name') ?? config('platform.name')),
            'brand' => (string) (SetupState::get('platform.brand') ?? config('platform.brand')),
            'url' => (string) (SetupState::get('platform.url') ?? ''),
            'mail_configured' => (string) (SetupState::get('platform.mail_configured') ?? '0'),
            'ai_enabled' => (string) (SetupState::get('ai.enabled') ?? '0'),
        ];
    }

    /** Steps 3+ require blocking checks green (re-verified server-side). */
    protected function gatingChecksFailed(int $step): bool
    {
        if ($step <= 2) {
            return false;
        }

        $cached = $this->checksCache;

        if ($cached === null) {
            $cached = SystemCheck::run();
            $this->checksCache = $cached;
        }

        return SystemCheck::blockingFailed($cached);
    }

    protected ?array $checksCache = null;

    protected function stepUrl(int $step): string
    {
        return '/setup/step/'.max(1, min(12, $step));
    }

    protected function currentStep(): int
    {
        $progress = SetupState::progress();

        foreach (array_keys(self::STEPS) as $step) {
            if (($progress[$step] ?? null) !== 'done') {
                return (int) $step;
            }
        }

        return 1;
    }
}
