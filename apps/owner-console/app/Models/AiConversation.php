<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * 0.6.0 Phase F — one Nexus AI conversation.
 *
 * A conversation is owned by exactly one user and bound to exactly one
 * resolved scope (platform, workspace, or project). The history list never
 * crosses either boundary: every query filters by the acting user AND the
 * current context, so two users (or the same user at two scopes) each see
 * their own threads and nothing else.
 *
 * The title is derived from the first user message — an internal id is never
 * shown in the UI.
 */
class AiConversation extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'scope', 'workspace_id', 'project_id', 'title', 'last_active_at',
    ];

    protected function casts(): array
    {
        return [
            'last_active_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'ai_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The scope signature this conversation belongs to, from a resolved
     * context. Used both when creating a thread and when filtering history.
     *
     * @param  \App\Services\Ai\AiContext  $context
     * @return array{scope: string, workspace_id: int|null, project_id: int|null}
     */
    public static function signatureFor($context): array
    {
        return [
            'scope' => $context->scope->value,
            'workspace_id' => $context->workspace?->getKey(),
            'project_id' => $context->project?->getKey(),
        ];
    }

    /** A human title derived from the opening question — no ids, no truncation mid-word. */
    public static function titleFrom(string $text): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return Str::limit($title, 60);
    }

    /**
     * The most recent conversation for this user + context, only when it has
     * something to show — a fresh context starts with a welcome state.
     */
    public static function latestFor($context, int $userId): ?self
    {
        return static::query()
            ->where('user_id', $userId)
            ->where('scope', $context->scope->value)
            ->where('workspace_id', $context->workspace?->getKey())
            ->where('project_id', $context->project?->getKey())
            ->whereHas('messages')
            ->orderByDesc('last_active_at')
            ->first();
    }
}
