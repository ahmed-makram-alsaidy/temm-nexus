<?php

namespace Tests\Feature\Phase20;

use App\Services\ControlPlane\SqlGuard;
use Tests\TestCase;

class SqlGuardTest extends TestCase
{
    public function test_allows_select_join_explain(): void
    {
        foreach ([
            'select * from users limit 10',
            'WITH a AS (SELECT 1) SELECT * FROM a',
            'SELECT u.* FROM users u JOIN orders o ON o.user_id = u.id',
            'EXPLAIN SELECT * FROM users',
            'SHOW server_version',
        ] as $sql) {
            $c = SqlGuard::classify($sql);
            $this->assertSame('read', $c['category'], $sql);
            $this->assertNull($c['blocked'], $sql);
        }
    }

    public function test_blocks_writes_without_write_mode(): void
    {
        foreach (['INSERT INTO t (a) VALUES (1)', 'UPDATE t SET a = 1', 'DELETE FROM t WHERE id = 1'] as $sql) {
            $c = SqlGuard::classify($sql, false);
            $this->assertSame('blocked', $c['category'], $sql);
        }
    }

    public function test_allows_writes_in_write_mode_with_where_flags(): void
    {
        $c = SqlGuard::classify('UPDATE t SET a = 1 WHERE id = 2', true);
        $this->assertSame('write', $c['category']);
        $this->assertFalse($c['destructive']);

        $c = SqlGuard::classify('DELETE FROM t', true);
        $this->assertTrue($c['destructive']);
    }

    public function test_blocks_server_vectors_in_every_mode(): void
    {
        foreach ([
            "COPY t FROM PROGRAM 'id'",
            "SELECT pg_read_file('x')",
            'SELECT pg_terminate_backend(1)',
            'CREATE ROLE hacker LOGIN',
            'GRANT ALL ON t TO x',
            'VACUUM t',
            'DO $$ BEGIN END $$',
            'LISTEN x',
        ] as $sql) {
            $this->assertSame('blocked', SqlGuard::classify($sql, true)['category'], $sql);
        }
    }

    public function test_blocks_drop_truncate_transaction_control(): void
    {
        foreach (['DROP TABLE t', 'TRUNCATE t', 'BEGIN; SELECT 1', 'SELECT 1; SELECT 2; SELECT 3; SELECT 4; SELECT 5; SELECT 6'] as $sql) {
            $this->assertSame('blocked', SqlGuard::classify($sql, true)['category'], $sql);
        }
    }

    public function test_redact_removes_literals(): void
    {
        $redacted = SqlGuard::redact("SELECT * FROM users WHERE email = 'a@b.com' AND id = 42");
        $this->assertStringNotContainsString('a@b.com', $redacted);
        $this->assertStringNotContainsString('42', $redacted);
    }
}
