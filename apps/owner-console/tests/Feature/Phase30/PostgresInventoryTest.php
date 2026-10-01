<?php

namespace Tests\Feature\Phase30;

use App\Connectors\Postgres\PostgresSourceAdapter;
use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 30B/30C — pg_catalog-native inspection over the synthetic database:
 * full metadata fidelity (views, matviews, sequences with state, partitions,
 * generated/identity columns, enums, domains, RLS policies, extensions) and
 * exact type preservation with honest NEEDS_REVIEW for extension types.
 */
class PostgresInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function makeAdapter(): PostgresSourceAdapter
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $project = Project::create([
            'name' => 'P30 Inventory', 'slug' => 'p30-inv-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p30i.test', 'api_version' => 'v1',
        ]);
        $connector = ConnectorRegistry::instance()->sourceConnector('postgres');
        $source = $connector->createSourceProfile($project, [], [
            'host' => 'fixture', 'database' => 'synthetic_pg', 'username' => 'reader',
            'transport' => 'fixture', 'display_name' => 'Synthetic PG',
        ]);
        $source->refresh();

        return new PostgresSourceAdapter($source);
    }

    public function test_inventory_covers_schemas_tables_views_matviews(): void
    {
        $inventory = $this->makeAdapter()->inventory();

        $this->assertSame(['public', 'reporting'], $inventory['schemas']);
        $names = collect($inventory['tables'])->pluck('name')->all();
        $this->assertContains('customers', $names);
        $this->assertContains('orders', $names);
        $this->assertContains('events_by_month', $names, 'partitioned parent inventoried as a table');
        $this->assertContains('daily_revenue', $names, 'second schema imported');
        $this->assertSame('paid_orders', $inventory['views'][0]['name']);
        $this->assertSame('revenue_by_day', $inventory['matviews'][0]['name'], 'materialized view separated from views');
    }

    public function test_types_are_preserved_exactly_and_normalized(): void
    {
        $inventory = $this->makeAdapter()->inventory();
        $customers = collect($inventory['tables'])->firstWhere('name', 'customers');
        $columns = collect($customers['columns'])->keyBy('name');

        $this->assertSame('numeric(14,2)', $columns['balance']['source_type'], '30C — format_type output preserved verbatim');
        $this->assertSame('numeric(14,2)', $columns['balance']['type'], 'typmod carried into the normalized type');
        $this->assertSame('character varying(180)', $columns['email']['source_type']);
        $this->assertSame('varchar(180)', $columns['email']['type']);
        $this->assertSame('timestamp with time zone', $columns['joined_at']['source_type']);
        $this->assertSame('timestamptz', $columns['joined_at']['type']);
        $this->assertSame('text[]', $columns['tags']['type']);
        $this->assertSame('integer', $columns['id']['source_type']);
        $this->assertSame('int4', $columns['id']['type']);
    }

    public function test_column_semantics_identity_generated_defaults(): void
    {
        $inventory = $this->makeAdapter()->inventory();
        $customers = collect($inventory['tables'])->firstWhere('name', 'customers');
        $columns = collect($customers['columns'])->keyBy('name');

        $this->assertTrue((bool) $columns['id']['is_identity'], 'identity column detected');
        $this->assertTrue((bool) $columns['login_count']['is_generated'], 'generated column detected');
        $this->assertNull($columns['login_count']['default'], 'generated columns carry no copyable default');
        $this->assertSame('0', $columns['balance']['default']);
        $this->assertTrue((bool) $columns['full_name']['nullable'], 'nullable column detected');
        $this->assertFalse((bool) $columns['email']['nullable'], 'NOT NULL preserved');
    }

    public function test_enums_and_domains_are_resolved(): void
    {
        $inventory = $this->makeAdapter()->inventory();

        $levelEnum = collect($inventory['enums'])->firstWhere('name', 'customer_level');
        $this->assertSame(['bronze', 'silver', 'gold'], $levelEnum['values']);
        $orderStatus = collect($inventory['enums'])->firstWhere('name', 'order_status');
        $this->assertContains('paid', $orderStatus['values']);

        $domain = collect($inventory['postgres']['domains'])->firstWhere('typname', 'positive_amount');
        $this->assertSame('numeric(14,2)', $domain['base_type']);

        $customers = collect($inventory['tables'])->firstWhere('name', 'customers');
        $columns = collect($customers['columns'])->keyBy('name');
        $this->assertSame('customer_level', $columns['level']['type'], 'enum column keeps its type name (target ensureEnum)');
        $this->assertNull($columns['level']['domain'], 'enum columns are not domains');
    }

    public function test_extension_types_are_flagged_needs_review(): void
    {
        $inventory = $this->makeAdapter()->inventory();
        $postgres = $inventory['postgres'];

        $this->assertContains('postgis', array_column($postgres['extensions'], 'name'));
        $geometry = collect($inventory['tables'])->firstWhere('name', 'geometries');
        $location = collect($geometry['columns'])->keyBy('name')['location'];
        $this->assertSame('geometry(Point,4326)', $location['source_type']);
        $this->assertSame('text', $location['type'], 'unknown type degrades to text only as a transport');
        $this->assertNotEmpty($postgres['needs_review_types'], '30C — extension types are NEEDS_REVIEW, never silent');
        $this->assertSame('geometry(Point,4326)', $postgres['needs_review_types'][0]['source_type']);
    }

    public function test_partitioning_sequences_functions_and_policies(): void
    {
        $inventory = $this->makeAdapter()->inventory();
        $postgres = $inventory['postgres'];

        // 30B partitioning metadata.
        $events = collect($inventory['tables'])->firstWhere('name', 'events_by_month');
        $this->assertTrue((bool) $events['is_partitioned']);
        $partitionEntry = collect($postgres['partitions'])->firstWhere('table', 'events_by_month');
        $this->assertCount(2, $partitionEntry['partitions']);
        $this->assertStringContainsString('FOR VALUES FROM', $partitionEntry['partitions'][0]['bound']);

        // Sequence state preservation (30D).
        $customersSeq = collect($postgres['sequences'])->firstWhere('sequence_name', 'customers_id_seq');
        $this->assertSame('2', (string) $customersSeq['last_value']);
        $this->assertSame('customers', $customersSeq['owned_by_table']);

        // Functions vs procedures split; security definer flag.
        $this->assertSame('add_two', $inventory['functions'][0]['name']);
        $this->assertSame('monthly_rollup', $postgres['procedures'][0]['proname'], 'procedures inventoried separately (30B)');

        // Triggers.
        $this->assertSame('orders_audit', $inventory['triggers'][0]['tgname']);

        // RLS policies (policy_metadata capability).
        $this->assertSame('customers_own_row', $inventory['policies'][0]['policy_name']);

        // Read-only enforcement honestly reported.
        $this->assertTrue($postgres['read_only_enforced']);
    }

    public function test_fingerprint_is_deterministic(): void
    {
        $first = $this->makeAdapter()->fingerprint();
        $second = $this->makeAdapter()->fingerprint();
        $this->assertSame($first, $second);
    }
}
