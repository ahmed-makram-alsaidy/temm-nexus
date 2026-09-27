<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 21B: which node serves a project service
 * (app|db|redis|worker|realtime|storage). Single-node default maps every
 * service to node-local-01; split/distributed profiles remap rows.
 */
class ProjectServiceNode extends Model
{
    public const SERVICES = ['app', 'db', 'redis', 'worker', 'realtime', 'storage'];

    protected $fillable = ['project_id', 'service', 'node_id', 'endpoint_override'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(InfrastructureNode::class, 'node_id');
    }
}
