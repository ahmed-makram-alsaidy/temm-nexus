<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MigrationSource extends Model
{
    protected $fillable = [
        'project_id', 'environment_id', 'type', 'display_name', 'source_ref',
        'connection', 'secret_refs', 'read_only', 'status', 'last_error',
        'last_analyzed_at', 'last_tested_at', 'created_by',
        // Phase 25 — management metadata.
        'external_account_connection_id', 'management_project_ref', 'region',
        'organization', 'capabilities',
        // Phase 27I.1 — connector traceability (connector_key mirrors `type`,
        // kept as its own column so the provider-agnostic registry has one
        // canonical lookup while legacy rows keep working).
        'connector_key', 'connector_version', 'connector_instance_id',
    ];

    protected function casts(): array
    {
        return [
            'connection' => 'array', 'secret_refs' => 'array', 'read_only' => 'boolean',
            'last_analyzed_at' => 'datetime', 'last_tested_at' => 'datetime', 'capabilities' => 'array',
        ];
    }

    /**
     * Phase 27D — the stable connector key for this source: the Phase 27
     * column when present, the legacy `type` column otherwise.
     */
    public function effectiveConnectorKey(): string
    {
        return (string) ($this->connector_key ?: $this->type ?? '');
    }

    /** Record the connector version actually used for this source. */
    public function stampConnector(?string $key = null, ?string $version = null): void
    {
        $this->update(array_filter([
            'connector_key' => $key ?? $this->connector_key,
            'connector_version' => $version ?? $this->connector_version,
        ]));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function externalAccountConnection(): BelongsTo
    {
        return $this->belongsTo(ExternalAccountConnection::class, 'external_account_connection_id');
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(MigrationAnalysis::class);
    }
}
