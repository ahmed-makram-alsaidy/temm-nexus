<?php

// Wire-capture client (regression fixture). Boots the real application and
// drives the REAL OpenAiCompatibleDriver::test() and ::complete() against a
// loopback capture server, so the assertions run on the RAW bytes Guzzle
// actually put on the wire — beyond the reach of Http::fake().
//
// Usage: php wire_client.php <port> <with-custom-ua:0|1> <capture-dir>

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AiProviderConfig;
use App\Services\ControlPlane\Ai\OpenAiCompatibleDriver;

$port = (int) $argv[1];
$withUa = $argv[2] === '1';
$captureDir = rtrim($argv[3], '/\\');

$config = new AiProviderConfig([
    'provider' => 'openai_compatible',
    'display_name' => 'Wire Capture',
    'base_url' => "http://127.0.0.1:{$port}",
    'model' => 'deepseek-v4-flash',
    'secret_encrypted' => 'sk-wire-capture-fake-key',
    'enabled' => true,
    'status' => 'ready',
    'timeout_seconds' => 10,
    'max_output_tokens' => 64,
    'custom_headers' => $withUa ? ['User-Agent' => 'codex_cli_rs/0.149.1'] : [],
]);

$driver = new OpenAiCompatibleDriver;

$out = ['with_custom_ua' => $withUa];

try {
    $out['test'] = $driver->test($config);
} catch (Throwable $e) {
    $out['test'] = 'EXCEPTION: '.get_class($e);
}

try {
    $result = $driver->complete($config, [['role' => 'user', 'content' => 'Reply exactly with WIRE-OK']], []);
    $out['complete'] = $result['text'] ?? null;
} catch (Throwable $e) {
    $out['complete'] = 'EXCEPTION: '.get_class($e);
}

file_put_contents($captureDir.'/client_result.json', json_encode($out));
echo "done\n";
