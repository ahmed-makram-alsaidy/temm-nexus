<?php

namespace Tests\Feature\Phase29;

use App\Connectors\Firebase\FirebaseSourceAdapter;
use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 29C/29D — Firestore analysis over the synthetic sandbox project:
 * schema inference, observed Firestore types, Arabic UTF-8, nested maps,
 * arrays, subcollections and relationship candidates with honest
 * confidence classes.
 */
class FirebaseAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function makeAdapter(): FirebaseSourceAdapter
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $project = Project::create([
            'name' => 'P29 Analysis', 'slug' => 'p29-analysis-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p29a.test', 'api_version' => 'v1',
        ]);
        $connector = ConnectorRegistry::instance()->sourceConnector('firebase');
        $source = $connector->createSourceProfile($project, [], [
            'project_id' => 'temm-dogfood-sandbox',
            'transport' => 'fixture',
            'display_name' => 'Firebase fixture',
        ]);
        $source->refresh();

        return new FirebaseSourceAdapter($source);
    }

    public function test_inventory_normalizes_collections_into_tables(): void
    {
        $inventory = $this->makeAdapter()->inventory();
        $this->assertSame(['default'], $inventory['schemas'], '(default) normalizes to the default schema');

        $names = collect($inventory['tables'])->pluck('name')->all();
        $this->assertContains('users', $names);
        $this->assertContains('products', $names);
        $this->assertContains('orders', $names);
        $this->assertContains('orders__timeline', $names, 'observed subcollection becomes a table');

        $users = collect($inventory['tables'])->firstWhere('name', 'users');
        $this->assertSame(['_id'], $users['primary_key']);
        $this->assertSame(4, $users['row_estimate']);
    }

    public function test_schema_inference_reports_observed_types_and_missingness(): void
    {
        $adapter = $this->makeAdapter();
        $inventory = $adapter->inventory();
        $firebase = $inventory['firebase'];
        $this->assertStringContainsString('no authoritative relational schema', $firebase['inference']['note'], '29C — never claims Firestore has a real schema');

        $usersInf = null;
        foreach ($inventory['tables'] as $table) {
            if ($table['name'] === 'users') {
                $usersInf = $table;
            }
        }
        $columns = collect($usersInf['columns'])->keyBy('name');
        $this->assertSame('text', $columns['name']['type'], 'mixed null/string → text, nullable');
        $this->assertTrue((bool) $columns['name']['nullable'], 'null observed on u003');
        $this->assertSame('int8', $columns['age']['type']);
        $this->assertSame('boolean', $columns['active']['type']);
        $this->assertSame('timestamptz', $columns['created_at']['type']);
        $this->assertSame('jsonb', $columns['address']['type'], 'nested map → jsonb');
        $this->assertSame('text', $columns['avatar']['type'], 'bytes preserved with bin64 marker');
        $this->assertSame('jsonb', $columns['tags']['type'], 'scalar array → jsonb');
    }

    public function test_arabic_utf8_is_preserved_in_analysis_and_extraction(): void
    {
        $adapter = $this->makeAdapter();
        $rows = [];
        $adapter->streamRows('default', 'products', [], function ($row) use (&$rows) {
            $rows[] = $row;
        });
        $titles = array_column($rows, 'title');
        $this->assertContains('كتاب البرمجة', $titles, 'Arabic document text survives the REST parsing path');
        $this->assertContains('قلم أزرق', $titles);
        $orderRows = [];
        $adapter->streamRows('default', 'orders', [], function ($row) use (&$orderRows) {
            $orderRows[] = $row;
        });
        $this->assertContains('توصيل سريع للمنطقة الشمالية', array_column($orderRows, 'notes'));
    }

    public function test_relationship_candidates_use_honest_confidence_classes(): void
    {
        $inventory = $this->makeAdapter()->inventory();
        $candidates = collect($inventory['firebase']['relationships']);

        // EXPLICIT — DocumentReference field (orders.product_ref → products).
        $explicit = $candidates->first(fn ($c) => $c['collection'] === 'orders' && $c['path'] === 'product_ref');
        $this->assertNotNull($explicit, 'DocumentReference must be detected');
        $this->assertSame('EXPLICIT', $explicit['confidence']);
        $this->assertSame('products', $explicit['references']);
        $this->assertTrue((bool) $explicit['auto_fk'], 'strong mappings may become proposed FK candidates');

        // HIGH_CONFIDENCE — orders.user_id matches users document ids.
        $high = $candidates->first(fn ($c) => $c['collection'] === 'orders' && $c['path'] === 'user_id');
        $this->assertNotNull($high, '*_id pattern must be detected');
        $this->assertSame('HIGH_CONFIDENCE', $high['confidence']);
        $this->assertSame('users', $high['references']);
        $this->assertFalse((bool) $high['auto_fk'], 'naming-based candidates stay advisory (29D)');

        // UNKNOWN — an *_id field with no observable target: child items'
        // product_id is inside an array (advisory), and orders has none other;
        // assert at least every auto_fk candidate is EXPLICIT.
        foreach ($candidates->all() as $candidate) {
            if ($candidate['auto_fk']) {
                $this->assertSame('EXPLICIT', $candidate['confidence']);
            }
        }
    }

    public function test_subcollection_becomes_explicit_child_table(): void
    {
        $inventory = $this->makeAdapter()->inventory();
        $timeline = collect($inventory['tables'])->firstWhere('name', 'orders__timeline');
        $this->assertNotNull($timeline);
        $fk = $timeline['foreign_keys'][0];
        $this->assertSame('parent_id', $fk['column']);
        $this->assertSame('orders', $fk['references_table']);
        $this->assertSame('EXPLICIT', $fk['confidence'], 'subcollection parent linkage is structural (29D)');
        $this->assertTrue($timeline['is_subcollection']);
    }

    public function test_array_of_maps_becomes_derived_child_table(): void
    {
        $inventory = $this->makeAdapter()->inventory();
        $items = collect($inventory['tables'])->firstWhere('name', 'orders__items');
        $this->assertNotNull($items, 'orders.items[] derives a child table');
        $this->assertSame('ARRAY_CHILD_TABLE', $items['migration_strategy']);
        $this->assertSame('EXPLICIT', $items['foreign_keys'][0]['confidence']);
        $columns = collect($items['columns'])->pluck('name')->all();
        $this->assertContains('product_id', $columns);
        $this->assertContains('qty', $columns);
        $this->assertContains('unit_price', $columns);
    }

    public function test_strategy_decision_is_deterministic_and_explainable(): void
    {
        $inventory = $this->makeAdapter()->inventory();
        $orders = collect($inventory['tables'])->firstWhere('name', 'orders');
        $this->assertSame('HYBRID', $orders['migration_strategy'], 'orders has an array-of-map field');
        $products = collect($inventory['tables'])->firstWhere('name', 'products');
        $this->assertSame('RELATIONAL_TABLE', $products['migration_strategy']);
        $this->assertStringContainsString('29C', $products['strategy_reason']);
    }

    public function test_fingerprint_is_deterministic(): void
    {
        $first = $this->makeAdapter()->fingerprint();
        $second = $this->makeAdapter()->fingerprint();
        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
    }

    public function test_auth_storage_functions_sections_are_present_and_honest(): void
    {
        $inventory = $this->makeAdapter()->inventory();
        $firebase = $inventory['firebase'];

        $this->assertTrue($inventory['auth']['present']);
        $this->assertSame(4, $inventory['auth']['users_count']);
        $this->assertContains('password', $inventory['auth']['providers']);
        $this->assertSame('not_exposed_by_listing', $inventory['auth']['hash_strategy']);

        $this->assertTrue($inventory['storage']['present']);
        $this->assertSame(5, $inventory['storage']['buckets'][0]['files']);

        $this->assertTrue($firebase['functions']['available']);
        $this->assertSame(4, $firebase['functions']['count']);
        $this->assertSame(4, count($inventory['edge_functions']), 'functions surface in the normalized inventory');
    }
}
