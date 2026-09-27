<?php

namespace App\Services\ControlPlane;

/**
 * Redacts secret-bearing values from log lines before display or storage.
 * Patterns cover Laravel env keys, headers, cookies, tokens, and hashes.
 */
class LogSanitizer
{
    public const REDACTED = '[REDACTED]';

    public static function sanitize(string $line): string
    {
        // Bearer / Basic credentials first (whole credential, rest of token included).
        $line = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/=]{8,}/i', '$1 '.self::REDACTED, $line) ?? $line;

        // key=value / key: value pairs for sensitive keys.
        $line = preg_replace_callback(
            '/\b(password|passwd|pwd|secret|client_secret|api[_-]?key|access[_-]?token|auth[_-]?token|bearer|authorization|cookie|session|remember[_-]?token|private[_-]?key|db[_-]?password|redis[_-]?password|app[_-]?key|mail[_-]?password)\b\s*([=:>]+|=>)\s*(\[[^\]]*\]|"[^"]*"|\'[^\']*\'|\S+)/i',
            fn ($m) => $m[1].$m[2].self::REDACTED,
            $line
        ) ?? $line;

        // Long hex/base64 blobs that smell like tokens/hashes (conservative: 32+ chars).
        $line = preg_replace('/\b(token|hash|signature|digest)(["\']?\s*[:=]\s*["\']?)[A-Za-z0-9+\/=]{32,}["\']?/i', '$1$2'.self::REDACTED, $line) ?? $line;

        return $line;
    }

    /** @return list<string> names of sensitive keys still literally present (test helper) */
    public static function leakedKeys(string $line): array
    {
        $found = [];
        foreach (['password', 'passwd', 'secret', 'api_key', 'authorization', 'cookie', 'db_password', 'redis_password', 'app_key', 'bearer'] as $key) {
            if (preg_match('/'.preg_quote($key, '/').'\s*([=:>]+|=>)\s*(?!\[REDACTED\])(?:"[^"]+"|\'[^\']+\'|\S+)/i', $line)) {
                $found[] = $key;
            }
        }

        return $found;
    }
}
