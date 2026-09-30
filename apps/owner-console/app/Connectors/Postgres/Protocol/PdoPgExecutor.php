<?php

namespace App\Connectors\Postgres\Protocol;

use App\Services\ControlPlane\Connectors\Support\ConnectorNetworkGuard;

/**
 * Phase 30A — production executor: PDO pgsql with a HARD read-only session.
 *
 * On connect the session is pinned with default_transaction_read_only=on AND
 * transaction_read_only=on — even WRITE-capable credentials cannot mutate
 * the source through this executor. The pin is VERIFIED by reading the
 * effective setting back (30A: enforced, not assumed). The SQL surface is
 * allowlisted to read shapes as defense in depth.
 */
class PdoPgExecutor implements PgExecutor
{
    protected ?\PDO $pdo = null;

    public function __construct(
        protected string $host,
        protected int $port,
        protected string $database,
        protected string $username,
        protected string $password,
        protected string $sslMode = 'prefer',
        protected int $timeoutSeconds = 15,
    ) {
    }

    /** Build the executor after guarding the target host. */
    public static function forSource(array $config, string $username, string $password, bool $localAllowed): self
    {
        $host = (string) ($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 5432);
        if ($host === '') {
            throw new \InvalidArgumentException('postgres connector requires a host.');
        }
        ConnectorNetworkGuard::assertSafeHost($host, $port, $localAllowed);

        return new self(
            $host,
            $port,
            (string) ($config['database'] ?? ''),
            $username,
            $password,
            (string) ($config['ssl_mode'] ?? 'prefer'),
        );
    }

    public function name(): string
    {
        return 'pdo';
    }

    public function connect(): void
    {
        if ($this->pdo !== null) {
            return;
        }
        if ($this->database === '') {
            throw new \InvalidArgumentException('postgres connector requires a database name.');
        }
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=%d',
            $this->host,
            $this->port,
            $this->database,
            $this->sslMode,
            $this->timeoutSeconds
        );
        $this->pdo = new \PDO($dsn, $this->username, $this->password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        // 30A — HARD read-only session, then verify the pin took effect.
        $this->pdo->exec('SET SESSION default_transaction_read_only = on');
        $this->pdo->exec('SET SESSION transaction_read_only = on');
        $this->pdo->exec("SET SESSION application_name = 'temm-nexus-connector'");
        $effective = $this->pdo->query('SHOW transaction_read_only')->fetchColumn();
        if ($effective !== 'on') {
            throw new \RuntimeException('postgres source session could not be pinned read-only — refusing to continue (30A).');
        }
    }

    /** Effective read-only state of the session (for honest health reports). */
    public function readOnlyConfirmed(): bool
    {
        $this->connect();

        return $this->pdo->query('SHOW transaction_read_only')->fetchColumn() === 'on';
    }

    public function rows(string $sql, array $bindings = []): array
    {
        $this->connect();
        self::assertReadSql($sql);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn ($row) => array_change_key_case((array) $row, CASE_LOWER), $rows);
    }

    public function scalar(string $sql, array $bindings = []): mixed
    {
        $this->connect();
        self::assertReadSql($sql);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    /** Defense in depth: only read-shaped statements ever reach the server. */
    protected static function assertReadSql(string $sql): void
    {
        $head = ltrim(preg_replace('/\s+/', ' ', $sql) ?? '');
        foreach (['SELECT ', 'SHOW ', 'WITH '] as $allowed) {
            if (stripos($head, $allowed) === 0 || stripos($head, ltrim($allowed)) === 0) {
                return;
            }
        }
        throw new \LogicException('postgres connector only issues read statements (30A): '.mb_substr($head, 0, 60));
    }
}
