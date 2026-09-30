<?php

namespace App\Connectors\Mysql\Protocol;

use App\Services\ControlPlane\Connectors\Support\ConnectorNetworkGuard;

/**
 * Phase 31A — production executor: PDO mysql with a HARD read-only session.
 *
 * The session is pinned with transaction_read_only=on (MySQL 8+ and
 * MariaDB) and the pin is verified by reading @@SESSION.transaction_read_only
 * back. The SQL surface is allowlisted to read shapes as defense in depth.
 */
class PdoMysqlExecutor implements MysqlExecutor
{
    protected ?\PDO $pdo = null;

    public function __construct(
        protected string $host,
        protected int $port,
        protected string $database,
        protected string $username,
        protected string $password,
        protected int $timeoutSeconds = 15,
    ) {
    }

    /** Build the executor after guarding the target host. */
    public static function forSource(array $config, string $username, string $password, bool $localAllowed): self
    {
        $host = (string) ($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 3306);
        if ($host === '') {
            throw new \InvalidArgumentException('mysql connector requires a host.');
        }
        ConnectorNetworkGuard::assertSafeHost($host, $port, $localAllowed);

        return new self(
            $host,
            $port,
            (string) ($config['database'] ?? ''),
            $username,
            $password,
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
            throw new \InvalidArgumentException('mysql connector requires a database name.');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $this->database
        );
        $this->pdo = new \PDO($dsn, $this->username, $this->password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => $this->timeoutSeconds,
        ]);
        // 31 — HARD read-only session (MySQL 8+ and MariaDB), then verify.
        $this->pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $this->pdo->exec("SET SESSION application_name = 'temm-nexus-connector'");
        $effective = $this->pdo->query('SELECT @@SESSION.transaction_read_only')->fetchColumn();
        if (! in_array((string) $effective, ['1', 'on', 'true'], true)) {
            throw new \RuntimeException('mysql source session could not be pinned read-only — refusing to continue (31).');
        }
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
        foreach (['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN'] as $allowed) {
            if (stripos($head, $allowed) === 0) {
                return;
            }
        }
        throw new \LogicException('mysql connector only issues read statements (31): '.mb_substr($head, 0, 60));
    }
}
