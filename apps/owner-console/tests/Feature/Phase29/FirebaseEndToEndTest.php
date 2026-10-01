<?php

namespace Tests\Feature\Phase29;

use App\Models\MigrationSource;
use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use App\Services\ControlPlane\Migration\MigrationRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 29J — the Firebase connector works END TO END through the generic
 * Migration Center using the synthetic sandbox project (no real Firebase,
 * no containers): register → configure → analyze → plan → migrate (sqlite
 * disposable target) → validate. Proves the core consumes Firebase through
 * normalized artifacts only (29A architecture rule).
 */
class FirebaseEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSource(): MigrationSource
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $project = Project::create([
            'name' => 'P29 E2E', 'slug' => 'p29-e2e-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p29e2e.test', 'api_version' => 'v1',
        ]);
        $connector = ConnectorRegistry::instance()->sourceConnector('firebase');

        return $connector->createSourceProfile($project, [], [
            'project_id' => 'temm-dogfood-sandbox',
            'transport' => 'fixture',
            'display_name' => 'Firebase sandbox source',
        ]);
    }

    public function test_full_pipeline_analyze_plan_migrate_validate(): void
    {
        $source = $this->makeSource();

        // analyze — normalized inventory through the connector.
        $service = new MigrationCenterService;
        $analysis = $service->analyze($source);
        $this->assertSame('completed', $analysis->status, json_encode($analysis->errors));
        $service->classify($analysis);

        $counts = $analysis->counts;
        $this->assertGreaterThan(3, $counts['tables'], 'collections + subcollection + child tables planned');
        $this->assertSame(4, $counts['auth'], 'auth domain present (29F)');

        // plan — generic engine, FK-dependency ordered.
        $plan = $service->generatePlan($analysis);
        $this->assertSame('draft', $plan->status);
        $tableItems = $plan->items()->where('source_kind', 'table')->get();
        $this->assertGreaterThanOrEqual(4, $tableItems->count());

        // run — rehearsal on a disposable sqlite target.
        $targetPath = storage_path('framework/testing/phase29/targets/firebase-e2e-'.uniqid().'.sqlite');
        if (! is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0777, true);
        }
        $manager = new MigrationRunManager;
        $run = $manager->start($plan->fresh(), [
            'mode' => 'rehearsal',
            'target' => ['driver' => 'sqlite', 'path' => $targetPath, 'recreate' => true, 'disposable' => true, 'environment_type' => 'development'],
            'target_disposable' => true,
            'reset' => true,
            'target_environment_type' => 'development',
        ]);
        $manager->execute($run);
        $run = $run->fresh();
        $itemErrors = $run->items()->where('status', 'failed')->pluck('error')->all();
        $this->assertSame('completed', $run->status, 'run failure: '.json_encode($run->failure).' item errors: '.json_encode($itemErrors));

        // Records actually landed in the target — users, products, orders,
        // exploded items, subcollection timeline.
        $pdo = new \PDO('sqlite:'.$targetPath);
        $this->assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM orders__items')->fetchColumn());
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM orders__timeline')->fetchColumn());

        // Arabic UTF-8 round-trips byte-exact (29J acceptance).
        $arabic = (string) $pdo->query("SELECT title FROM products WHERE _id = 'p001'")->fetchColumn();
        $this->assertSame('كتاب البرمجة', $arabic);

        // Reference values project to the referenced DOCUMENT ID so explicit
        // FKs hold; the full path stays in the canonical JSON payload.
        $productRef = (string) $pdo->query("SELECT product_ref FROM orders WHERE _id = 'o001'")->fetchColumn();
        $this->assertSame('p001', $productRef);

        // validate — generic validator suite over the same plan.
        $results = $manager->validate($run);
        foreach ($results as $validator => $result) {
            $this->assertContains($result['status'], ['pass', 'warn', 'skipped'], "{$validator}: ".json_encode($result));
        }

        // *_id naming relationships hold in the target (HIGH_CONFIDENCE →
        // advisory, but data parity makes the join valid).
        $orphans = (int) $pdo->query('SELECT COUNT(*) FROM orders o LEFT JOIN users u ON u._id = o.user_id WHERE u._id IS NULL')->fetchColumn();
        $this->assertSame(0, $orphans, 'no orphans: orders.user_id ↔ users._id');
    }

    public function test_connector_validation_artifacts_are_honest(): void
    {
        $source = $this->makeSource();
        $connector = ConnectorRegistry::instance()->sourceConnector('firebase');
        $artifacts = $connector->validateSource($source);

        $this->assertSame('connector_validation', $artifacts['kind']);
        $this->assertSame('firebase', $artifacts['connector_key']);
        $this->assertSame('temm-dogfood-sandbox', $artifacts['project']);
        $this->assertSame(4, $artifacts['row_counts']['users']);
        $this->assertSame(3, $artifacts['row_counts']['orders__items']);
        $this->assertSame('NEEDS_REVIEW', $artifacts['auth_password_compatibility'], 'password compat never faked');
        $this->assertStringContainsString('inferred', $artifacts['inferred_schema_note']);
        $this->assertSame(5, $artifacts['storage_objects']);
        $this->assertSame(4, $artifacts['functions']);
        $this->assertTrue($artifacts['functions_available']);
    }

    public function test_core_has_no_firebase_branching(): void
    {
        // 29 architecture rule — the generic core never mentions Firebase.
        $coreDirs = [
            app_path('Services/ControlPlane/Migration'),
            app_path('Services/ControlPlane/Connectors'),
        ];
        foreach ($coreDirs as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! str_ends_with($file->getPathname(), '.php')) {
                    continue;
                }
                $content = (string) file_get_contents($file->getPathname());
                // Strip comments — historical prose may mention future
                // connectors; the rule bans CODE references (29 architecture).
                $withoutBlock = preg_replace('#/\*.*?\*/#s', '', $content) ?? $content;
                $code = preg_replace('#^\s*//.*$#m', '', $withoutBlock) ?? $withoutBlock;
                $this->assertStringNotContainsStringIgnoringCase('firebase', $code, 'core file must not reference Firebase in code: '.$file->getPathname());
            }
        }
    }
}
