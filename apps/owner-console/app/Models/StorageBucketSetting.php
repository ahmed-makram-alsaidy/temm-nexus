<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageBucketSetting extends Model
{
    protected $fillable = [
        'project_id', 'bucket', 'visibility', 'max_size_mb', 'allowed_mimes',
    ];

    protected function casts(): array
    {
        return ['allowed_mimes' => 'array'];
    }
}
