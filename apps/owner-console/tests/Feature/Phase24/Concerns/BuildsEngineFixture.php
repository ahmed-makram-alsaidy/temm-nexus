<?php

namespace Tests\Feature\Phase24\Concerns;

use App\Models\Project;
use App\Models\User;

/**
 * Shared fixture for Phase 24 tests: admin user + projects + the generic
 * migration-engine sqlite fixture (a small Supabase-shaped source dataset
 * with FK dependencies, auth users, storage tables and Arabic/UTF-8 data).
 */
trait BuildsEngineFixture
{
    protected User $admin;
    protected Project $projectA;
    protected Project $projectB;

    protected function buildBase(): void
    {
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->projectA = Project::create([
            'name' => 'Fixture A', 'slug' => 'fixture-a', 'status' => 'active',
            'api_domain' => 'api.fixture-a.test', 'api_version' => 'v1', 'db_name' => 'fixture_a_db',
        ]);
        $this->projectB = Project::create([
            'name' => 'Fixture B', 'slug' => 'fixture-b', 'status' => 'active',
            'api_domain' => 'api.fixture-b.test', 'api_version' => 'v1', 'db_name' => 'fixture_b_db',
        ]);
    }

    /** Create the generic engine fixture sqlite source database. */
    protected function buildSqliteSource(string $path): string
    {
        if (file_exists($path)) {
            unlink($path);
        }
        $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE company (id INTEGER PRIMARY KEY, name TEXT NOT NULL, status TEXT)');
        $pdo->exec('CREATE TABLE branch (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL REFERENCES company(id), title TEXT)');
        $pdo->exec('CREATE TABLE shipment (id INTEGER PRIMARY KEY, branch_id INTEGER NOT NULL REFERENCES branch(id), ref TEXT, cod_amount NUMERIC, note TEXT, created_at TEXT)');
        $pdo->exec("CREATE VIEW pending_shipments AS SELECT id, ref FROM shipment WHERE ref IS NOT NULL");
        // Supabase-shaped domain tables (fixture convention).
        $pdo->exec('CREATE TABLE auth_users (id TEXT PRIMARY KEY, email TEXT, encrypted_password TEXT, created_at TEXT)');
        $pdo->exec('CREATE TABLE storage_buckets (name TEXT PRIMARY KEY, public INTEGER)');
        $pdo->exec('CREATE TABLE storage_objects (id INTEGER PRIMARY KEY, bucket_id TEXT, path TEXT)');

        $pdo->exec("INSERT INTO company (name, status) VALUES ('القاهرة للتوصيل', 'active'), ('alexandria-logistics', 'active')");
        $pdo->exec("INSERT INTO branch (company_id, title) VALUES (1, 'إدارة الشحنات'), (2, 'وصلة فرع')");
        $pdo->exec("INSERT INTO shipment (branch_id, ref, cod_amount, note, created_at) VALUES
            (1, 'DEMO-RH-D3E51EA6', 750.00, 'العربية — القاهرة — الإسكندرية', '2026-09-01 10:00:00'),
            (2, 'DEMO-RH-B2A11CC9', 60.00, 'وصلة نص ثنائي', '2026-09-02 11:30:00')");
        $pdo->exec("INSERT INTO auth_users (id, email, encrypted_password, created_at) VALUES
            ('11111111-1111-4111-8111-111111111111', 'admin@fixture.test', '\$2a\$10\$abcdefghijklmnopqrstuvABCDEFGHijklmnopqrstuVWXYz0123456789012', '2026-01-01 00:00:00'),
            ('22222222-2222-4222-8222-222222222222', 'courier@fixture.test', '\$2a\$06\$bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', '2026-01-02 00:00:00')");
        $pdo->exec("INSERT INTO storage_buckets (name, public) VALUES ('shipment_images', 0), ('company_logos', 1)");
        $pdo->exec("INSERT INTO storage_objects (bucket_id, path) VALUES ('shipment_images', '2026/09/a.png'), ('shipment_images', '2026/09/b.png')");

        return $path;
    }

    protected function tempPath(string $name): string
    {
        $dir = storage_path('framework/testing/phase24');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir.'/'.$name;
    }
}
