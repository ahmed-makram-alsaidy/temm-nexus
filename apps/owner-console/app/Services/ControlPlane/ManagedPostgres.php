<?php

namespace App\Services\ControlPlane;

use PDO;

/** Narrow, server-only DDL boundary; passwords never occur in SQL or exceptions. */
class ManagedPostgres
{
    private ?PDO $admin = null;

    public function coordinates(): array
    {
        return array_intersect_key(config('managed-database'), array_flip(['host', 'port', 'sslmode']));
    }

    public static function identifier(string $value): string
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $value)) {
            throw new \RuntimeException(__('connections.invalid_identifier'));
        }

        return '"'.$value.'"';
    }

    public function locked(string $key, callable $action): mixed
    {
        $pdo = $this->admin();
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

    public function catalog(string $database, string $role): array
    {
        $pdo = $this->admin();
        $db = $pdo->prepare("SELECT pg_get_userbyid(datdba) AS owner, shobj_description(oid, 'pg_database') AS marker FROM pg_database WHERE datname = ?");
        $db->execute([$database]);
        $user = $pdo->prepare("SELECT rolcanlogin, rolsuper, rolcreatedb, rolcreaterole, rolreplication, shobj_description(oid, 'pg_authid') AS marker FROM pg_roles WHERE rolname = ?");
        $user->execute([$role]);

        return ['database' => $db->fetch(PDO::FETCH_ASSOC) ?: null, 'role' => $user->fetch(PDO::FETCH_ASSOC) ?: null];
    }

    public function createRole(string $role, string $password, string $marker): void
    {
        $pdo = $this->admin();
        $name = self::identifier($role);
        $pdo->beginTransaction();
        try {
            // A SCRAM verifier prevents statement logs from containing the plaintext password.
            $verifier = self::scramVerifier($password);
            $pdo->exec('CREATE ROLE '.$name.' LOGIN PASSWORD '.$pdo->quote($verifier).' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION');
            $pdo->exec('COMMENT ON ROLE '.$name.' IS '.$pdo->quote($marker));
            $pdo->commit();
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new \RuntimeException(__('connections.provision_failed'));
        }
    }

    public function createDatabase(string $database, string $role, string $marker): void
    {
        $pdo = $this->admin();
        $db = self::identifier($database);
        $user = self::identifier($role);
        try {
            $pdo->exec('CREATE DATABASE '.$db.' OWNER '.$user." ENCODING 'UTF8'");
            $pdo->exec('COMMENT ON DATABASE '.$db.' IS '.$pdo->quote($marker));
        } catch (\Throwable) {
            throw new \RuntimeException(__('connections.provision_failed'));
        }
    }

    /** Idempotent grants only on a database proven to be created by this provisioner. */
    public function secureDatabase(string $database, string $role): void
    {
        $pdo = $this->admin();
        $db = self::identifier($database);
        $user = self::identifier($role);
        try {
            $pdo->exec('REVOKE CREATE, CONNECT ON DATABASE '.$db.' FROM PUBLIC');
            $pdo->exec('GRANT CONNECT ON DATABASE '.$db.' TO '.$user);
            $schema = $this->connect(config('managed-database') + ['database' => $database]);
            $schema->exec('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
            $schema->exec('GRANT USAGE, CREATE ON SCHEMA public TO '.$user);
        } catch (\Throwable) {
            throw new \RuntimeException(__('connections.provision_failed'));
        }
    }

    public function verify(array $connection): bool
    {
        try {
            return (int) $this->connect($connection)->query('SELECT 1')->fetchColumn() === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function connect(array $configuration): PDO
    {
        // Values are validated separately from SQL identifiers and bound credentials.
        foreach (['host', 'database'] as $field) {
            if (! filled($configuration[$field] ?? null) || preg_match('/[;\s]/', (string) $configuration[$field])) {
                throw new \RuntimeException(__('connections.invalid_configuration'));
            }
        }
        $port = filter_var($configuration['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $sslmode = $configuration['sslmode'] ?? 'prefer';
        if (! $port || ! in_array($sslmode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            throw new \RuntimeException(__('connections.invalid_configuration'));
        }

        return new PDO('pgsql:host='.$configuration['host'].';port='.$port.';dbname='.$configuration['database'].';sslmode='.$sslmode.';connect_timeout=5',
            $configuration['username'], $configuration['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function admin(): PDO
    {
        if ($this->admin) {
            return $this->admin;
        }
        if (! filled(config('managed-database.password'))) {
            throw new \RuntimeException(__('connections.admin_unavailable'));
        }
        try {
            return $this->admin = $this->connect(config('managed-database') + ['database' => 'postgres']);
        } catch (\Throwable) {
            throw new \RuntimeException(__('connections.admin_unavailable'));
        }
    }

    private static function scramVerifier(string $password): string
    {
        $salt = random_bytes(16);
        $key = hash_pbkdf2('sha256', $password, $salt, 4096, 32, true);
        $client = hash_hmac('sha256', 'Client Key', $key, true);

        return 'SCRAM-SHA-256$4096:'.base64_encode($salt).'$'.base64_encode(hash('sha256', $client, true)).':'.base64_encode(hash_hmac('sha256', 'Server Key', $key, true));
    }
}
