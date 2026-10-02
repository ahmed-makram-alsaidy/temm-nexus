<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-user, per-scope UI preference: widget layout, density, hidden panels,
 * saved dashboard arrangement. Inspect Mode records its proposed UI changes
 * here after approval, which is why appearance changes never require a code
 * deployment (0.4.0 §27).
 *
 * `key` is a stable machine name; `value` is arbitrary JSON owned by the UI.
 */
class UserUiPreference extends Model
{
    protected $fillable = ['user_id', 'workspace_id', 'project_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
