<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FunctionVersion extends Model
{
    protected $fillable = ['function_id', 'version', 'config', 'deployed_by'];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }
}
