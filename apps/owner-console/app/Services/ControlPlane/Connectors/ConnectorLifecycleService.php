<?php

namespace App\Services\ControlPlane\Connectors;

use App\Models\MigrationSource;
use App\Models\ProjectSecret;
use App\Services\ControlPlane\AdminAudit;

/**
 * Phase 27F — connector instance lifecycle (disable / remove).
 *
 * The forward lifecycle (register → configure → test → discover → select →
 * probe → analyze → extract → validate) is exercised through the registry
 * and Migration Center. This service owns the teardown side:
 *
 * - disable: source stops being invokable, configuration intact;
 * - remove: configuration and connector secret REFERENCES are cleared and
 *   vault secrets that exist only for this source are deleted — historical
 *   analyses/artifacts are PRESERVED (they are audit evidence, 27F.1: never
 *   erase migration history silently).
 */
class ConnectorLifecycleService
{
    public static function disable(MigrationSource $source): MigrationSource
    {
        $source->update(['status' => 'disabled']);
        AdminAudit::record('CONNECTOR_INSTANCE_DISABLED', $source->project, 'migration_source', $source->id, [
            'connector' => $source->effectiveConnectorKey(),
        ]);

        return $source;
    }

    /**
     * Remove a connector instance. Returns the preserved (disabled) source
     * record so historical analyses/artifacts keep their foreign key.
     */
    public static function removeInstance(MigrationSource $source): MigrationSource
    {
        $removedRefs = [];
        $refs = (array) ($source->secret_refs ?? []);
        foreach ($refs as $fieldKey => $secretName) {
            if (! is_string($secretName) || $secretName === '') {
                continue;
            }
            // Delete the vault secret only when no OTHER source still references it.
            $stillReferenced = MigrationSource::query()
                ->where('project_id', $source->project_id)
                ->where('id', '!=', $source->id)
                ->get()
                ->contains(fn (MigrationSource $other) => in_array($secretName, array_values((array) ($other->secret_refs ?? [])), true));
            if (! $stillReferenced) {
                ProjectSecret::query()->where('project_id', $source->project_id)->where('name', $secretName)->first()?->delete();
            }
            $removedRefs[] = $fieldKey;
        }

        $source->update([
            'status' => 'disabled',
            'secret_refs' => [],
            'connection' => [],
            'last_error' => null,
        ]);

        AdminAudit::record('CONNECTOR_INSTANCE_REMOVED', $source->project, 'migration_source', $source->id, [
            'connector' => $source->effectiveConnectorKey(),
            'removed_secret_refs' => $removedRefs,
            'history_preserved' => true,
        ]);

        return $source;
    }
}
