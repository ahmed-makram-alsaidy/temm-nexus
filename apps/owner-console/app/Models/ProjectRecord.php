<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read-model over an ARBITRARY project table (display/sort/search/paginate).
 * Writes go through ProjectDatabaseExplorer (query builder + audit), never here.
 */
class ProjectRecord extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    public static function onTable(string $connection, string $table, ?string $primaryKey = null): self
    {
        $model = new static;
        $model->setConnection($connection);
        $model->setTable($table);
        if ($primaryKey) {
            $model->setKeyName($primaryKey);
        }

        return $model;
    }

    public function setKeyName($key)
    {
        $this->primaryKey = $key;

        return $this;
    }
}
