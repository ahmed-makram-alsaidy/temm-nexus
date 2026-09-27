<?php

namespace App\Console\Commands;

use App\Services\ControlPlane\Connectors\ConnectorCapability;
use App\Services\ControlPlane\Connectors\ConnectorManifest;
use App\Services\ControlPlane\Connectors\ConnectorNotSupported;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Connectors\Support\Platform;
use Illuminate\Console\Command;

/**
 * Phase 27M.2 — inspect a connector: definition, credential schema,
 * capabilities, compatibility. Never shows secret VALUES (there are none to
 * show — the schema is declarative).
 */
class ConnectorInspectCommand extends Command
{
    protected $signature = 'connector:inspect {key : Connector key, e.g. supabase}';

    protected $description = 'Inspect a connector definition (no secret values)';

    public function handle(): int
    {
        $key = (string) $this->argument('key');
        try {
            $connector = ConnectorRegistry::instance()->connector($key);
        } catch (ConnectorNotSupported $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $manifest = $connector->manifest();
        $definition = $connector->definition();

        $this->info($definition->name.' ('.$manifest->key().') v'.$manifest->version());
        $this->line($definition->description);
        $this->line('');
        $this->line('Author: <comment>'.$definition->author.'</comment>'.($definition->license ? ' — License: <comment>'.$definition->license.'</comment>' : ''));
        $this->line('Trust: <comment>'.$definition->trust.'</comment> — Category: <comment>'.$definition->category.'</comment> — Import flow: <comment>'.$definition->importFlow.'</comment>');
        $this->line('Platform requirement: <comment>'.($definition->platformRequirement ?? 'none').'</comment> — This platform: <comment>'.Platform::version().'</comment> — Compatible: <comment>'.(ConnectorManifest::satisfiesPlatform($definition->platformRequirement ?? '*', Platform::core()) ? 'yes' : 'NO').'</comment>');

        $this->line('');
        $this->line('<options=bold>Credentials (schema only — never values):</>');
        foreach ($definition->credentials as $field) {
            $flags = $field->secret ? 'secret' : 'plain';
            $flags .= $field->required ? ', required' : '';
            $this->line("  {$field->key} — {$field->label} [{$field->type}, {$field->scope}, {$flags}]{$this->capabilitySuffix($field->capability)}");
        }
        foreach ($definition->configuration as $field) {
            $this->line("  {$field->key} — {$field->label} [{$field->type}, configuration]{$this->capabilitySuffix($field->capability)}");
        }

        $this->line('');
        $this->line('<options=bold>Capabilities:</>');
        foreach ($manifest->capabilities() as $capability) {
            $this->line('  '.$capability.' — '.ConnectorCapability::label($capability));
        }

        $this->line('');
        $this->line('<options=bold>Permissions:</> '.($manifest->permissions() === [] ? '(none declared)' : implode(', ', $manifest->permissions())));

        return self::SUCCESS;
    }

    protected function capabilitySuffix(?string $capability): string
    {
        return $capability ? ' — unlocks '.$capability : '';
    }
}
