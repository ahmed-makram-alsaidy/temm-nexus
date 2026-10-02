<?php

namespace App\Services\Product;

use App\Models\User;
use App\Models\UserUiPreference;
use Illuminate\Support\Facades\Log;

/**
 * 0.4.0 Phase I — the STRUCTURED UI PREFERENCE layer.
 *
 * This is the answer to "do not let AI randomly rewrite CSS". An appearance
 * change is expressed as a small, typed record:
 *
 *     {component: "home.summary", adjustment: "visibility", value: false}
 *
 * and stored in `user_ui_preferences` against the acting user (and the
 * workspace/project the change was made in, when it is scoped).
 *
 * WHY THIS SHAPE
 *   - **Whitelisted.** The adjustment must be one the component declares in
 *     `ComponentRegistry`, and the component must exist. There is no
 *     free-form key and no stylesheet field, so a mistaken or malicious
 *     suggestion cannot produce arbitrary rendering. There is deliberately
 *     NO `custom_css` and NO `custom_html`.
 *   - **Typed.** Booleans are booleans; density is one of three words;
 *     position is first or last. Anything else is refused, not coerced.
 *   - **Per-user.** Presentation preferences belong to the person, not the
 *     platform. One operator hiding a panel must not hide it for everyone,
 *     and no path here can alter another user's experience.
 *   - **Reversible.** Every write can be undone by removing the row.
 *   - **Auditable.** Callers record the proposal and the apply; this class
 *     only validates and persists.
 *
 * Anything that cannot be expressed as a whitelisted adjustment is a CODE
 * change, and code changes go through the isolated patch workflow
 * (PLAN → PATCH → DIFF → TEST → APPROVAL → APPLY → VERIFY). This class
 * deliberately cannot help with those.
 */
final class UiPreferenceService
{
    /** Preference key namespace, so UI config cannot collide with other prefs. */
    public const PREFIX = 'ui';

    public function __construct(
        private readonly User $user,
    ) {}

    public static function for(User $user): self
    {
        return new self($user);
    }

    // ─────────────────────────────────────────────────────────────────
    // Validation
    // ─────────────────────────────────────────────────────────────────

    /**
     * Validate a proposed adjustment against the registry whitelist.
     *
     * @return array{ok: bool, reason: ?string}
     */
    public static function validate(string $componentKey, string $adjustment, mixed $value): array
    {
        if (! ComponentRegistry::exists($componentKey)) {
            return ['ok' => false, 'reason' => 'unknown_component'];
        }

        if (! ComponentRegistry::isAdjustment($adjustment)) {
            return ['ok' => false, 'reason' => 'unsupported_adjustment'];
        }

        if (! ComponentRegistry::permits($componentKey, $adjustment)) {
            return ['ok' => false, 'reason' => 'adjustment_not_permitted'];
        }

        return match ($adjustment) {
            'visibility', 'expanded_by_default' => is_bool($value)
                ? ['ok' => true, 'reason' => null]
                : ['ok' => false, 'reason' => 'value_must_be_boolean'],
            'density' => in_array($value, ['compact', 'comfortable', 'spacious'], true)
                ? ['ok' => true, 'reason' => null]
                : ['ok' => false, 'reason' => 'unknown_density'],
            'position' => in_array($value, ['first', 'last'], true)
                ? ['ok' => true, 'reason' => null]
                : ['ok' => false, 'reason' => 'unknown_position'],
            default => ['ok' => false, 'reason' => 'unsupported_adjustment'],
        };
    }

    // ─────────────────────────────────────────────────────────────────
    // Write / undo
    // ─────────────────────────────────────────────────────────────────

    /**
     * Persist a PRE-VALIDATED adjustment. Callers must run `validate()` (and
     * authorise the component) first; this method re-validates anyway because
     * a persisted invalid value would be a rendering hazard, but it performs
     * NO authorisation — that belongs to the caller.
     *
     * @return array{ok: bool, reason: ?string}
     */
    public function apply(
        string $componentKey,
        string $adjustment,
        mixed $value,
        ?int $workspaceId = null,
        ?int $projectId = null,
    ): array {
        $check = self::validate($componentKey, $adjustment, $value);
        if (! $check['ok']) {
            // A refused adjustment is worth knowing about: it means something
            // proposed a change the platform does not support.
            Log::warning('UI adjustment refused', [
                'component' => $componentKey,
                'adjustment' => $adjustment,
                'reason' => $check['reason'],
                'user_id' => $this->user->getKey(),
            ]);

            return $check;
        }

        try {
            UserUiPreference::query()->updateOrCreate(
                [
                    'user_id' => $this->user->getKey(),
                    'workspace_id' => $workspaceId,
                    'project_id' => $projectId,
                    'key' => $this->preferenceKey($componentKey, $adjustment),
                ],
                ['value' => ['v' => $value]],
            );
        } catch (\Throwable $e) {
            Log::warning('UI adjustment could not be stored', [
                'component' => $componentKey,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'reason' => 'storage_failed'];
        }

        return ['ok' => true, 'reason' => null];
    }

    /** Undo one adjustment — back to the registry default. */
    public function revert(string $componentKey, string $adjustment, ?int $workspaceId = null, ?int $projectId = null): bool
    {
        try {
            return (bool) UserUiPreference::query()
                ->where('user_id', $this->user->getKey())
                ->where('workspace_id', $workspaceId)
                ->where('project_id', $projectId)
                ->where('key', $this->preferenceKey($componentKey, $adjustment))
                ->delete();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Forget every UI adjustment for this user. */
    public function revertAll(): int
    {
        try {
            return (int) UserUiPreference::query()
                ->where('user_id', $this->user->getKey())
                ->where('key', 'like', self::PREFIX.'.%')
                ->delete();
        } catch (\Throwable) {
            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Read
    // ─────────────────────────────────────────────────────────────────

    /**
     * Read back the effective value for one adjustment.
     *
     * Resolution is most-specific-first: project, then workspace, then the
     * user's global preference. A project-level choice therefore wins over a
     * workspace-wide one, which is what a user expects when they adjust
     * something inside a project.
     */
    public function value(
        string $componentKey,
        string $adjustment,
        ?int $workspaceId = null,
        ?int $projectId = null,
    ): mixed {
        $rows = $this->rows();

        $candidates = [
            'project' => $projectId !== null
                ? $rows->first(fn (UserUiPreference $r) => $r->project_id === $projectId
                    && $r->key === $this->preferenceKey($componentKey, $adjustment))
                : null,
            'workspace' => $workspaceId !== null
                ? $rows->first(fn (UserUiPreference $r) => $r->workspace_id === $workspaceId
                    && $r->project_id === null
                    && $r->key === $this->preferenceKey($componentKey, $adjustment))
                : null,
            'platform' => $rows->first(fn (UserUiPreference $r) => $r->workspace_id === null
                && $r->project_id === null
                && $r->key === $this->preferenceKey($componentKey, $adjustment)),
        ];

        $row = $candidates['project'] ?? $candidates['workspace'] ?? $candidates['platform'];

        return $row !== null
            ? ($row->value['v'] ?? ComponentRegistry::defaultFor($adjustment))
            : ComponentRegistry::defaultFor($adjustment);
    }

    /**
     * Effective preferences for MANY components in ONE query — the shape page
     * controllers pass to their views.
     *
     * Returns only adjustments the registry declares for each component, each
     * resolved to a value (preference, or the registry default).
     *
     * @param  list<string>  $componentKeys
     * @return array<string, array<string, mixed>>
     */
    public function effectiveForComponents(array $componentKeys, ?int $workspaceId = null, ?int $projectId = null): array
    {
        $rows = $this->rows();

        $out = [];
        foreach ($componentKeys as $componentKey) {
            if (! ComponentRegistry::exists($componentKey)) {
                continue;
            }

            $adjusted = [];
            foreach (ComponentRegistry::adjustmentsFor($componentKey) as $adjustment) {
                $key = $this->preferenceKey($componentKey, $adjustment);

                $projectRow = $projectId !== null
                    ? $rows->first(fn (UserUiPreference $r) => $r->project_id === $projectId && $r->key === $key)
                    : null;
                $workspaceRow = $workspaceId !== null
                    ? $rows->first(fn (UserUiPreference $r) => $r->workspace_id === $workspaceId
                        && $r->project_id === null && $r->key === $key)
                    : null;
                $globalRow = $rows->first(fn (UserUiPreference $r) => $r->workspace_id === null
                    && $r->project_id === null && $r->key === $key);

                $row = $projectRow ?? $workspaceRow ?? $globalRow;

                $adjusted[$adjustment] = $row !== null
                    ? ($row->value['v'] ?? ComponentRegistry::defaultFor($adjustment))
                    : ComponentRegistry::defaultFor($adjustment);
            }

            $out[$componentKey] = $adjusted;
        }

        return $out;
    }

    /**
     * Everything this user has adjusted, newest first — for a settings screen
     * and for tests asserting reversibility.
     *
     * @return list<array{component: string, adjustment: string, value: mixed, scope: string}>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->rows()->sortByDesc('updated_at') as $row) {
            $parsed = self::parseKey((string) $row->key);
            if ($parsed === null) {
                continue;
            }

            $out[] = [
                'component' => $parsed['component'],
                'adjustment' => $parsed['adjustment'],
                'value' => $row->value['v'] ?? null,
                'scope' => $row->project_id !== null ? 'project' : ($row->workspace_id !== null ? 'workspace' : 'platform'),
            ];
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * All of this user's `ui.*` rows, or an empty collection when the table
     * does not exist yet (pre-migration) — preferences must never break a
     * page render.
     */
    private function rows()
    {
        try {
            return UserUiPreference::query()
                ->where('user_id', $this->user->getKey())
                ->where('key', 'like', self::PREFIX.'.%')
                ->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    private function preferenceKey(string $componentKey, string $adjustment): string
    {
        return self::PREFIX.'.'.$componentKey.'.'.$adjustment;
    }

    /** @return array{component: string, adjustment: string}|null */
    public static function parseKey(string $key): ?array
    {
        if (! str_starts_with($key, self::PREFIX.'.')) {
            return null;
        }

        // `ui.<component.with.dots>.<adjustment>` — the adjustment is the
        // final segment, so a dotted component key still parses.
        $rest = substr($key, strlen(self::PREFIX) + 1);
        $position = strrpos($rest, '.');
        if ($position === false) {
            return null;
        }

        return [
            'component' => substr($rest, 0, $position),
            'adjustment' => substr($rest, $position + 1),
        ];
    }
}
