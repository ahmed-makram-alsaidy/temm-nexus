<?php

namespace App\Services\ControlPlane;

use App\Models\OnboardingSession;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\MigrationSource;
use Illuminate\Support\Str;

/**
 * Phase 24B — Project Onboarding Wizard.
 *
 * Two flows: CREATE NEW PROJECT and IMPORT EXISTING PROJECT (connector-
 * driven, Phase 27G). State persists in onboarding_sessions so an operator
 * can leave and resume. Nothing is created until the final confirmation
 * step; the import flow reuses the Migration Center (no duplicated analysis
 * logic) and resolves all providers through the connector registry.
 */
class OnboardingService
{
    public const CREATE_STEPS = [
        'identity', 'environment', 'database', 'auth', 'storage', 'realtime',
        'api', 'secrets', 'sdk', 'health', 'finish',
    ];

    public const IMPORT_STEPS = [
        'identity', 'source_type', 'connect_account', 'select_project',
        'capabilities', 'db_credential', 'analyze', 'compatibility',
        'link_repository', 'scan_client', 'copilot_plan', 'review', 'finish',
    ];

    /**
     * 25B — prepare the import draft early: Platform Project + canonical
     * environments + read-only source profile with management metadata.
     * No migration executes. Returns existing draft if already prepared.
     * Phase 27G.2 — source profile creation goes through the session's
     * chosen connector (27D.2: no provider hardwiring).
     */
    public static function prepareImportDraft(OnboardingSession $session, ?\App\Models\ExternalAccountConnection $connection, array $discovered, ?\App\Services\ControlPlane\Connectors\Contracts\SourceConnector $connector = null): array
    {
        abort_if($session->flow !== 'import', 422, 'Not an import-flow session.');
        $state = $session->state ?? [];
        abort_if(empty($state['name']), 422, 'Complete the identity step first.');

        if ($session->project_id) {
            $project = $session->project;
        } else {
            $project = self::createImportProjectShell($session, 'Imported via onboarding wizard (read-only source, no migration)');
            $session->update(['project_id' => $project->id]);
        }

        $source = \App\Models\MigrationSource::where('project_id', $project->id)
            ->where('management_project_ref', $discovered['ref'] ?? null)->first();
        if (! $source) {
            $connector ??= self::connectorForSession($session);
            $source = $connector->createSourceProfile($project, [
                'account_connection' => $connection,
                'discovered' => $discovered,
            ], [], EnvironmentService::defaultFor($project)->id);
        }

        return ['project' => $project, 'source' => $source];
    }

    /**
     * Phase 27G.2 — draft preparation for generic (credentials-form)
     * connectors: creates the project shell so vault secrets and the source
     * profile have a home before the final confirmation.
     */
    public static function prepareConnectorDraft(OnboardingSession $session): array
    {
        abort_if($session->flow !== 'import', 422, 'Not an import-flow session.');
        $state = $session->state ?? [];
        abort_if(empty($state['name']), 422, 'Complete the identity step first.');
        abort_if(empty($state['source_type']), 422, 'Choose a source connector first.');

        if ($session->project_id) {
            $project = $session->project;
        } else {
            $project = self::createImportProjectShell($session, 'Imported via onboarding wizard (read-only source, no migration)');
            $session->update(['project_id' => $project->id]);
        }

        return ['project' => $project];
    }

    /** The connector chosen in the wizard (throws when unset/unknown). */
    public static function connectorForSession(OnboardingSession $session): \App\Services\ControlPlane\Connectors\Contracts\SourceConnector
    {
        $key = (string) ($session->state['source_type'] ?? '');

        return \App\Services\ControlPlane\Connectors\ConnectorRegistry::instance()->sourceConnector($key);
    }

    protected static function createImportProjectShell(OnboardingSession $session, string $notes): Project
    {
        $state = $session->state ?? [];
        $project = Project::create([
            'name' => $state['name'],
            'slug' => $state['slug'] ?? Str::slug($state['name']),
            'status' => 'active',
            'environment' => 'development',
            'api_version' => 'v1',
            'notes' => $notes,
        ]);
        EnvironmentService::ensureDefaults($project);

        return $project;
    }

    public static function start(int $userId, string $flow, array $state = []): OnboardingSession
    {
        abort_unless(in_array($flow, ['create', 'import'], true), 422, 'Unknown onboarding flow');
        $session = OnboardingSession::create([
            'user_id' => $userId,
            'flow' => $flow,
            'status' => 'in_progress',
            'current_step' => 0,
            'state' => $state,
        ]);
        AdminAudit::record('ONBOARDING_STARTED', null, 'onboarding_session', $session->id, ['flow' => $flow]);

        return $session;
    }

    public static function resume(int $userId): ?OnboardingSession
    {
        return OnboardingSession::where('user_id', $userId)
            ->where('status', 'in_progress')
            ->orderByDesc('id')->first();
    }

    public static function saveStep(OnboardingSession $session, int $step, array $state): OnboardingSession
    {
        $session->update([
            'current_step' => $step,
            'state' => array_merge($session->state ?? [], $state),
        ]);

        return $session;
    }

    /** Final confirmation of the CREATE flow — creates the project now. */
    public static function completeCreate(OnboardingSession $session): Project
    {
        abort_if($session->flow !== 'create', 422, 'Not a create-flow session.');
        $state = $session->state ?? [];

        $project = Project::create([
            'name' => $state['name'],
            'slug' => $state['slug'] ?? Str::slug($state['name']),
            'status' => 'active',
            'environment' => $state['environment'] ?? 'development',
            'api_domain' => $state['api_domain'] ?? null,
            'api_version' => $state['api_version'] ?? 'v1',
            'db_name' => $state['db_name'] ?? null,
            'redis_prefix' => $state['redis_prefix'] ?? null,
            'notes' => 'Onboarded via Phase 24 wizard',
        ]);

        EnvironmentService::ensureDefaults($project);
        foreach ($state['secrets'] ?? [] as $secret) {
            if (! empty($secret['name']) && isset($secret['value'])) {
                SecretVaultService::createSecret($project, $secret['name'], $secret['value'], [
                    'category' => $secret['category'] ?? 'application',
                ]);
            }
        }

        $session->update(['project_id' => $project->id, 'status' => 'completed', 'current_step' => count(self::CREATE_STEPS) - 1]);
        AdminAudit::record('ONBOARDING_COMPLETED', $project, 'onboarding_session', $session->id, ['flow' => 'create']);

        return $project;
    }

    /** Final confirmation of the IMPORT flow — project shell + migration source. */
    public static function completeImport(OnboardingSession $session): array
    {
        abort_if($session->flow !== 'import', 422, 'Not an import-flow session.');
        $state = $session->state ?? [];

        if ($session->project_id) {
            // Phase 25 flow: draft was prepared at project selection.
            $project = $session->project;
        } else {
            $project = Project::create([
                'name' => $state['name'],
                'slug' => $state['slug'] ?? Str::slug($state['name']),
                'status' => 'active',
                'environment' => 'development',
                'api_version' => 'v1',
                'notes' => 'Imported via onboarding wizard (read-only source)',
            ]);
            EnvironmentService::ensureDefaults($project);
        }

        $source = \App\Models\MigrationSource::where('project_id', $project->id)->orderByDesc('id')->first();
        if (! $source && ! empty($state['source_type'])) {
            // Phase 27D.2 — create the source through the chosen connector
            // (any registered connector works; no provider branching).
            $source = self::connectorForSession($session)->createSourceProfile($project, [], array_merge(
                (array) ($state['source_connection'] ?? []),
                ['display_name' => $state['source_display_name'] ?? ($state['name'].' source')]
            ), EnvironmentService::defaultFor($project)->id);
            foreach ((array) ($state['source_secret_refs'] ?? []) as $fieldKey => $secretName) {
                $source->update(['secret_refs' => array_merge($source->secret_refs ?? [], [$fieldKey => $secretName])]);
            }
        }

        $session->update(['status' => 'completed', 'current_step' => count(self::IMPORT_STEPS) - 1]);
        AdminAudit::record('ONBOARDING_COMPLETED', $project, 'onboarding_session', $session->id, ['flow' => 'import']);

        return ['project' => $project, 'source' => $source];
    }

    public static function abandon(OnboardingSession $session): void
    {
        $session->update(['status' => 'abandoned']);
    }
}
