<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedSqlQuery extends Model
{
    protected $fillable = ['project_id', 'name', 'sql', 'created_by'];
}
