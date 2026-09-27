<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupRecord extends Model
{
    protected $fillable = [
        'db_name', 'status', 'size_bytes', 'checksum', 'finished_at', 'restore_test_status',
        'type', 'location', 'verified_at', 'log',
    ];

    protected function casts(): array
    {
        return [
            'finished_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }
}
