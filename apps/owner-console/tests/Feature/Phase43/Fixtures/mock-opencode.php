<?php

// Mock OpenCode server (Phase 43 test fixture). Implements the OFFICIAL
// OpenCode server surface TEMM uses (docs/agent-runtime/OPENCODE_INTEGRATION.md)
// — same paths, same payload shapes as the verified 1.18.34 schema — so the
// adapter is proven against a stand-in of the real contract, not a fake one
// invented for the tests.
//
// Usage: php mock-opencode.php <scenario> [port]
//   Prints the bound port as its first stdout line, then serves forever.
//
// Scenarios:
//   happy          session runs, edits 2 files, runs a command, goes idle
//   permission     asks a bash permission mid-session before continuing
//   no-changes     session runs but produces no diff
//   session-error  runtime reports a session error then goes idle
//   silent         never emits events and reports busy (drives TIMEOUT)
//   malformed      /session returns garbage (drives INVALID_RUNTIME_RESPONSE)
//   slow           emits events with delays (exercises streaming)
//   abortable      long-running until aborted (exercises cancellation)
//
// State is kept in a temp file so sequential-connection handling stays
// simple (one request per connection, like the Phase 42 wire fixture).

$scenario = $argv[1] ?? 'happy';
$requestedPort = (int) ($argv[2] ?? 0);

$server = stream_socket_server("tcp://127.0.0.1:{$requestedPort}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: {$errstr}\n");
    exit(1);
}

$name = stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1)."\n";
flush();

$stateFile = sys_get_temp_dir().'/mock-opencode-'.getmypid().'.json';

function state_load(string $file): array
{
    $raw = @file_get_contents($file);

    return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
}

function state_save(string $file, array $state): void
{
    file_put_contents($file, json_encode($state), LOCK_EX);
}

function respond($conn, int $status, string $body, array $headers = []): void
{
    $reason = [200 => 'OK', 204 => 'No Content', 400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found'][$status] ?? 'OK';
    $head = "HTTP/1.1 {$status} {$reason}\r\n";
    foreach ($headers as $k => $v) {
        $head .= "{$k}: {$v}\r\n";
    }
    if ($status !== 204) {
        $head .= 'Content-Length: '.strlen($body)."\r\n";
    }
    $head .= "Connection: close\r\n\r\n";
    fwrite($conn, $head.($status === 204 ? '' : $body));
}

function read_request($conn): ?array
{
    $raw = '';
    $deadline = microtime(true) + 30;
    while (microtime(true) < $deadline) {
        $chunk = @fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            usleep(10_000);

            continue;
        }
        $raw .= $chunk;
        if (strpos($raw, "\r\n\r\n") !== false) {
            // Read remaining body if content-length present.
            if (preg_match('/content-length:\s*(\d+)/i', $raw, $m)) {
                $headEnd = strpos($raw, "\r\n\r\n") + 4;
                while (strlen($raw) - $headEnd < (int) $m[1] && microtime(true) < $deadline) {
                    $chunk = @fread($conn, 8192);
                    if ($chunk === false || $chunk === '') {
                        usleep(10_000);

                        continue;
                    }
                    $raw .= $chunk;
                }
            }
            break;
        }
    }

    if ($raw === '' || strpos($raw, "\r\n\r\n") === false) {
        return null;
    }

    [$head, $body] = explode("\r\n\r\n", $raw, 2);
    $lines = explode("\r\n", $head);
    [$method, $target] = explode(' ', $lines[0]);
    $headers = [];
    foreach (array_slice($lines, 1) as $line) {
        if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    parse_str(parse_url($target, PHP_URL_QUERY) ?? '', $query);

    return [
        'method' => $method,
        'path' => parse_url($target, PHP_URL_PATH),
        'query' => $query,
        'headers' => $headers,
        'body' => $body,
    ];
}

function authorize(?array $req): bool
{
    $expected = getenv('MOCK_OPENCODE_AUTH') !== false ? getenv('MOCK_OPENCODE_AUTH') : null;
    if ($expected === null || $expected === '') {
        return true;
    }

    $header = $req['headers']['authorization'] ?? '';

    return $header === 'Basic '.base64_encode($expected);
}

/** The scripted event sequence per scenario — same shapes the real SSE stream emits. */
function script_for(string $scenario, string $sessionId): array
{
    $frames = [];
    $emit = function (string $type, array $properties) use (&$frames) {
        $frames[] = ['type' => $type, 'properties' => $properties];
    };

    switch ($scenario) {
        case 'happy':
            $emit('session.status', ['sessionID' => $sessionId, 'status' => ['type' => 'busy']]);
            $emit('message.part.updated', ['sessionID' => $sessionId, 'part' => ['type' => 'reasoning', 'text' => 'SECRET-REASONING-CONTENT']]);
            $emit('message.part.updated', ['sessionID' => $sessionId, 'part' => ['type' => 'tool', 'tool' => 'read', 'callID' => 'c1', 'state' => ['status' => 'completed', 'input' => ['filePath' => 'src/greeting.php'], 'output' => 'file content here', 'title' => 'Read src/greeting.php', 'time' => ['start' => 1000, 'end' => 2000]]]]);
            $emit('message.part.updated', ['sessionID' => $sessionId, 'part' => ['type' => 'tool', 'tool' => 'edit', 'callID' => 'c2', 'state' => ['status' => 'completed', 'input' => ['filePath' => 'src/greeting.php'], 'output' => '', 'title' => 'Edit src/greeting.php', 'time' => ['start' => 3000, 'end' => 4000]]]]);
            $emit('message.part.updated', ['sessionID' => $sessionId, 'part' => ['type' => 'tool', 'tool' => 'bash', 'callID' => 'c3', 'state' => ['status' => 'running', 'input' => ['command' => 'php test.php'], 'title' => 'php test.php', 'time' => ['start' => 5000]]]]);
            $emit('message.part.updated', ['sessionID' => $sessionId, 'part' => ['type' => 'tool', 'tool' => 'bash', 'callID' => 'c3', 'state' => ['status' => 'completed', 'input' => ['command' => 'php test.php'], 'output' => "OUTPUT-CONTENTS-ARE-NOT-STORED\n", 'metadata' => ['exit' => 0, 'description' => 'ran'], 'title' => 'php test.php', 'time' => ['start' => 5000, 'end' => 6500]]]]);
            $emit('message.part.updated', ['sessionID' => $sessionId, 'part' => ['type' => 'text', 'text' => 'Updated the greeting and the test.']]);
            $emit('message.updated', ['sessionID' => $sessionId, 'info' => ['tokens' => ['input' => 120, 'output' => 45], 'cost' => 0.01]]);
            $emit('session.idle', ['sessionID' => $sessionId]);
            break;

        case 'permission':
            $emit('message.part.updated', ['sessionID' => $sessionId, 'part' => ['type' => 'tool', 'tool' => 'bash', 'callID' => 'c1', 'state' => ['status' => 'pending', 'input' => ['command' => 'php test.php'], 'raw' => '', 'title' => 'php test.php']]]);
            $emit('permission.asked', ['sessionID' => $sessionId, 'id' => 'per_001', 'permission' => 'bash', 'patterns' => ['php test.php'], 'metadata' => []]);
            $emit('message.part.updated', ['sessionID' => $sessionId, 'part' => ['type' => 'tool', 'tool' => 'edit', 'callID' => 'c2', 'state' => ['status' => 'completed', 'input' => ['filePath' => 'tests/greeting_test.php'], 'output' => '', 'title' => 'Edit tests/greeting_test.php', 'time' => ['start' => 1000, 'end' => 2000]]]]);
            $emit('session.idle', ['sessionID' => $sessionId]);
            break;

        case 'no-changes':
            $emit('message.part.updated', ['sessionID' => $sessionId, 'part' => ['type' => 'text', 'text' => 'Nothing to change.']]);
            $emit('session.idle', ['sessionID' => $sessionId]);
            break;

        case 'session-error':
            $emit('session.error', ['sessionID' => $sessionId, 'message' => 'upstream error: Authorization: Bearer sk-abcdef1234567890']);
            $emit('session.idle', ['sessionID' => $sessionId]);
            break;

        case 'slow':
            $emit('message.part.updated', ['sessionID' => $sessionId, 'part' => ['type' => 'text', 'text' => 'Working...']]);
            $frames[] = ['__sleep' => 1];
            $emit('message.part.updated', ['sessionID' => $sessionId, 'part' => ['type' => 'text', 'text' => 'Still working...']]);
            $emit('session.idle', ['sessionID' => $sessionId]);
            break;

        case 'abortable':
            $emit('message.part.updated', ['sessionID' => $sessionId, 'part' => ['type' => 'text', 'text' => 'Starting long work...']]);
            $frames[] = ['__wait_abort' => true];
            break;

        case 'happy':
        default:
            break;
    }

    return $frames;
}

const DIFFS = [
    ['path' => 'src/greeting.php', 'status' => 'modified', 'additions' => 1, 'deletions' => 1,
        'patch' => "diff --git a/src/greeting.php b/src/greeting.php\n--- a/src/greeting.php\n+++ b/src/greeting.php\n@@ -1,1 +1,1 @@\n-echo 'HELLO';\n+echo 'HELLO TEMM';\n"],
    ['path' => 'tests/greeting_test.php', 'status' => 'modified', 'additions' => 1, 'deletions' => 1,
        'patch' => "diff --git a/tests/greeting_test.php b/tests/greeting_test.php\n--- a/tests/greeting_test.php\n+++ b/tests/greeting_test.php\n@@ -1,1 +1,1 @@\n-assert('HELLO');\n+assert('HELLO TEMM');\n"],
];

while (true) {
    $conn = @stream_socket_accept($server, 60);
    if ($conn === false) {
        continue;
    }

    $req = read_request($conn);

    if ($req === null) {
        fclose($conn);

        continue;
    }

    $state = state_load($stateFile);
    $path = $req['path'];

    @file_put_contents(sys_get_temp_dir().'/mock-opencode-debug-'.getmypid().'.log',
        date('H:i:s')." {$req['method']} {$path}?".http_build_query($req['query'])."\n", FILE_APPEND);

    // Record the last REAL request shape for transport assertions (debug
    // probes must not overwrite what they are trying to observe).
    if (! str_starts_with($path, '/debug')) {
        $state['last_request'] = [
            'method' => $req['method'],
            'path' => $path,
            'query' => $req['query'],
            'authorization' => $req['headers']['authorization'] ?? null,
        ];
        state_save($stateFile, $state);
    }

    // Authentication (official basic auth surface).
    if (! authorize($req)) {
        respond($conn, 401, json_encode(['error' => 'unauthorized']), ['WWW-Authenticate' => 'Basic realm="opencode"']);
        fclose($conn);

        continue;
    }

    if ($scenario === 'malformed' && $path === '/session') {
        respond($conn, 200, 'THIS-IS-NOT-JSON{{', ['Content-Type' => 'application/json']);
        fclose($conn);

        continue;
    }

    switch (true) {
        case $path === '/global/health':
            respond($conn, 200, json_encode(['healthy' => true, 'version' => getenv('MOCK_OPENCODE_VERSION') ?: '1.18.34']));
            break;

        case $path === '/debug/last-request':
            respond($conn, 200, json_encode($state['last_request'] ?? null));
            break;

        case $path === '/config/providers':
            respond($conn, 200, json_encode(['providers' => [[
                'id' => 'mockprovider',
                'name' => 'Mock Provider',
                'source' => 'config',
                'env' => [],
                'options' => [],
                'models' => [
                    'mock-small' => ['id' => 'mock-small', 'providerID' => 'mockprovider', 'name' => 'Mock Small', 'api' => ['id' => 'mock-small', 'url' => '', 'npm' => 'x'], 'capabilities' => ['toolcall' => true], 'limit' => ['context' => 128000, 'output' => 8192], 'cost' => ['input' => 0, 'output' => 0]],
                    'mock-large' => ['id' => 'mock-large', 'providerID' => 'mockprovider', 'name' => 'Mock Large', 'api' => ['id' => 'mock-large', 'url' => '', 'npm' => 'x'], 'capabilities' => ['toolcall' => true, 'reasoning' => true], 'limit' => ['context' => 1000000, 'output' => 32768], 'cost' => ['input' => 0, 'output' => 0]],
                ],
            ]]]));
            break;

        case $path === '/session' && $req['method'] === 'POST':
            $sessionId = 'ses_'.bin2hex(random_bytes(6));
            $directory = $req['query']['directory'] ?? '';
            $state['sessions'][$sessionId] = ['directory' => $directory, 'aborted' => false, 'busy' => false, 'prompted' => false, 'permissions' => []];
            state_save($stateFile, $state);
            respond($conn, 200, json_encode(['id' => $sessionId, 'directory' => $directory, 'title' => null]));
            break;

        case preg_match('#^/session/(ses_[a-f0-9]+)/prompt_async$#', $path, $m) === 1 && $req['method'] === 'POST':
            $sessionId = $m[1];
            if (! isset($state['sessions'][$sessionId])) {
                respond($conn, 404, json_encode(['error' => 'not found']));
                break;
            }
            $state['sessions'][$sessionId]['busy'] = true;
            $state['sessions'][$sessionId]['prompted'] = true;
            $script = script_for($scenario, $sessionId);
            // For the permission scenario, the frames after the ask are held
            // until the permission is answered.
            if ($scenario === 'permission') {
                $state['sessions'][$sessionId]['queue'] = array_slice($script, 0, 2);
                $state['sessions'][$sessionId]['rest'] = array_slice($script, 2);
            } else {
                $state['sessions'][$sessionId]['queue'] = $script;
            }
            // Like the real agent, actually apply changes INSIDE the assigned
            // workspace directory (the only filesystem the session sees).
            if (in_array($scenario, ['happy', 'permission'], true)) {
                $directory = $state['sessions'][$sessionId]['directory'];
                $edits = $scenario === 'happy'
                    ? [
                        ['src/greeting.php', "<?php\necho 'HELLO TEMM';\n"],
                        ['tests/greeting_test.php', "<?php\nassert('HELLO TEMM' === 'HELLO TEMM');\n"],
                    ]
                    : [
                        ['tests/greeting_test.php', "<?php\nassert('HELLO TEMM' === 'HELLO TEMM');\n"],
                    ];
                foreach ($edits as [$relative, $content]) {
                    if (! str_contains($directory, '..')) {
                        @mkdir(dirname($directory.'/'.$relative), 0777, true);
                        @file_put_contents($directory.'/'.$relative, $content);
                    }
                }
            }
            state_save($stateFile, $state);
            respond($conn, 204, '');
            break;

        case $path === '/event' && $req['method'] === 'GET':
            // SSE: server.connected first, then queued frames for this
            // directory's sessions; close when the queue is drained or the
            // session was aborted.
            $directory = $req['query']['directory'] ?? '';
            header_stream($conn);
            send_frame($conn, ['id' => 'evt_0', 'type' => 'server.connected', 'properties' => []]);

            while (true) {
                // Sequential-connection server: if the SSE client walked away
                // (idle timeout), stop holding the connection open so other
                // requests (permission replies, status polls) can be served.
                stream_set_timeout($conn, 1);
                @fread($conn, 1);
                if (feof($conn)) {
                    break;
                }

                $state = state_load($stateFile);
                $mine = array_filter($state['sessions'] ?? [], fn ($s) => ($s['directory'] ?? '') === $directory);
                if ($mine === []) {
                    usleep(100_000);

                    continue;
                }

                $didSend = false;
                $allEmpty = true;
                foreach ($mine as $sessionId => $session) {
                    if (! empty($session['aborted'])) {
                        send_frame($conn, ['id' => 'evt_x', 'type' => 'session.idle', 'properties' => ['sessionID' => $sessionId]]);
                        $didSend = true;

                        continue;
                    }
                    $queue = $session['queue'] ?? [];
                    if ($queue !== []) {
                        $allEmpty = false;
                        $frame = array_shift($queue);
                        if (isset($frame['__sleep'])) {
                            usleep((int) $frame['__sleep'] * 1_000_000);
                        } elseif (isset($frame['__wait_abort'])) {
                            while (empty(state_load($stateFile)['sessions'][$sessionId]['aborted'])) {
                                usleep(100_000);
                            }
                            send_frame($conn, ['id' => 'evt_x', 'type' => 'session.idle', 'properties' => ['sessionID' => $sessionId]]);
                        } else {
                            send_frame($conn, ['id' => 'evt_'.uniqid(), 'type' => $frame['type'], 'properties' => $frame['properties']]);
                            // A permission ask must be ANSWERED before the
                            // session proceeds: close the SSE connection so
                            // the sequential server can accept the reply.
                            if ($frame['type'] === 'permission.asked' || $frame['type'] === 'permission.v2.asked') {
                                $state = state_load($stateFile);
                                $state['sessions'][$sessionId]['queue'] = $queue;
                                state_save($stateFile, $state);
                                break 2;
                            }
                        }
                        $state = state_load($stateFile);
                        if (isset($frame['type']) && $frame['type'] === 'session.idle') {
                            $state['sessions'][$sessionId]['busy'] = false;
                        }
                        $state['sessions'][$sessionId]['queue'] = $queue;
                        state_save($stateFile, $state);
                        $didSend = true;
                    }
                }

                if ($allEmpty && $didSend === false) {
                    // Drain complete: check whether any session is still busy.
                    $anyBusy = false;
                    foreach ($mine as $session) {
                        if (! empty($session['busy'])) {
                            $anyBusy = true;
                        }
                    }
                    if ($anyBusy) {
                        usleep(100_000);

                        continue;
                    }
                    break; // close the stream; client re-checks session status
                }
                if ($allEmpty) {
                    break;
                }
            }
            break;

        case preg_match('#^/session/(ses_[a-f0-9]+)/diff$#', $path, $m) === 1:
            $busy = ! empty($state['sessions'][$m[1]]['busy']);
            respond($conn, 200, json_encode($busy ? [] : DIFFS));
            break;

        case preg_match('#^/session/(ses_[a-f0-9]+)/abort$#', $path, $m) === 1:
            if (isset($state['sessions'][$m[1]])) {
                $state['sessions'][$m[1]]['aborted'] = true;
                $state['sessions'][$m[1]]['busy'] = false;
                state_save($stateFile, $state);
            }
            respond($conn, 200, json_encode(['ok' => true]));
            break;

        case $path === '/session/status':
            $out = [];
            foreach (($state['sessions'] ?? []) as $sessionId => $session) {
                if (($req['query']['directory'] ?? null) !== null && ($session['directory'] ?? '') !== $req['query']['directory']) {
                    continue;
                }
                $out[$sessionId] = ['type' => ! empty($session['busy']) ? 'busy' : 'idle'];
            }
            respond($conn, 200, json_encode($out));
            break;

        case preg_match('#^/permission/(per_[a-z0-9]+)/reply$#', $path, $m) === 1 && $req['method'] === 'POST':
            $state['permissions'][$m[1]] = json_decode($req['body'], true)['reply'] ?? 'unknown';
            // The scripted session continues once its permission is answered.
            foreach (($state['sessions'] ?? []) as $sessionId => $session) {
                if ($session['busy'] && empty($session['queue']) && ! empty($session['rest'])) {
                    $state['sessions'][$sessionId]['queue'] = $session['rest'];
                    $state['sessions'][$sessionId]['rest'] = [];
                }
            }
            state_save($stateFile, $state);
            respond($conn, 200, json_encode(['ok' => true]));
            break;

        default:
            respond($conn, 404, json_encode(['error' => 'not found']));
    }

    fclose($conn);
}

function header_stream($conn): void
{
    fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nCache-Control: no-cache\r\nConnection: close\r\n\r\n");
}

function send_frame($conn, array $frame): void
{
    fwrite($conn, 'data: '.json_encode($frame)."\n\n");
    flush();
}
