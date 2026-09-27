<?php
// Minimal Pusher-protocol WebSocket client (no dependencies).
// Usage: php reverb-handshake.php <host> <port> <appKey> <channel> <expectEvent> <timeoutSec> <resultFile>
[$script, $host, $port, $key, $channel, $expect, $timeout, $resultFile] = $argv;

$fail = function (string $detail) use ($resultFile) {
    file_put_contents($resultFile, json_encode(['ok' => false, 'detail' => $detail, 'ran_at' => gmdate('c')]));
    fwrite(STDERR, "FAIL: $detail\n");
    exit(1);
};

$fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);
if (! $fp) {
    $fail("connect failed: $errstr");
}
stream_set_timeout($fp, (int) $timeout);

$wsKey = base64_encode(random_bytes(16));
$req = "GET /app/{$key}?protocol=7&client=cp-proof&version=1.0&flash=false HTTP/1.1\r\n"
    ."Host: {$host}:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
    ."Sec-WebSocket-Key: {$wsKey}\r\nSec-WebSocket-Version: 13\r\n\r\n";
fwrite($fp, $req);

$head = '';
while (! str_contains($head, "\r\n\r\n")) {
    $chunk = fread($fp, 1024);
    if ($chunk === false || $chunk === '') {
        $fail('handshake unreadable');
    }
    $head .= $chunk;
}
if (! str_contains($head, '101')) {
    $fail('no 101 Switching Protocols: '.substr($head, 0, 120));
}
// The server may pipeline the first frame with the headers — keep leftovers.
$buffer = substr($head, strpos($head, "\r\n\r\n") + 4);

$readBytes = function (int $n) use ($fp, &$buffer) {
    while (strlen($buffer) < $n) {
        $chunk = fread($fp, $n - strlen($buffer));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buffer .= $chunk;
    }
    $out = substr($buffer, 0, $n);
    $buffer = substr($buffer, $n);

    return $out;
};

$readFrame = function () use ($readBytes) {
    $hdr = $readBytes(2);
    if (strlen($hdr) < 2) {
        return null;
    }
    $b1 = ord($hdr[0]);
    $b2 = ord($hdr[1]);
    $opcode = $b1 & 0x0F;
    $len = $b2 & 0x7F;
    if ($len === 126) {
        $len = unpack('n', $readBytes(2))[1];
    } elseif ($len === 127) {
        $len = unpack('J', $readBytes(8))[1];
    }
    if ($opcode === 0x8) {
        return ['close' => true];
    }

    return ['opcode' => $opcode, 'payload' => $readBytes($len)];
};

$sendFrame = function (string $data) use ($fp) {
    $mask = random_bytes(4);
    $len = strlen($data);
    $hdr = chr(0x81);
    if ($len < 126) {
        $hdr .= chr(0x80 | $len);
    } elseif ($len < 65536) {
        $hdr .= chr(0x80 | 126).pack('n', $len);
    } else {
        $hdr .= chr(0x80 | 127).pack('J', $len);
    }
    $masked = '';
    for ($i = 0; $i < $len; $i++) {
        $masked .= $data[$i] ^ $mask[$i % 4];
    }
    fwrite($fp, $hdr.$mask.$masked);
};

// 1. connection_established
$frame = $readFrame();
if (! $frame || ! isset($frame['payload']) || ! str_contains($frame['payload'], 'pusher:connection_established')) {
    $fail('no connection_established');
}

// 2. subscribe
$sendFrame(json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => $channel]]));
$deadline = time() + (int) $timeout;
$subscribed = false;
$received = null;
while (time() < $deadline) {
    $frame = $readFrame();
    if (! $frame) {
        break;
    }
    if (isset($frame['close'])) {
        $fail('server closed connection');
    }
    $payload = $frame['payload'] ?? '';
    if (str_contains($payload, 'pusher_internal:subscription_succeeded')) {
        $subscribed = true;
        // Signal readiness, then keep listening for the test event.
        file_put_contents($resultFile.'.ready', '1');
        continue;
    }
    $decoded = json_decode($payload, true);
    if (($decoded['event'] ?? '') === $expect) {
        $received = $decoded['data'] ?? $payload;
        break;
    }
    if (isset($decoded['event']) && str_starts_with($decoded['event'], 'pusher:')) {
        continue;
    }
}

if (! $subscribed) {
    $fail('subscribe not acknowledged');
}
if ($received === null) {
    $fail("subscribed but event '{$expect}' not received within timeout");
}

file_put_contents($resultFile, json_encode([
    'ok' => true,
    'detail' => "Received {$expect} on {$channel}",
    'payload' => is_string($received) ? substr($received, 0, 300) : $received,
    'ran_at' => gmdate('c'),
]));
fclose($fp);
echo "PASS: received {$expect}\n";
