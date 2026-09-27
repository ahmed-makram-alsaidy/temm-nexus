<?php

declare(strict_types=1);

namespace Platform\BackendSdk;

/**
 * Realtime helpers for server-to-server code.
 *
 * PHP does not subscribe to Reverb channels (browsers / Flutter do that with
 * the JS/Dart SDKs). This helper validates channel names with the same rule
 * the server enforces (`^[A-Za-z0-9_.:-]{1,160}$`) and builds the Pusher
 * WebSocket URL operators paste into client apps.
 */
class Realtime
{
    public static function validateChannel(string $channel): string
    {
        $channel = trim($channel);
        if (preg_match('/^[A-Za-z0-9_.:-]{1,160}$/', $channel) !== 1) {
            throw new \InvalidArgumentException('Invalid channel name.');
        }

        return $channel;
    }

    public static function isPrivate(string $channel): bool
    {
        return str_starts_with($channel, 'private-') || str_starts_with($channel, 'presence-');
    }

    public static function wsUrl(string $host, int $port, string $appKey, string $scheme = 'ws'): string
    {
        return "{$scheme}://{$host}:{$port}/app/{$appKey}?protocol=7";
    }
}
