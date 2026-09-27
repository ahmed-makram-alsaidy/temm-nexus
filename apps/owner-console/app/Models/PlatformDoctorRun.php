<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 26.1H — history of deployment doctor runs (real schema addition used
 * to prove the artifact-to-artifact upgrade). Diagnostics metadata only —
 * never contains secret values.
 */
class PlatformDoctorRun extends Model
{
    protected $fillable = [
        'exit_code', 'pass', 'warning', 'fail', 'not_configured',
        'not_applicable', 'platform_version',
    ];

    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'pass' => 'integer',
            'warning' => 'integer',
            'fail' => 'integer',
            'not_configured' => 'integer',
            'not_applicable' => 'integer',
        ];
    }
}
