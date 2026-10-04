<?php

namespace Tests\Unit\Agent;

use App\Services\Agent\Runtimes\OpenCode\OpenCodeClient;
use App\Services\Agent\Runtimes\OpenCode\OpenCodeEventNormalizer;
use PHPUnit\Framework\TestCase;

class OpenCodeEventNormalizerTest extends TestCase
{
    public function test_text_part_becomes_a_bounded_message(): void
    {
        $long = str_repeat('a', 900);
        $event = OpenCodeEventNormalizer::normalize([
            'type' => 'message.part.updated',
            'properties' => ['sessionID' => 'ses_1', 'part' => ['type' => 'text', 'text' => $long]],
        ]);

        $this->assertNotNull($event);
        $this->assertSame('message', $event->type);
        $this->assertSame('ses_1', $event->sessionId);
        $this->assertLessThanOrEqual(503, mb_strlen((string) $event->summary));
    }

    public function test_reasoning_content_never_leaks(): void
    {
        $event = OpenCodeEventNormalizer::normalize([
            'type' => 'message.part.updated',
            'properties' => ['sessionID' => 'ses_1', 'part' => ['type' => 'reasoning', 'text' => 'SECRET-CHAIN-OF-THOUGHT']],
        ]);

        $this->assertNotNull($event);
        $this->assertSame('thinking', $event->type);
        $this->assertStringNotContainsString('SECRET-CHAIN-OF-THOUGHT', json_encode($event->summary ?? '').json_encode($event->payload) ?: '');
    }

    public function test_bash_tool_produces_command_metadata_without_output_content(): void
    {
        $event = OpenCodeEventNormalizer::normalize([
            'type' => 'message.part.updated',
            'properties' => ['sessionID' => 'ses_1', 'part' => [
                'type' => 'tool', 'tool' => 'bash', 'callID' => 'c1',
                'state' => [
                    'status' => 'completed',
                    'input' => ['command' => 'php test.php'],
                    'output' => "line1\nSECRET-STDOUT\n",
                    'metadata' => ['exit' => 0],
                    'time' => ['start' => 1000, 'end' => 3500],
                ],
            ]],
        ]);

        $this->assertSame('command', $event->type);
        $this->assertSame('php test.php', $event->payload['command']);
        $this->assertSame(0, $event->payload['exit_code']);
        $this->assertNotNull($event->payload['duration_ms']);
        $this->assertSame(strlen("line1\nSECRET-STDOUT\n"), $event->payload['output_bytes']);
        $this->assertArrayNotHasKey('output', $event->payload);
        $this->assertStringNotContainsString('SECRET-STDOUT', json_encode($event->payload));
    }

    public function test_edit_tool_emits_editing_then_file_changed(): void
    {
        $editing = OpenCodeEventNormalizer::normalize([
            'type' => 'message.part.updated',
            'properties' => ['sessionID' => 'ses_1', 'part' => [
                'type' => 'tool', 'tool' => 'edit', 'callID' => 'c2',
                'state' => ['status' => 'running', 'input' => ['filePath' => 'src/a.php'], 'time' => ['start' => 1]],
            ]],
        ]);
        $changed = OpenCodeEventNormalizer::normalize([
            'type' => 'message.part.updated',
            'properties' => ['sessionID' => 'ses_1', 'part' => [
                'type' => 'tool', 'tool' => 'edit', 'callID' => 'c2',
                'state' => ['status' => 'completed', 'input' => ['filePath' => 'src/a.php'], 'time' => ['start' => 1, 'end' => 2]],
            ]],
        ]);

        $this->assertSame('editing', $editing->type);
        $this->assertSame('file_changed', $changed->type);
        $this->assertSame('src/a.php', $changed->payload['path']);
    }

    public function test_read_tool_emits_reading(): void
    {
        $event = OpenCodeEventNormalizer::normalize([
            'type' => 'message.part.updated',
            'properties' => ['sessionID' => 'ses_1', 'part' => [
                'type' => 'tool', 'tool' => 'read', 'callID' => 'c3',
                'state' => ['status' => 'completed', 'input' => ['filePath' => 'README.md'], 'title' => 'Read README.md', 'time' => ['start' => 1, 'end' => 2]],
            ]],
        ]);

        $this->assertSame('reading', $event->type);
        $this->assertSame('README.md', $event->payload['path']);
    }

    public function test_permission_ask_carries_the_request_id(): void
    {
        $event = OpenCodeEventNormalizer::normalize([
            'type' => 'permission.asked',
            'properties' => ['sessionID' => 'ses_1', 'id' => 'per_9', 'permission' => 'bash', 'patterns' => ['php test.php']],
        ]);

        $this->assertSame('permission', $event->type);
        $this->assertSame('per_9', $event->payload['request_id']);
        $this->assertSame('bash', $event->payload['permission']);
    }

    public function test_idle_event_completes_the_stream(): void
    {
        $event = OpenCodeEventNormalizer::normalize([
            'type' => 'session.idle',
            'properties' => ['sessionID' => 'ses_1'],
        ]);

        $this->assertSame('completed', $event->type);
    }

    public function test_error_events_are_sanitized(): void
    {
        $event = OpenCodeEventNormalizer::normalize([
            'type' => 'session.error',
            'properties' => ['sessionID' => 'ses_1', 'message' => 'upstream Authorization: Bearer sk-supersecret123 failed'],
        ]);

        $this->assertSame('error', $event->type);
        $this->assertStringNotContainsString('sk-supersecret123', (string) $event->summary);
        $this->assertStringContainsString('[redacted]', (string) $event->summary);
    }

    public function test_unrelated_events_are_dropped(): void
    {
        $this->assertNull(OpenCodeEventNormalizer::normalize(['type' => 'lsp.updated', 'properties' => []]));
        $this->assertNull(OpenCodeEventNormalizer::normalize(['type' => 'pty.exited', 'properties' => []]));
        $this->assertNull(OpenCodeEventNormalizer::normalize(['type' => 'catalog.updated', 'properties' => []]));
        $this->assertNull(OpenCodeEventNormalizer::normalize(['garbage' => true]));
    }

    // ── SSE line parsing ────────────────────────────────────────────────

    public function test_sse_line_parsing(): void
    {
        $frame = OpenCodeClient::parseSseLine('data: {"type":"session.idle","properties":{"sessionID":"ses_1"}}');
        $this->assertSame('session.idle', $frame['type']);
        $this->assertSame('ses_1', $frame['properties']['sessionID']);

        $this->assertNull(OpenCodeClient::parseSseLine(': keepalive'));
        $this->assertNull(OpenCodeClient::parseSseLine('event: something'));
        $this->assertNull(OpenCodeClient::parseSseLine('data: [DONE]'));
        $this->assertNull(OpenCodeClient::parseSseLine('data: {broken json'));
    }

    public function test_model_splitting(): void
    {
        $this->assertSame(['google', 'gemini-2.5-pro'], OpenCodeClient::splitModel('google/gemini-2.5-pro'));
        $this->assertSame(['openrouter', 'deep/deepseek/r1'], OpenCodeClient::splitModel('openrouter/deep/deepseek/r1'));
        $this->assertSame([null, null], OpenCodeClient::splitModel(null));
        $this->assertSame([null, null], OpenCodeClient::splitModel(''));
        $this->assertSame([null, null], OpenCodeClient::splitModel('no-slash'));
    }

    public function test_directory_comparison_is_separator_agnostic(): void
    {
        $this->assertTrue(OpenCodeClient::sameDirectory('C:/tmp/ws', 'C:\tmp\ws'));
        $this->assertFalse(OpenCodeClient::sameDirectory('C:/tmp/ws', 'C:/tmp/other'));
    }
}
