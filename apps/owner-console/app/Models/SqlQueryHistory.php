<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SqlQueryHistory extends Model
{
    public $timestamps = false;

    protected $table = 'sql_query_history';

    protected $fillable = [
        'project_id', 'owner_user_id', 'query_hash', 'category',
        'status', 'duration_ms', 'rows', 'redacted_sql', 'error',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
