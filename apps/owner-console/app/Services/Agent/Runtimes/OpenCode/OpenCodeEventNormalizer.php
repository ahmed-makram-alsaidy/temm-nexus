<?php

namespace App\Services\Agent\Runtimes\OpenCode;

use App\Services\Agent\Contract\AgentRuntimeEvent;
use Illuminate\Support\Str;

/**
 * Normalizes raw OpenCode SSE frames (official `/event` stream, schema
 * verified against GET /doc) into TEMM's runtime event vocabulary.
 *
 * Privacy boundary: hidden reasoning content is NEVER copied into summaries
 * or payloads — only its existence ("agent is reasoning"). Tool outputs are
 * reduced to byte counts and truncation flags. Text produced for the user is
 * bounded and passed through; everything else is structural metadata.
 */
class OpenCodeEventNormalizer
{
    /** Max characters of user-facing text copied into a summary. */
    protected const SUMMARY_LIMIT = 500;

    /** Tool names whose input references a filesystem path. */
    protected const PATH_INPUT_KEYS = ['filePath', 'path', 'file_path', 'notebookPath', 'directory'];

    public static function normalize(array $frame, ?string $sessionId = null): ?AgentRuntimeEvent
    {
        $type = $frame['type'] ?? null;
        $properties = is_array($frame['properties'] ?? null) ? $frame['properties'] : [];

        $sid = is_string($properties['sessionID'] ?? null) ? $properties['sessionID'] : null;

        return match ($type) {
            'server.connected' => new AgentRuntimeEvent('status', 'Connected to runtime', [], $sessionId),
            'session.status' => new AgentRuntimeEvent('status', self::sessionStatusLabel($properties), [], $sid ?? $sessionId),
            'message.part.updated' => self::partUpdated($properties, $sid ?? $sessionId),
            'file.edited' => self::fileEdited($properties, $sid ?? $sessionId),
            'permission.asked', 'permission.v2.asked' => self::permissionAsked($properties, $sid ?? $sessionId),
            'permission.replied', 'permission.v2.replied' => new AgentRuntimeEvent(
                'permission',
                'Permission decision recorded',
                ['outcome' => 'replied'],
                $sid ?? $sessionId
            ),
            'session.idle' => new AgentRuntimeEvent('completed', 'Agent session went idle', [], $sid ?? $sessionId),
            'session.error' => self::sessionError($properties, $sid ?? $sessionId),
            'message.updated' => self::messageUpdated($properties, $sid ?? $sessionId),
            default => null, // unrelated/bus events are intentionally dropped
        };
    }

    /** @param  array<string, mixed>  $properties */
    protected static function partUpdated(array $properties, ?string $sessionId): ?AgentRuntimeEvent
    {
        $part = is_array($properties['part'] ?? null) ? $properties['part'] : null;
        if ($part === null) {
            return null;
        }

        $partType = is_string($part['type'] ?? null) ? $part['type'] : '';

        switch ($partType) {
            case 'text':
                // Final user-facing assistant text — safe to surface, bounded.
                $text = is_string($part['text'] ?? null) ? $part['text'] : '';

                return $text === '' ? null : new AgentRuntimeEvent('message', Str::limit($text, self::SUMMARY_LIMIT), [], $sessionId);

            case 'reasoning':
                // Existence only. Never the content.
                return new AgentRuntimeEvent('thinking', 'Agent is reasoning', [], $sessionId);

            case 'step-start':
                return new AgentRuntimeEvent('status', 'Agent step started', [], $sessionId);

            case 'step-finish':
                return new AgentRuntimeEvent('status', 'Agent step finished', self::usageFromPart($part), $sessionId);

            case 'tool':
                return self::toolPart($part, $sessionId);

            case 'subtask':
            case 'agent':
                return new AgentRuntimeEvent('status', 'Sub-agent activity', [], $sessionId);

            case 'patch':
                return new AgentRuntimeEvent('editing', 'Applying patch to workspace', [], $sessionId);

            default:
                return null;
        }
    }

    /** @param  array<string, mixed>  $part */
    protected static function toolPart(array $part, ?string $sessionId): ?AgentRuntimeEvent
    {
        $tool = is_string($part['tool'] ?? null) ? $part['tool'] : '';
        $state = is_array($part['state'] ?? null) ? $part['state'] : [];
        $status = is_string($state['status'] ?? null) ? $state['status'] : 'pending';
        $input = is_array($state['input'] ?? null) ? $state['input'] : [];
        $metadata = is_array($state['metadata'] ?? null) ? $state['metadata'] : [];
        $title = is_string($state['title'] ?? null) ? Str::limit($state['title'], self::SUMMARY_LIMIT) : null;

        $path = null;
        foreach (self::PATH_INPUT_KEYS as $key) {
            if (is_string($input[$key] ?? null) && $input[$key] !== '') {
                $path = $input[$key];
                break;
            }
        }

        $time = is_array($state['time'] ?? null) ? $state['time'] : [];
        $durationMs = null;
        if (isset($time['start'], $time['end']) && is_numeric($time['start']) && is_numeric($time['end'])) {
            $durationMs = (int) round((($time['end'] - $time['start']) / 1000)); // ms (µs→ms if needed upstream keeps ms semantics)
        }

        // Shell-like tools → command ledger entries (metadata only).
        if (in_array($tool, ['bash', 'shell'], true)) {
            $command = is_string($input['command'] ?? null) ? $input['command'] : null;
            $exitCode = isset($metadata['exit']) && is_numeric($metadata['exit']) ? (int) $metadata['exit'] : null;
            $output = is_string($state['output'] ?? null) ? $state['output'] : '';
            $payload = [
                'phase' => $status === 'running' || $status === 'pending' ? 'started' : 'finished',
                'command' => $command !== null ? Str::limit($command, 2000) : ($title ?? 'unknown command'),
                'exit_code' => $exitCode,
                'duration_ms' => $durationMs,
                'output_bytes' => $output !== '' ? strlen($output) : null,
                'failed' => $status === 'error' || ($exitCode !== null && $exitCode !== 0),
            ];

            return new AgentRuntimeEvent('command', $payload['command'], $payload, $sessionId);
        }

        // File mutation tools.
        if (in_array($tool, ['edit', 'write', 'multiedit', 'patch'], true)) {
            $payload = ['tool' => $tool, 'path' => $path];

            if ($status === 'completed') {
                return new AgentRuntimeEvent('file_changed', $title ?? 'File changed', $payload, $sessionId);
            }

            return new AgentRuntimeEvent('editing', $title ?? 'Editing workspace file', $payload, $sessionId);
        }

        // Read/search tools.
        if (in_array($tool, ['read', 'glob', 'grep', 'list', 'ls'], true)) {
            return new AgentRuntimeEvent('reading', $title ?? 'Reading workspace', array_filter([
                'tool' => $tool, 'path' => $path,
            ]), $sessionId);
        }

        // Everything else (task, webfetch, …) is generic activity.
        return new AgentRuntimeEvent('status', $title ?? "Tool: {$tool}", ['tool' => $tool], $sessionId);
    }

    /** @param  array<string, mixed>  $properties */
    protected static function fileEdited(array $properties, ?string $sessionId): ?AgentRuntimeEvent
    {
        $file = is_string($properties['file'] ?? null) ? $properties['file'] : null;

        return new AgentRuntimeEvent('file_changed', 'Workspace file changed', array_filter([
            'path' => $file,
        ]), $sessionId);
    }

    /** @param  array<string, mixed>  $properties */
    protected static function permissionAsked(array $properties, ?string $sessionId): ?AgentRuntimeEvent
    {
        $id = is_string($properties['id'] ?? null) ? $properties['id'] : (is_string($properties['requestID'] ?? null) ? $properties['requestID'] : null);
        $permission = is_string($properties['permission'] ?? null) ? $properties['permission'] : null;
        $patterns = is_array($properties['patterns'] ?? null) ? array_slice(array_filter($properties['patterns'], 'is_string'), 0, 10) : [];

        if ($id === null) {
            return null;
        }

        return new AgentRuntimeEvent('permission', 'Runtime permission requested', [
            'request_id' => $id,
            'permission' => $permission,
            'patterns' => $patterns,
        ], $sessionId);
    }

    /** @param  array<string, mixed>  $properties */
    protected static function sessionError(array $properties, ?string $sessionId): AgentRuntimeEvent
    {
        // Classify, don't copy: raw upstream error text may embed URLs/keys.
        $raw = $properties['message'] ?? ($properties['error'] ?? '');
        $raw = is_string($raw) ? $raw : (json_encode($raw) ?: '');

        return new AgentRuntimeEvent('error', Str::limit(self::sanitizeError($raw), self::SUMMARY_LIMIT), [], $sessionId);
    }

    /** @param  array<string, mixed>  $properties */
    protected static function messageUpdated(array $properties, ?string $sessionId): ?AgentRuntimeEvent
    {
        $info = is_array($properties['info'] ?? null) ? $properties['info'] : null;

        if ($info === null) {
            return null;
        }

        $tokens = is_array($info['tokens'] ?? null) ? $info['tokens'] : null;
        $cost = is_numeric($info['cost'] ?? null) ? (float) $info['cost'] : null;

        if ($tokens === null && $cost === null) {
            return null;
        }

        return new AgentRuntimeEvent('status', 'Session usage recorded', array_filter([
            'tokens' => $tokens,
            'cost' => $cost,
        ], fn ($v) => $v !== null), $sessionId);
    }

    protected static function sessionStatusLabel(array $properties): string
    {
        $status = is_array($properties['status'] ?? null) ? $properties['status'] : [];
        $type = is_string($status['type'] ?? null) ? $status['type'] : 'unknown';

        return match ($type) {
            'busy' => 'Agent is working',
            'retry' => 'Agent retrying after a transient error',
            'idle' => 'Agent is idle',
            default => 'Agent status: '.$type,
        };
    }

    /** Strip anything secret-shaped before an upstream message is displayed. */
    public static function sanitizeError(string $message): string
    {
        // Authorization headers, bearer tokens, api-key query params.
        $message = preg_replace('/(authorization\s*:\s*)(\S+)/i', '$1[redacted]', $message) ?? $message;
        $message = preg_replace('/(bearer\s+)[A-Za-z0-9._\-]+/i', '$1[redacted]', $message) ?? $message;
        $message = preg_replace('/((?:api[_-]?key|key|token|password)\s*[=:]\s*)\S+/i', '$1[redacted]', $message) ?? $message;
        $message = preg_replace('/\b(sk|pk)-[A-Za-z0-9]{8,}/', '[redacted]', $message) ?? $message;

        return $message;
    }

    /** @param  array<string, mixed>  $part */
    protected static function usageFromPart(array $part): array
    {
        $metadata = is_array($part['metadata'] ?? null) ? $part['metadata'] : [];
        $tokens = is_array($metadata['tokens'] ?? null) ? $metadata['tokens'] : null;
        $cost = is_numeric($metadata['cost'] ?? null) ? (float) $metadata['cost'] : null;

        return array_filter([
            'tokens' => $tokens,
            'cost' => $cost,
        ], fn ($v) => $v !== null);
    }
}
