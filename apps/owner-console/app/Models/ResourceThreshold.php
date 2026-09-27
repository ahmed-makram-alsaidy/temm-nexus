<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResourceThreshold extends Model
{
    protected $fillable = ['project_id', 'metric', 'warning_value', 'critical_value'];

    protected function casts(): array
    {
        return ['warning_value' => 'float', 'critical_value' => 'float'];
    }
}
