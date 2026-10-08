<?php

namespace App\Services\ControlPlane;

use App\Filament\Support\PlatformAccess;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectSecret;
use App\Services\Access\Capability;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProjectDatabaseProvisioner
{
    public function __construct(private ManagedPostgres $postgres) {}

    public static function canManage(Project $project): bool
    {
        return PlatformAccess::current()->allowsProject(Capability::INFRASTRUCTURE_MANAGE, $project)
            && PlatformAccess::current()->allowsProject(Capability::SECRETS_MANAGE, $project);
    }

    private function authorize(Project $project, ProjectEnvironment $environment): void
    {
        abort_unless(self::canManage($project), 403);
        abort_unless($environment->project_id === $project->id, 404);
        abort_unless($environment->status === 'active', 422, __('connections.environment_inactive'));
    }

    public function provision(Project $project, ?ProjectEnvironment $environment = null): ProjectEnvironment
    {
        $environment ??= EnvironmentContext::active($project);
        $this->authorize($project, $environment);
        AdminAudit::record('PROJECT_DATABASE_PROVISIONING_STARTED', $project, 'environment', $environment->id);

        try {
            return $this->locked($environment, fn () => $this->postgres->locked('temm-db-'.$this->databaseName($project, $environment), function () use ($project, $environment) {
                $environment->refresh();
                $this->authorize($project, $environment);
                if (($environment->config['destination'] ?? null) === 'external') {
                    throw new \RuntimeException(__('connections.external_configure'));
                }
                $database = $this->databaseName($project, $environment);
                $role = $this->shortIdentifier($database.'_user');
                $coordinates = $this->postgres->coordinates();
                $catalog = $this->postgres->catalog($database, $role);
                $intent = $environment->config['database_provisioning'] ?? null;

                if ($environment->database_connection) {
                    $connection = ProjectConnectionManager::configuration($project, $environment);
                    if ($connection['host'] !== $coordinates['host'] || (string) $connection['port'] !== (string) $coordinates['port']) {
                        throw new \RuntimeException(__('connections.existing_conflict'));
                    }
                    if (! $this->postgres->verify($connection)) {
                        throw new \RuntimeException(__('connections.verify_failed'));
                    }
                    AdminAudit::record('PROJECT_DATABASE_RECONCILED', $project, 'environment', $environment->id);

                    return $environment;
                }

                if (! $intent) {
                    // Known, working legacy credentials can be bound without changing existing objects.
                    if ($catalog['database'] || $catalog['role']) {
                        try {
                            $legacy = ProjectConnectionManager::configuration($project, $environment);
                        } catch (\Throwable) {
                            throw new \RuntimeException(__('connections.existing_conflict'));
                        }
                        if ($legacy['host'] !== $coordinates['host'] || (string) $legacy['port'] !== (string) $coordinates['port']
                            || $legacy['database'] !== $database || ! $this->postgres->verify($legacy)) {
                            throw new \RuntimeException(__('connections.existing_conflict'));
                        }

                        return $this->bind($project, $environment, $legacy, 'temm', 'PROJECT_DATABASE_RECONCILED');
                    }
                    // Commit the encrypted retry intent BEFORE DDL (CREATE DATABASE is not transactional).
                    $intent = [
                        'database' => $database, 'username' => $role,
                        'marker' => 'TEMM project='.$project->id.' environment='.$environment->id.' '.bin2hex(random_bytes(16)),
                        'host' => $coordinates['host'], 'port' => $coordinates['port'],
                    ];
                    DB::transaction(function () use ($project, $environment, $intent) {
                        SecretVaultService::createSecret($project, $this->secretName($environment), bin2hex(random_bytes(32)), [
                            'category' => 'database', 'environment_id' => $environment->id,
                        ]);
                        $environment->update(['config' => array_merge($environment->config ?? [], [
                            'destination' => 'temm', 'database_provisioning' => $intent,
                        ])]);
                    });
                }

                if ($intent['database'] !== $database || $intent['username'] !== $role
                    || $intent['host'] !== $coordinates['host'] || (string) $intent['port'] !== (string) $coordinates['port']) {
                    throw new \RuntimeException(__('connections.existing_conflict'));
                }
                $secret = ProjectSecret::where('project_id', $project->id)->where('name', $this->secretName($environment))
                    ->where('environment_id', $environment->id)->where('status', 'active')->first();
                if (! $secret) {
                    throw new \RuntimeException(__('connections.existing_conflict'));
                }
                try {
                    if ($catalog['role']) {
                        $user = $catalog['role'];
                        if ($user['marker'] !== $intent['marker'] || ! $user['rolcanlogin']
                            || $user['rolsuper'] || $user['rolcreatedb'] || $user['rolcreaterole'] || $user['rolreplication']) {
                            throw new \RuntimeException(__('connections.existing_conflict'));
                        }
                    } else {
                        $this->postgres->createRole($role, $secret->value, $intent['marker']);
                    }
                    if ($catalog['database']) {
                        if ($catalog['database']['owner'] !== $role
                            || ($catalog['database']['marker'] !== null && $catalog['database']['marker'] !== $intent['marker'])) {
                            throw new \RuntimeException(__('connections.existing_conflict'));
                        }
                    } else {
                        $this->postgres->createDatabase($database, $role, $intent['marker']);
                    }
                    if (! $catalog['database'] || $catalog['database']['marker'] === $intent['marker']) {
                        $this->postgres->secureDatabase($database, $role);
                    }
                    $connection = $coordinates + ['database' => $database, 'username' => $role, 'password' => $secret->value];
                    if (! $this->postgres->verify($connection)) {
                        throw new \RuntimeException(__('connections.verify_failed'));
                    }
                    $this->saveBinding($project, $environment, $connection, $secret->name, 'temm');
                    AdminAudit::record('PROJECT_DATABASE_PROVISIONED', $project, 'environment', $environment->id, ['database' => $database]);

                    return $environment->fresh();
                } catch (\Throwable) {
                    // Never carry a PDO exception / SQL / password into UI, logs, or audit metadata.
                    throw new \RuntimeException(__('connections.provision_failed'));
                }
            }));
        } catch (\Throwable $error) {
            AdminAudit::record('PROJECT_DATABASE_PROVISIONING_FAILED', $project, 'environment', $environment->id, ['retryable' => true]);
            // Only documented messages may leave the DDL/control-plane boundary.
            $safe = array_map(fn ($key) => __('connections.'.$key), [
                'external_configure', 'existing_conflict', 'verify_failed', 'provision_failed',
                'invalid_identifier', 'admin_unavailable', 'busy', 'environment_inactive',
            ]);
            throw new \RuntimeException(in_array($error->getMessage(), $safe, true)
                ? $error->getMessage() : __('connections.provision_failed'));
        }
    }

    /** Bind verified manual/external credentials; never performs database or role DDL. */
    public function configure(Project $project, ProjectEnvironment $environment, array $connection, string $destination = 'external'): ProjectEnvironment
    {
        $this->authorize($project, $environment);
        abort_unless(in_array($destination, ['external', 'temm'], true), 422);

        return $this->locked($environment, function () use ($project, $environment, $connection, $destination) {
            $environment->refresh();
            $this->authorize($project, $environment);
            if (! $environment->database_connection && ! isset($environment->config['database_provisioning'])) {
                $environment->update(['config' => array_merge($environment->config ?? [], ['destination' => $destination])]);
            }
            if (! $environment->database_connection && isset($environment->config['database_provisioning'])) {
                // Reconcile only the original endpoint/role. Verification never alters PostgreSQL.
                $intent = $environment->config['database_provisioning'];
                foreach (['host', 'port', 'database', 'username'] as $field) {
                    if ((string) ($connection[$field] ?? '') !== (string) $intent[$field]) {
                        throw new \RuntimeException(__('connections.pending_provisioning'));
                    }
                }
            }
            if (! $this->postgres->verify($connection)) {
                throw new \RuntimeException(__('connections.verify_failed'));
            }

            return $this->bind($project, $environment, $connection, $destination, 'PROJECT_DATABASE_CONFIGURED');
        });
    }

    /** Serialize provisioning and manual configuration across requests/workers. */
    private function locked(ProjectEnvironment $environment, callable $action): mixed
    {
        $key = 'temm-db-binding-'.$environment->id;
        $platform = DB::connection();
        if ($platform->getDriverName() !== 'pgsql') {
            $lock = Cache::lock($key, 300);
            if (! $lock->get()) {
                throw new \RuntimeException(__('connections.busy'));
            }
            try {
                return $action();
            } finally {
                $lock->release();
            }
        }
        $pdo = $platform->getPdo();
        $lock = $pdo->prepare('SELECT pg_try_advisory_lock(hashtext(?))::int');
        $lock->execute([$key]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new \RuntimeException(__('connections.busy'));
        }
        try {
            return $action();
        } finally {
            $unlock = $pdo->prepare('SELECT pg_advisory_unlock(hashtext(?))');
            $unlock->execute([$key]);
        }
    }

    private function bind(Project $project, ProjectEnvironment $environment, array $connection, string $destination, string $audit): ProjectEnvironment
    {
        DB::transaction(function () use ($project, $environment, $connection, $destination) {
            $secret = SecretVaultService::createSecret($project, $this->secretName($environment), $connection['password'], [
                'category' => 'database', 'environment_id' => $environment->id,
            ]);
            $this->saveBinding($project, $environment, $connection, $secret->name, $destination);
        });
        AdminAudit::record($audit, $project, 'environment', $environment->id, ['database' => $connection['database']]);

        return $environment->fresh();
    }

    private function saveBinding(Project $project, ProjectEnvironment $environment, array $connection, string $reference, string $destination): void
    {
        $environment->update([
            'database_connection' => array_intersect_key($connection, array_flip(['host', 'port', 'database', 'username', 'sslmode'])),
            'database_secret_ref' => $reference,
            'config' => array_merge($environment->config ?? [], ['destination' => $destination]),
        ]);
        if ($environment->is_default) {
            $project->update(['db_host' => $connection['host'], 'db_port' => $connection['port'], 'db_name' => $connection['database']]);
        }
        ProjectConnectionManager::forget($project, $environment);
    }

    private function secretName(ProjectEnvironment $environment): string
    {
        return 'PROJECT_DB_PASSWORD_'.$environment->id;
    }

    public function databaseName(Project $project, ProjectEnvironment $environment): string
    {
        $base = $project->db_name ?: str_replace('-', '_', $project->slug).'_db';
        $name = $environment->is_default ? $base : $base.'_'.$environment->slug;
        $name = $this->shortIdentifier($name);
        ManagedPostgres::identifier($name);

        return $name;
    }

    private function shortIdentifier(string $name): string
    {
        return strlen($name) > 63 ? substr($name, 0, 50).'_'.substr(hash('sha256', $name), 0, 12) : $name;
    }
}
