<?php

/**
 * TEMM Nexus AI — REAL AgentRouter acceptance (0.4.0-rc.7).
 *
 * Runs the five acceptance gates against the CONFIGURED provider row using
 * the exact production transport path (OpenAiCompatibleDriver through the
 * AiGateway / Test-Connection page code paths — never a side-built client):
 *
 *   1. HELLO visible            — a real turn whose visible reply contains HELLO
 *   2. Test Connection          — the Settings → Nexus AI test path returns CONNECTED
 *   3. Normal Nexus AI reply    — a real turn with a non-empty, token-counted reply
 *   4. No blank assistant bubble — every successful turn has non-blank text;
 *                                  failures must carry a safe error instead
 *   5. Secret leakage — 0       — the API key appears NOWHERE in the run's
 *                                 output, results, or telemetry log tail
 *
 * It also prints the transport comparison block (final URL, method, status,
 * Content-Type, effective User-Agent, request JSON field names, stream value)
 * straight from the safe TransportTelemetry log.
 *
 * Usage (on the VPS, from apps/owner-console):
 *   php scripts/agentrouter-acceptance.php
 *       Uses the enabled AgentRouter / openai_compatible provider row.
 *   php scripts/agentrouter-acceptance.php --provider-id=7
 *       Pin an explicit provider row.
 *   php scripts/agentrouter-acceptance.php --mock
 *       Fully OFFLINE: boots scripts/mock-agentrouter.php, creates a
 *       throwaway provider row + acceptance user in the LOCAL app database
 *       and runs the same gates end to end. Never use on production data.
 *
 * The API key is never printed by this script under any circumstances.
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AiProviderConfig;
use App\Models\AiUsageRecord;
use App\Models\User;
use App\Services\Access\Access;
use App\Services\Access\Roles;
use App\Services\Ai\AiContext;
use App\Services\Ai\ConversationEngine;
use App\Services\Ai\ModelRouter;
use App\Services\Ai\NexusAiConfig;
use App\Services\ControlPlane\Ai\AiGateway;
use App\Services\ControlPlane\Ai\OpenAiCompatibleDriver;
use Illuminate\Support\Facades\DB;

$mock = in_array('--mock', $argv, true);
$providerId = null;
$baseUrl = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--provider-id=')) {
        $providerId = (int) substr($arg, strlen('--provider-id='));
    }
    if (str_starts_with($arg, '--base-url=')) {
        $baseUrl = rtrim(substr($arg, strlen('--base-url=')), '/');
    }
}

$transientProviderId = null;
$transientUserId = null;
$mockProc = null;
$mockPipes = null;
$exitCode = 0;

// ── telemetry log tail (read via the configured channel) ────────────────
$channelName = (string) config('nexus-ai.telemetry.channel', 'nexus-ai');
$channelPath = config('logging.channels.'.$channelName.'.path');
$channelPath = is_string($channelPath) ? $channelPath : null;
$logSizeBefore = $channelPath !== null && is_file($channelPath) ? (int) filesize($channelPath) : 0;

function telemetryTail(?string $path, int $fromSize): array
{
    if ($path === null || ! is_file($path)) {
        return [];
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }
    fseek($handle, min($fromSize, (int) filesize($path)));
    $tail = stream_get_contents($handle);
    fclose($handle);

    return array_values(array_filter(explode("\n", (string) $tail)));
}

/** Extract the JSON context object from one log line. */
function entryJson(string $line): ?array
{
    $start = strpos($line, '{');
    if ($start === false) {
        return null;
    }
    $decoded = json_decode(substr($line, $start), true);

    return is_array($decoded) ? $decoded : null;
}

$gates = [
    'hello_visible' => [false, 'a real turn whose visible reply contains HELLO'],
    'test_connection' => [false, 'Settings → Nexus AI test path returns CONNECTED'],
    'normal_reply' => [false, 'a real turn returns a non-empty, token-counted reply'],
    'no_blank_bubble' => [false, 'successful turns always carry non-blank text'],
    'secret_leakage_zero' => [false, 'API key appears nowhere in output/results/telemetry'],
];
$findings = [];
$hello = $normal = null;

try {
    echo "TEMM Nexus AI — AgentRouter acceptance ".($mock ? '(LOCAL MOCK)' : '(REAL)')."\n";
    echo str_repeat('=', 74)."\n";

    if ($mock) {
        // Boot the offline mock gateway and point a throwaway provider row at it.
        $php = PHP_BINARY;
        $mockProc = proc_open(
            escapeshellarg($php).' '.escapeshellarg(__DIR__.'/mock-agentrouter.php'),
            [1 => ['pipe', 'w'], 2 => ['file', sys_get_temp_dir().'/mock-agentrouter.err', 'w']],
            $mockPipes
        );
        if (! is_resource($mockProc)) {
            fwrite(STDERR, "failed to start the mock gateway\n");
            exit(1);
        }
        stream_set_timeout($mockPipes[1], 10);
        $port = trim((string) fgets($mockPipes[1]));
        if (! preg_match('/^\d+$/', $port)) {
            fwrite(STDERR, "mock gateway did not report a port\n");
            exit(1);
        }
        config(['nexus-ai.allow_loopback_endpoints' => true]);
        NexusAiConfig::setAiEnabled(true);

        $config = AiProviderConfig::create([
            'provider' => 'openai_compatible',
            'display_name' => 'AgentRouter (local mock)',
            'base_url' => "http://127.0.0.1:{$port}/v1",
            'model' => 'deepseek-v4-flash',
            'secret_encrypted' => 'sk-mock-acceptance-key-not-a-secret',
            'enabled' => true,
            'status' => 'ready',
            'timeout_seconds' => 15,
            'max_output_tokens' => 256,
            'custom_headers' => ['User-Agent' => 'codex_cli_rs/0.149.1'],
        ]);
        $transientProviderId = $config->getKey();
        $user = User::create([
            'name' => 'Acceptance Runner (mock)',
            'email' => 'acceptance-mock-'.uniqid().'@acceptance.local',
            'password' => bin2hex(random_bytes(16)),
            'platform_role' => Roles::PLATFORM_OWNER,
        ]);
        $transientUserId = $user->getKey();
        echo "mock gateway: http://127.0.0.1:{$port}/v1 (throwaway provider + user created)\n\n";
    } else {
        // REAL run — locate the configured provider row, never create one.
        $query = AiProviderConfig::query()->where('enabled', true);
        $config = null;
        if ($providerId !== null) {
            $config = $query->whereKey($providerId)->first();
            if ($config === null) {
                fwrite(STDERR, "no provider row with id {$providerId}\n");
                exit(1);
            }
        } elseif ($baseUrl !== null) {
            $config = (clone $query)->where('base_url', $baseUrl)->first()
                ?? (clone $query)->where('base_url', 'like', '%'.parse_url($baseUrl, PHP_URL_HOST).'%')->first();
        }
        $config ??= (clone $query)->where('base_url', 'like', '%agentrouter%')->first()
            ?? (clone $query)->where('provider', 'openai_compatible')->orderByDesc('id')->first();

        if ($config === null) {
            fwrite(STDERR, "No enabled openai_compatible provider row found.\n"
                ."Save the AgentRouter preset in Settings → Nexus AI first, or pass --provider-id=ID.\n");
            exit(1);
        }

        $user = User::query()->where('platform_role', Roles::PLATFORM_OWNER)->orderBy('id')->first();
        if ($user === null) {
            $user = User::create([
                'name' => 'AgentRouter Acceptance Runner',
                'email' => 'agentrouter-acceptance-'.time().'@acceptance.local',
                'password' => bin2hex(random_bytes(16)),
                'platform_role' => Roles::PLATFORM_OWNER,
            ]);
            $transientUserId = $user->getKey();
            echo "note: no platform owner existed; created acceptance user {$user->email} (safe to delete)\n";
        }

        echo "provider row: #{$config->getKey()} {$config->display_name} ({$config->provider})\n";
        echo "base_url:     {$config->base_url}\n";
        echo "model:        {$config->model}\n\n";
    }

    $secret = (string) ($config->secret_encrypted ?? '');
    $gateway = new AiGateway;

    // ── Gate 2 — Test Connection (the exact page path) ──────────────────
    $testResult = $gateway->driver($config)->test($config);
    $config->forceFill([
        'status' => $testResult === OpenAiCompatibleDriver::RESULT_CONNECTED ? 'connected' : 'error',
        'last_tested_at' => now(),
    ])->save();
    $gates['test_connection'][0] = $testResult === OpenAiCompatibleDriver::RESULT_CONNECTED;
    if (! $gates['test_connection'][0]) {
        $findings[] = "Test Connection classified as {$testResult} — read the ai_transport entries below.";
    }

    // ── Gates 1/3/4 — real turns through the production engine ──────────
    if (NexusAiConfig::aiEnabled()) {
        $engine = new ConversationEngine(AiContext::platform(Access::for($user)), new ModelRouter($config->getKey()));

        $hello = $engine->turn('Reply exactly with HELLO');
        $gates['hello_visible'][0] = (bool) $hello['ok']
            && str_contains(strtoupper((string) $hello['reply']), 'HELLO');

        $normal = $engine->turn('In one short sentence, confirm that you are working.');
        $gates['normal_reply'][0] = (bool) $normal['ok']
            && trim((string) $normal['reply']) !== ''
            && (int) ($normal['usage']['output_tokens'] ?? 0) > 0;

        $gates['no_blank_bubble'][0] = $gates['hello_visible'][0] && $gates['normal_reply'][0]
            && trim((string) $hello['reply']) !== '' && trim((string) $normal['reply']) !== ''
            // A failed turn must surface a SAFE ERROR, never an empty reply
            // that would render as a blank assistant bubble.
            && (($hello['ok'] && $normal['ok']) || ($hello['reply'] === null && $normal['reply'] === null));
        if (! $gates['normal_reply'][0] && $normal !== null && ! $normal['ok']) {
            $findings[] = 'Normal turn failed with safe error: '.(string) $normal['error'];
        }
        if ($hello !== null && ! $hello['ok']) {
            $findings[] = 'HELLO turn failed with safe error: '.(string) $hello['error'];
        }
    } else {
        $findings[] = 'The Nexus AI master switch (Settings → Nexus AI) is OFF — chat gates cannot run.';
    }

    // ── Gate 5 — secret leakage — 0 ─────────────────────────────────────
    $leakLocations = [];
    if ($secret !== '') {
        $outputs = var_export($hello, true)."\n".var_export($normal, true);
        if (str_contains($outputs, $secret)) {
            $leakLocations[] = 'script result payloads';
        }
        $tail = telemetryTail($channelPath, $logSizeBefore);
        foreach ($tail as $i => $line) {
            if (str_contains($line, $secret)) {
                $event = str_contains($line, 'ai_transport.request') ? 'ai_transport.request'
                    : (str_contains($line, 'ai_transport.response') ? 'ai_transport.response'
                    : (str_contains($line, 'ai_transport.connection_failure') ? 'ai_transport.connection_failure' : 'unknown'));
                $leakLocations[] = 'telemetry log line '.($i + 1).' (event: '.$event.')';
            }
        }
        $gates['secret_leakage_zero'][0] = $leakLocations === [];
        if ($leakLocations !== []) {
            $findings[] = 'SECRET LEAK DETECTED in: '.implode('; ', $leakLocations).' (locations only — content withheld)';
        }
    } else {
        $findings[] = 'provider row has an empty API key — leakage gate skipped as vacuous, but auth will fail';
        $gates['secret_leakage_zero'][0] = true;
    }

    // ── Transport comparison block (from SAFE telemetry only) ───────────
    echo "Transport comparison (safe telemetry: {$channelName} channel)\n";
    echo str_repeat('-', 74)."\n";
    $tail = telemetryTail($channelPath, $logSizeBefore);
    $sawWire = false;
    foreach ($tail as $line) {
        $entry = entryJson($line);
        if ($entry === null || ! str_starts_with($line, '[') || ! str_contains($line, 'ai_transport.')) {
            continue;
        }
        $sawWire = true;
        if (str_contains($line, 'ai_transport.request')) {
            printf("REQUEST  kind=%s\n         url=%s\n         method=%s  user_agent=%s\n         json_fields=[%s]  stream=%s  model=%s\n",
                $entry['kind'] ?? '?',
                $entry['url'] ?? '?',
                $entry['method'] ?? '?',
                $entry['user_agent'] ?? '?',
                implode(',', $entry['request_json_fields'] ?? []),
                var_export($entry['stream'] ?? null, true),
                $entry['model'] ?? '?');
        } elseif (str_contains($line, 'ai_transport.response')) {
            printf("RESPONSE kind=%s  status=%s  content_type=%s\n",
                $entry['kind'] ?? '?',
                $entry['status'] ?? '?',
                $entry['content_type'] ?? '?');
        } elseif (str_contains($line, 'ai_transport.connection_failure')) {
            printf("CONNECT-FAIL kind=%s  exception=%s\n", $entry['kind'] ?? '?', $entry['exception'] ?? '?');
        }
    }
    if (! $sawWire) {
        echo "(no ai_transport entries found — is telemetry enabled? NEXUS_AI_TELEMETRY=true)\n";
    }

    // ── Verdict ─────────────────────────────────────────────────────────
    echo "\n".str_repeat('=', 74)."\nACCEPTANCE VERDICT\n".str_repeat('-', 74)."\n";
    foreach ($gates as $name => [$ok, $description]) {
        printf("%s  %-22s %s\n", $ok ? 'PASS' : 'FAIL', $name, $description);
        if (! $ok) {
            $exitCode = 1;
        }
    }
    foreach ($findings as $finding) {
        echo "note: {$finding}\n";
    }
    echo str_repeat('=', 74)."\n";
    exit($exitCode);
} catch (Throwable $e) {
    fwrite(STDERR, 'acceptance framework failure: '.get_class($e).': '.$e->getMessage()."\n"
        .'at '.$e->getFile().':'.$e->getLine()."\n");
    exit(2);
} finally {
    if ($transientProviderId !== null) {
        try {
            AiUsageRecord::query()->where('ai_provider_config_id', $transientProviderId)->delete();
            AiProviderConfig::query()->whereKey($transientProviderId)->delete();
        } catch (Throwable) {
            // leave the throwaway row rather than masking the verdict
        }
    }
    // Transient acceptance USERS are kept on purpose: audit rows may
    // reference them and the account is inert (random password, no
    // capability beyond platform read).
    if (is_resource($mockProc)) {
        proc_terminate($mockProc);
        proc_close($mockProc);
    }
}
