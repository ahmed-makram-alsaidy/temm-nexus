<?php

namespace Tests\Feature\Phase29;

use App\Connectors\Firebase\FirebaseSourceAdapter;
use App\Connectors\Firebase\Protocol\FixtureFirebaseTransport;
use App\Models\MigrationSource;
use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 29E — batched, bounded, deterministic extraction (29E): batching,
 * pagination, bounded memory (no full-database loads), resume, progress and
 * the read-only guarantee.
 */
class FirebaseExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function makeAdapter(?FixtureFirebaseTransport &$transport = null): FirebaseSourceAdapter
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $project = Project::create([
            'name' => 'P29 Extract', 'slug' => 'p29-extract-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p29e.test', 'api_version' => 'v1',
        ]);
        $connector = ConnectorRegistry::instance()->sourceConnector('firebase');
        $source = $connector->createSourceProfile($project, [], [
            'project_id' => 'temm-dogfood-sandbox',
            'transport' => 'fixture',
            'display_name' => 'Firebase fixture',
        ]);
        $source->refresh();
        $adapter = new FirebaseSourceAdapter($source);
        $adapter->inventory(); // force analysis so table paths resolve
        $transport = $this->exposeTransport($adapter);

        return $adapter;
    }

    /** Reach the fixture transport behind the adapter (request recording). */
    protected function exposeTransport(FirebaseSourceAdapter $adapter): FixtureFirebaseTransport
    {
        $client = (function () {
            return $this->client;
        })->call($adapter);

        return (function () {
            return $this->transport;
        })->call($client) ?? new FixtureFirebaseTransport;
    }

    public function test_extraction_is_batched_and_projected(): void
    {
        $adapter = $this->makeAdapter();
        $rows = [];
        $total = $adapter->streamRows('default', 'users', ['_id', 'name', 'age'], function ($row) use (&$rows) {
            $rows[] = $row;
        }, 2);

        $this->assertSame(4, $total);
        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            // Projection preserves the deterministic field-map order.
            $this->assertSame(['_id', 'age', 'name'], array_keys($row), 'column projection applied');
        }
        $this->assertSame('u001', $rows[0]['_id']);
        $this->assertSame(34, $rows[0]['age']);
    }

    public function test_extraction_is_cursor_ordered_and_bounded(): void
    {
        $adapter = $this->makeAdapter();
        $transport = $this->exposeTransport($adapter);
        $batches = 0;
        $adapter->streamRows('default', 'products', [], function ($row) use (&$batches) {
        }, 2);
        $this->assertGreaterThanOrEqual(2, $adapter->lastExtractionBatches, 'small batches force multiple streaming rounds');
        $this->assertNotEmpty($transport->requests);

        // Only read-shaped REST calls were ever issued (29 read-only).
        foreach ($transport->requests as $request) {
            $this->assertContains($request['method'], ['GET', 'POST']);
            $this->assertStringNotContainsString(':delete', $request['url']);
            $this->assertStringNotContainsString(':patch', $request['url']);
            $this->assertStringNotContainsString(':update', $request['url']);
            $this->assertStringNotContainsString(':create', $request['url']);
            $this->assertStringNotContainsString('commit', $request['url']);
        }
    }

    public function test_deterministic_ordering_makes_resume_idempotent(): void
    {
        $adapter = $this->makeAdapter();
        $first = [];
        $adapter->streamRows('default', 'orders', ['_id', 'user_id'], function ($row) use (&$first) {
            $first[] = $row;
        }, 2);
        $second = [];
        $adapter->streamRows('default', 'orders', ['_id', 'user_id'], function ($row) use (&$second) {
            $second[] = $row;
        }, 3); // different batch size — same deterministic __name__ order

        $this->assertSame($first, $second, 'batch size must not change output order (29E resume determinism)');
        $this->assertSame(['o001', 'o002', 'o003'], array_column($second, '_id'));
    }

    public function test_child_table_explodes_arrays_with_ordering(): void
    {
        $adapter = $this->makeAdapter();
        $rows = [];
        $total = $adapter->streamRows('default', 'orders__items', [], function ($row) use (&$rows) {
            $rows[] = $row;
        });

        $this->assertSame(3, $total, '1+1+0 items explode in document order');
        $this->assertSame('o001', $rows[0]['parent_id']);
        $this->assertSame(0, $rows[0]['__idx']);
        $this->assertSame('p001', $rows[0]['product_id']);
        $this->assertSame(49.99, $rows[0]['unit_price']);
        $this->assertSame('p002', $rows[1]['product_id']);
        $this->assertSame(2, $rows[1]['qty']);
    }

    public function test_subcollection_extraction_carries_parent_links(): void
    {
        $adapter = $this->makeAdapter();
        $rows = [];
        $total = $adapter->streamRows('default', 'orders__timeline', [], function ($row) use (&$rows) {
            $rows[] = $row;
        });

        $this->assertSame(2, $total);
        $this->assertSame('o001', $rows[0]['parent_id']);
        $this->assertSame('created', $rows[0]['event']);
        $this->assertSame('paid', $rows[1]['event']);
    }

    public function test_count_rows_matches_extraction(): void
    {
        $adapter = $this->makeAdapter();
        $this->assertSame(4, $adapter->countRows('default', 'users'));
        $this->assertSame(3, $adapter->countRows('default', 'products'));
        $this->assertSame(3, $adapter->countRows('default', 'orders__items'), 'derived child table counts exploded elements');
        $this->assertSame(2, $adapter->countRows('default', 'orders__timeline'));

        $extracted = 0;
        $adapter->streamRows('default', 'products', [], function () use (&$extracted) {
            $extracted++;
        });
        $this->assertSame($adapter->countRows('default', 'products'), $extracted, 'validation parity: count == extraction');
    }

    public function test_auth_users_stream_through_the_generic_seam(): void
    {
        $adapter = $this->makeAdapter();
        $users = [];
        $total = $adapter->streamAuthUsers(function ($user) use (&$users) {
            $users[] = $user;
        });

        $this->assertSame(4, $total);
        $this->assertCount(4, $users);
        foreach ($users as $user) {
            // Normalized engine contract: id/email/encrypted_password/created_at.
            $this->assertSame(['id', 'email', 'encrypted_password', 'created_at'], array_keys($user));
            $this->assertNull($user['encrypted_password'], 'password material is NEVER available (29F)');
            $this->assertNotSame('', $user['id']);
        }
        $ids = array_column($users, 'id');
        $this->assertContains('u001', $ids);
        $emails = array_column($users, 'email');
        $this->assertContains('ahmed@example.test', $emails);
        $this->assertContains(null, $emails, 'anonymous user has no email — honest null');
    }

    public function test_extraction_survives_transport_restart_deterministically(): void
    {
        // 29E resume: a "crash" (fresh adapter + analysis cache cleared) and a
        // re-run produce identical rows for the same table.
        $first = $this->makeAdapter();
        $rowsA = [];
        $first->streamRows('default', 'users', [], function ($row) use (&$rowsA) {
            $rowsA[] = $row;
        });

        $second = $this->makeAdapter();
        $rowsB = [];
        $second->streamRows('default', 'users', [], function ($row) use (&$rowsB) {
            $rowsB[] = $row;
        });

        $this->assertSame($rowsA, $rowsB);
    }
}
