<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectSchemaChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'project_id', 'kind', 'detail', 'sql', 'owner_user_id',
    ];

    protected function casts(): array
    {
        return ['detail' => 'array', 'created_at' => 'datetime'];
    }
}
