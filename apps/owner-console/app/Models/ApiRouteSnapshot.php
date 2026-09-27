<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiRouteSnapshot extends Model
{
    protected $fillable = ['project_id', 'routes', 'captured_at'];

    protected function casts(): array
    {
        return ['routes' => 'array', 'captured_at' => 'datetime'];
    }
}
