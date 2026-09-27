<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 26D — platform key/value settings (first-run setup state).
 * Values are encrypted at rest so database dumps never leak operator
 * configuration. Never log rows from this table.
 */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'platform_settings';

    protected $fillable = ['key', 'value', 'secret'];

    protected $casts = [
        'value' => 'encrypted',
        'secret' => 'boolean',
    ];
}
