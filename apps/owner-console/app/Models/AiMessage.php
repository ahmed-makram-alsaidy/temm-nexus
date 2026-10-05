<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 0.6.0 Phase F — one turn of a Nexus AI conversation.
 *
 * Rows hold ONLY what the UI may render: user/assistant text, humanised tool
 * activity (tool name, safe label, ok/denied, duration), proposed action
 * cards in their DISPLAY state, and the provider/model route that answered.
 * The authoritative plan state always lives in `ai_action_plans` — the card
 * JSON here is a mirror refreshed whenever the page acts on a plan.
 *
 * `error` on a user row is the classified failure of a turn that never got
 * its answer (kind + safe technical line). It is cleared the moment a retry
 * succeeds, so history never shows a failure that no longer exists.
 */
class AiMessage extends Model
{
    use HasUuids;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    protected $fillable = [
        'ai_conversation_id', 'role', 'text', 'tools', 'actions', 'route', 'error',
    ];

    protected function casts(): array
    {
        return [
            'tools' => 'array',
            'actions' => 'array',
            'route' => 'array',
            'error' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    /**
     * The transcript-turn shape the page renders. Raw payloads never exist on
     * this model, so the shape here is already UI-safe.
     *
     * @return array{role: string, text: string, tools: list<array<string, mixed>>, actions: list<array<string, mixed>>}
     */
    public function toTurn(): array
    {
        return [
            'role' => $this->role,
            'text' => (string) $this->text,
            'tools' => $this->tools ?? [],
            'actions' => $this->actions ?? [],
        ];
    }
}
