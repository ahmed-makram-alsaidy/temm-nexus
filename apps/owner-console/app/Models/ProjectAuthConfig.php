<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectAuthConfig extends Model
{
    protected $fillable = [
        'project_id', 'providers', 'password_policy', 'session_policy', 'email_templates',
    ];

    protected function casts(): array
    {
        return [
            'providers' => 'array', 'password_policy' => 'array',
            'session_policy' => 'array', 'email_templates' => 'array',
        ];
    }
}
