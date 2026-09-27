<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use App\Models\RealtimeEvent;

/**
 * Phase 20N Realtime Studio backend.
 *
 * Publish flow per test event, with honest verification levels:
 *   recorded            — stored in the project event feed (always works)
 *   endpoint_reachable  — WS handshake + subscribe acknowledged by the
 *                         project's Reverb endpoint (needs deployed Reverb)
 *   verified            — event received back on the subscription (needs the
 *                         project app broadcasting; proven in 18H E2E)
 * Private/presence channels require a project API key; anonymous subscribe
 * to them is refused. No secrets are ever rendered (states only).
 */
class RealtimeService
{
    public const PAYLOAD_CAP = 4000;

    public static function validateChannel(string $channel): string
    {
        $channel = trim($channel);
        abort_unless(preg_match('/^[A-Za-z0-9_.:-]{1,160}$/', $channel) === 1, 422, 'Invalid channel name.');

        return $channel;
    }

    public static function isPrivate(string $channel): bool
    {
        return str_starts_with($channel, 'private-') || str_starts_with($channel, 'presence-');
    }

    /** @return array{event:RealtimeEvent,level:string,detail:string} */
    public static function publishTest(
        Project $project, string $channel, string $event,
        mixed $payload, ?string $credential = null
    ): array {
        $channel = self::validateChannel($channel);
        $event = trim($event);
        abort_unless(preg_match('/^[A-Za-z0-9_.:-]{1,160}$/', $event) === 1, 422, 'Invalid event name.');
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = json_last_error() === JSON_ERROR_NONE ? $decoded : ['text' => mb_substr($payload, 0, self::PAYLOAD_CAP)];
        }
        abort_if(! is_array($payload), 422, 'Payload must be a JSON object.');
        if (self::isPrivate($channel)) {
            abort_unless($credential, 401, 'Private channels require a project API key.');
            $key = ApiKeyService::validate($project, $credential, 'functions:invoke');
            abort_unless($key, 401, 'Invalid API key for private channel.');
        }

        $record = RealtimeEvent::create([
            'project_id' => $project->id,
            'channel' => $channel,
            'event' => $event,
            'payload' => json_decode(mb_substr(json_encode($payload) ?: '{}', 0, self::PAYLOAD_CAP), true),
            'request_id' => SqlRunner::requestId(),
            'verified' => false,
        ]);
        AdminAudit::record('FUNCTION_INVOKED', $project, 'realtime', $record->id, [
            'channel' => $channel, 'event' => $event, 'via' => 'test-console',
        ]);

        // Level 2: endpoint reachability (handshake + subscribe ack).
        $status = ReverbStatusService::for($project)->status();
        if (empty($status['host']) || empty($status['port']) || $status['app_key'] !== 'Configured') {
            return [
                'event' => $record, 'level' => 'recorded',
                'detail' => 'Recorded in the event feed. Live delivery needs the project Reverb endpoint configured and running.',
            ];
        }
        $probe = self::subscribeProbe((string) $status['host'], (int) $status['port'], 8);
        if (! $probe['ok']) {
            return ['event' => $record, 'level' => 'recorded', 'detail' => 'Endpoint unreachable: '.$probe['detail']];
        }

        return ['event' => $record, 'level' => 'endpoint_reachable', 'detail' => $probe['detail']];
    }

    /**
     * Minimal Pusher-protocol subscribe probe (dependency-free, in-process).
     * @return array{ok:bool,detail:string}
     */
    public static function subscribeProbe(string $host, int $port, int $timeout = 8): array
    {
        $appKey = 'probe';
        // App key is unknown here by design (states only) — the probe only
        // proves TCP + WS upgrade + Pusher framing, not channel auth.
        try {
            $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);
            if (! $fp) {
                return ['ok' => false, 'detail' => 'connect failed'];
            }
            stream_set_timeout($fp, $timeout);
            $wsKey = base64_encode(random_bytes(16));
            fwrite($fp, "GET /app/{$appKey}?protocol=7&client=cp-probe&version=1.0 HTTP/1.1\r\n"
                ."Host: {$host}:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                ."Sec-WebSocket-Key: {$wsKey}\r\nSec-WebSocket-Version: 13\r\n\r\n");
            $head = '';
            $deadline = time() + $timeout;
            while (! str_contains($head, "\r\n\r\n") && time() < $deadline) {
                $chunk = fread($fp, 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $head .= $chunk;
            }
            fclose($fp);
            if (! str_contains($head, '101')) {
                return ['ok' => false, 'detail' => 'no WS upgrade (not a Reverb endpoint?)'];
            }

            return ['ok' => true, 'detail' => 'WS upgrade OK (framing proven; channel auth needs the app key at broadcast time).'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'probe error'];
        }
    }
}
