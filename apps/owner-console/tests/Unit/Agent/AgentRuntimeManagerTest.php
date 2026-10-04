<?php

namespace Tests\Unit\Agent;

use App\Services\Agent\AgentExecutionPolicy;
use App\Services\Agent\AgentRuntimeManager;
use App\Services\Agent\Contract\AgentRuntimeException;
use App\Services\Agent\Runtimes\OpenCode\OpenCodeRuntime;
use PHPUnit\Framework\TestCase;

class AgentRuntimeManagerTest extends TestCase
{
    public function test_defaults_register_the_opencode_driver(): void
    {
        $manager = new AgentRuntimeManager;
        $manager->registerDefaults();

        $this->assertTrue($manager->has('opencode'));
        $this->assertSame('opencode', $manager->driver('opencode')->id());
        $this->assertSame('OpenCode', $manager->driver('opencode')->label());
    }

    public function test_unknown_driver_throws_a_structured_error(): void
    {
        $manager = new AgentRuntimeManager;
        $manager->registerDefaults();

        $this->expectException(AgentRuntimeException::class);
        $manager->driver('does-not-exist');
    }

    public function test_catalogue_lists_driver_ids_and_labels(): void
    {
        $manager = new AgentRuntimeManager;
        $manager->registerDefaults();

        $catalogue = $manager->catalogue();
        $ids = array_column($catalogue, 'id');

        $this->assertContains('opencode', $ids);
        $this->assertContains('OpenCode', array_column($catalogue, 'label'));
    }

    public function test_drivers_can_be_extended_and_overridden(): void
    {
        $manager = new AgentRuntimeManager;
        $manager->registerDefaults();
        $manager->extend('opencode', fn () => new OpenCodeRuntime);

        $this->assertTrue($manager->driver('opencode') instanceof OpenCodeRuntime);
    }

    public function test_v1_policy_boundaries(): void
    {
        $verdicts = AgentExecutionPolicy::describe();

        $this->assertSame('runtime', $verdicts[AgentExecutionPolicy::READ]);
        $this->assertSame('runtime', $verdicts[AgentExecutionPolicy::WRITE_WORKSPACE]);
        $this->assertSame('runtime', $verdicts[AgentExecutionPolicy::EXECUTE_WORKSPACE_COMMAND]);
        $this->assertSame('approval', $verdicts[AgentExecutionPolicy::APPLY_TO_SOURCE]);
        $this->assertSame('denied', $verdicts[AgentExecutionPolicy::DEPLOY]);
    }

    public function test_session_permission_config_denies_escaping_the_workspace(): void
    {
        $rules = AgentExecutionPolicy::sessionPermissionConfig();
        $byKey = collect($rules)->keyBy("permission");

        $this->assertSame("deny", $byKey["external_directory"]["action"]);
        $this->assertSame("allow", $byKey["read"]["action"]);
        $this->assertSame("allow", $byKey["edit"]["action"]);
        $this->assertSame("allow", $byKey["bash"]["action"]);
    }
}
