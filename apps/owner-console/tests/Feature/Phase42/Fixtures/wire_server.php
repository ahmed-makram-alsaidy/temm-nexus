<?php

// Wire-capture server (regression fixture). Accepts $argv[2] sequential HTTP
// requests on a loopback port, stores the RAW request bytes of each into
// <capture-dir>/capture_N.txt and answers each with a minimal valid OpenAI
// chat completion. Prints the bound port as its first stdout line.

$captureDir = rtrim($argv[1], '/\\');
$requestCount = (int) ($argv[2] ?? 2);

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: {$errstr}\n");
    exit(1);
}

$name = stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1)."\n";
flush();

for ($i = 1; $i <= $requestCount; $i++) {
    $conn = stream_socket_accept($server, 20);
    if ($conn === false) {
        fwrite(STDERR, "no connection #{$i}\n");
        exit(1);
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

    file_put_contents($captureDir."/capture_{$i}.txt", $raw);

    $body = json_encode([
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'WIRE-OK'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ]);
    fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n{$body}");
    fclose($conn);
}

fclose($server);
