<?php

namespace Tests\Feature;

use App\Jobs\TemplateProbeJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class TemplateGateTest extends TestCase
{
    public function test_health_endpoint_reports_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonStructure(['ok', 'app', 'time', 'database', 'redis']);
    }

    public function test_database_connection_works(): void
    {
        $this->assertEquals(1, DB::select('select 1 as ok')[0]->ok);
    }

    public function test_redis_connection_works(): void
    {
        Redis::connection()->ping();
        Cache::put('gate:test', 'yes', 60);
        $this->assertSame('yes', Cache::get('gate:test'));
    }

    public function test_queued_job_processes(): void
    {
        $marker = 'gate-'.uniqid();
        TemplateProbeJob::dispatch($marker);

        // Process the redis queue synchronously inside the test.
        $this->artisan('queue:work', [
            '--once' => true,
            '--queue' => config('queue.connections.redis.queue', 'default'),
            '--timeout' => 30,
        ])->assertSuccessful();

        $this->assertNotNull(Cache::get("queue-probe:{$marker}"));
    }
}
