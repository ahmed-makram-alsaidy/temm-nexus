<?php

namespace Tests\Feature\Phase43\Concerns;

/**
 * Launches the mock OpenCode server fixture (tests/Feature/Phase43/Fixtures/
 * mock-opencode.php) on a loopback port for the duration of a test.
 */
trait RunsMockOpenCode
{
    protected ?array $mockProcess = null;

    /** Start the mock server; returns its http://127.0.0.1:PORT base URL. */
    protected function startMockOpenCode(string $scenario = 'happy', ?string $basicAuth = 'opencode:mock-password'): string
    {
        $fixtures = dirname(__DIR__).'/Fixtures';
        $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';

        $cmd = [$php, $fixtures.'/mock-opencode.php', $scenario];

        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = array_merge($_SERVER, $_ENV, [
            'MOCK_OPENCODE_AUTH' => $basicAuth ?? '',
        ]);
        $env = array_filter($env, 'is_scalar');

        $process = proc_open($cmd, $spec, $pipes, null, $env);
        $this->assertIsResource($process, 'Failed to start mock OpenCode server.');

        $port = '';
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $line = fgets($pipes[1]);
            if ($line !== false && trim($line) !== '') {
                $port = trim($line);
                break;
            }
            usleep(50_000);
        }

        $this->assertNotSame('', $port, 'Mock OpenCode server did not report its port.');

        $this->mockProcess = ['process' => $process, 'pipes' => $pipes];

        return 'http://127.0.0.1:'.$port;
    }

    protected function stopMockOpenCode(): void
    {
        if ($this->mockProcess === null) {
            return;
        }

        [$process, $pipes] = [$this->mockProcess['process'], $this->mockProcess['pipes']];
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        if (is_resource($process)) {
            @proc_terminate($process);
            @proc_close($process);
        }
        $this->mockProcess = null;
    }

    protected function tearDownMock(): void
    {
        $this->stopMockOpenCode();
    }
}
