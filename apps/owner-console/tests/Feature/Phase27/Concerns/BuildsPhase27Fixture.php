<?php

namespace Tests\Feature\Phase27\Concerns;

use App\Models\Project;
use App\Models\User;

/**
 * Phase 27 fixture: admin user + projects + a local JSON dataset directory
 * for the example-json connector.
 */
trait BuildsPhase27Fixture
{
    protected User $admin;
    protected Project $projectA;
    protected Project $projectB;

    protected function buildPhase27(): void
    {
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->projectA = Project::create([
            'name' => 'Connector Fixture A', 'slug' => 'p27-a-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p27-a.test', 'api_version' => 'v1',
        ]);
        $this->projectB = Project::create([
            'name' => 'Connector Fixture B', 'slug' => 'p27-b-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p27-b.test', 'api_version' => 'v1',
        ]);
    }

    /** Build a local JSON dataset (users → orders → order_items). */
    protected function buildJsonDataset(?int $scale = null, ?string $name = null): string
    {
        $dir = storage_path('framework/testing/phase27/datasets/'.($name ?? 'ds-'.uniqid()));
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $users = $scale ?? 12;
        $userRows = [];
        for ($i = 1; $i <= $users; $i++) {
            $userRows[] = [
                'id' => $i,
                'name' => 'User '.$i,
                'email' => 'user'.$i.'@dataset.test',
                'age' => 20 + ($i % 40),
                'is_active' => $i % 2 === 0,
                'created_at' => sprintf('2026-01-%02dT10:00:00Z', ($i % 28) + 1),
            ];
        }
        $orderRows = [];
        for ($i = 1; $i <= $users * 2; $i++) {
            $orderRows[] = [
                'id' => $i,
                'user_id' => ($i % $users) + 1,
                'total' => round(9.9 * $i, 2),
                'currency' => 'USD',
                'created_at' => sprintf('2026-02-%02dT12:30:00Z', ($i % 28) + 1),
            ];
        }
        $itemRows = [];
        for ($i = 1; $i <= $users * 3; $i++) {
            $itemRows[] = [
                'id' => $i,
                'order_id' => ($i % ($users * 2)) + 1,
                'product' => 'SKU-'.(100 + $i),
                'qty' => 1 + ($i % 4),
                'price' => round(4.5 + $i * 0.3, 2),
            ];
        }
        file_put_contents($dir.'/users.json', json_encode($userRows));
        file_put_contents($dir.'/orders.json', json_encode($orderRows));
        file_put_contents($dir.'/order_items.json', json_encode($itemRows));

        return $dir;
    }
}
