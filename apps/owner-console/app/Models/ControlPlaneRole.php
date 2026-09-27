<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ControlPlaneRole extends Model
{
    protected $fillable = ['name', 'permissions'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }
}
