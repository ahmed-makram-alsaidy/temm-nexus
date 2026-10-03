<?php

// Local mock of the AgentRouter chat-completions gateway (acceptance fixture).
//
// Behaves like agentrouter.org's observed contract:
//   - requests WITHOUT the codex_cli_rs User-Agent are refused with the
//     unauthorized_client_error envelope (the gateway's client gate);
//   - requests WITH it receive a normal 200 chat completion.
//
// Usage: php scripts/mock-agentrouter.php
// Prints the bound 127.0.0.1 port as its first stdout line, then serves
// requests until it is terminated.

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: {$errstr}\n");
    exit(1);
}

$name = stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1)."\n";
flush();

while (true) {
    $conn = stream_socket_accept($server, 3600);
    if ($conn === false) {
        continue;
    }

    $raw = '';
    $headersDone = false;
    $contentLength = 0;
    while (! feof($conn)) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw .= $chunk;
        if (! $headersDone && ($pos = strpos($raw, "\r\n\r\n")) !== false) {
            $headersDone = true;
            foreach (explode("\r\n", substr($raw, 0, $pos)) as $line) {
                if (preg_match('/^content-length:\s*(\d+)/i', $line, $m)) {
                    $contentLength = (int) $m[1];
                }
            }
        }
        if ($headersDone) {
            $pos = strpos($raw, "\r\n\r\n");
            if (strlen($raw) - ($pos + 4) >= $contentLength) {
                break;
            }
        }
    }

    preg_match('/^user-agent:\s*(.*)$/mi', $raw, $ua);
    $userAgent = trim($ua[1] ?? '');

    if (! str_starts_with($userAgent, 'codex_cli_rs/')) {
        reply($conn, 403, [
            'error' => [
                'code' => 'unauthorized_client_error',
                'message' => 'unauthorized client detected',
            ],
        ]);
    } else {
        preg_match("/\r\n\r\n(.*)$/s", $raw, $body);
        $payload = json_decode($body[1] ?? '{}', true) ?: [];
        $lastUser = '';
        foreach ((array) ($payload['messages'] ?? []) as $message) {
            if (($message['role'] ?? '') === 'user') {
                $lastUser = (string) ($message['content'] ?? '');
            }
        }
        $content = str_contains($lastUser, 'HELLO')
            ? 'HELLO'
            : 'MOCK-OK ('.($payload['model'] ?? 'unknown-model').' responding normally)';

        reply($conn, 200, [
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 42, 'completion_tokens' => 8],
        ]);
    }

    fclose($conn);
}

function reply($conn, int $status, array $body): void
{
    $json = json_encode($body);
    $text = "HTTP/1.1 {$status} OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($json)."\r\nConnection: close\r\n\r\n{$json}";
    fwrite($conn, $text);
}
