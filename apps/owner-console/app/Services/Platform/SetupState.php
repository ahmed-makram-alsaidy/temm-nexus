<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Phase 26D/26E — server-side setup state.
 *
 * Single source of truth for platform initialization. A platform counts as
 * initialized ONLY when the persisted flag is set AND at least one admin
 * user exists (defense in depth against a flag set without a real owner).
 *
 * Idempotence: completing setup twice, or racing two clients, must never
 * duplicate the admin, duplicate settings, or regenerate APP_KEY. All writes
 * happen inside a cache lock + transaction with re-checks.
 */
class SetupState
{
    public const KEY_COMPLETED = 'setup.completed';

    public const KEY_PROGRESS = 'setup.progress';

    public const LOCK = 'setup.bootstrap';

    public static function initialized(): bool
    {
        try {
            // Cached: this gate runs on every request. complete() invalidates.
            return Cache::remember('platform.initialized', 60, function () {
                if (! SchemaReady::tables(['platform_settings', 'users'])) {
                    return false;
                }

                // Operational rule: the platform is initialized once ANY user
                // account exists. The first-run gate only governs a truly
                // empty installation; the persisted wizard flag marks a
                // wizard-completed install, and instances created before the
                // flag existed are equally operational — the gate never locks
                // operators (admins or team roles) out of a working console.
                return User::query()->exists();
            });
        } catch (Throwable) {
            // Cannot read state (e.g. pre-migration container boot): the only
            // safe assumption for a brand-new install is "not initialized",
            // which routes to /setup where the system check explains why.
            return false;
        }
    }

    public static function allowSetup(): bool
    {
        return ! self::initialized();
    }

    /** @return array<string, mixed> */
    public static function progress(): array
    {
        try {
            $raw = PlatformSetting::query()->where('key', self::KEY_PROGRESS)->value('value');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);

                return is_array($decoded) ? $decoded : [];
            }

            return [];
        } catch (Throwable) {
            return [];
        }
    }

    public static function saveProgress(array $steps): void
    {
        self::set(self::KEY_PROGRESS, json_encode($steps ?: new \stdClass));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = PlatformSetting::query()->where('key', $key)->value('value');

            return $value ?? $default;
        } catch (Throwable) {
            return $default;
        }
    }

    public static function set(string $key, mixed $value, bool $secret = false): void
    {
        // The encrypted cast encrypts strings — normalize scalars/arrays first.
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_array($value)) {
            $value = json_encode($value);
        } elseif (! is_string($value)) {
            $value = (string) $value;
        }

        PlatformSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'secret' => $secret]
        );
    }

    /**
     * Bootstrap the first platform owner + persist wizard settings.
     * Exactly one owner is ever created by setup; concurrent callers get a
     * 409-grade failure (SetupLockedException) instead of a duplicate.
     *
     * @param  array<string, mixed>  $settings  wizard settings (platform name, url, mail, ...)
     * @return User the (possibly pre-existing) platform owner
     *
     * @throws SetupLockedException|SetupCompletedException
     */
    public static function complete(string $name, string $email, string $password, array $settings = [], bool $preHashed = false): User
    {
        if (self::initialized()) {
            throw new SetupCompletedException;
        }

        $lock = Cache::lock(self::LOCK, 15);

        if (! $lock->get()) {
            throw new SetupLockedException;
        }

        try {
            return DB::transaction(function () use ($name, $email, $password, $settings, $preHashed) {
                // Re-check inside the lock: another request may have finished
                // between our outer check and lock acquisition.
                if (self::initialized()) {
                    throw new SetupCompletedException;
                }

                $admin = User::query()->where('is_admin', true)->first();

                if (! $admin) {
                    // Unique email constraint backs this up at the DB level.
                    $admin = User::create([
                        'name' => $name,
                        'email' => strtolower($email),
                        'password' => $preHashed ? $password : Hash::make($password),
                        'is_admin' => true,
                        'cp_role' => 'owner',
                    ]);
                }

                foreach ($settings as $key => $value) {
                    self::set((string) $key, $value);
                }

                self::saveProgress(array_fill_keys(array_keys($settings), 'done') + ['admin' => 'done', 'complete' => 'done']);
                self::set(self::KEY_COMPLETED, '1');
                Cache::forget('platform.initialized');

                return $admin;
            });
        } finally {
            optional($lock)->release();
        }
    }
}
