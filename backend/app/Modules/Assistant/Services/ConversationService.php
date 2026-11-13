<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Models\AssistantConversation;
use App\Modules\Assistant\Models\AssistantMessage;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saved assistant conversations (ADR-0022). Always scoped to the owner: no method reads another
 * user's conversation. `assistant.history_retention_days` = 0 turns saving off.
 */
class ConversationService
{
    public const DEFAULT_RETENTION_DAYS = 90;

    public function __construct(private readonly SettingService $settings) {}

    public function retentionDays(): int
    {
        $stored = $this->settings->get('assistant', 'history_retention_days');

        return $stored !== null && ctype_digit($stored) ? (int) $stored : self::DEFAULT_RETENTION_DAYS;
    }

    public function enabled(): bool
    {
        return $this->retentionDays() > 0;
    }

    /** @return Collection<int, AssistantConversation> */
    public function list(User $user, ?Branch $branch, int $limit = 100)
    {
        return AssistantConversation::query()
            ->where('user_id', $user->id)
            ->where('branch_id', $branch?->id)
            ->orderByDesc('last_message_at')
            ->limit($limit)
            ->get();
    }

    public function find(User $user, string $publicId): ?AssistantConversation
    {
        return AssistantConversation::query()->where('user_id', $user->id)->where('public_id', $publicId)->first();
    }

    /**
     * Saves one turn (the user's last question and the answer). A missing or foreign conversation id
     * starts a new conversation titled from the question.
     *
     * @param  list<array<string, mixed>>  $citations
     * @param  list<string>  $actionIds
     */
    public function saveTurn(User $user, ?Branch $branch, ?string $conversationId, string $question, string $answer, array $citations, bool $truncated, array $actionIds = []): ?AssistantConversation
    {
        if (! $this->enabled()) {
            return null;
        }

        return DB::transaction(function () use ($user, $branch, $conversationId, $question, $answer, $citations, $truncated, $actionIds) {
            $conversation = $conversationId !== null ? $this->find($user, $conversationId) : null;
            $conversation ??= AssistantConversation::query()->create([
                'user_id' => $user->id,
                'branch_id' => $branch?->id,
                'title' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $question)), 0, 80),
                'last_message_at' => now(),
            ]);

            AssistantMessage::query()->create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => $question]);
            AssistantMessage::query()->create([
                'conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => $answer,
                'citations' => $citations, 'truncated' => $truncated, 'actions' => $actionIds,
            ]);
            $conversation->forceFill(['last_message_at' => now()])->save();

            return $conversation;
        });
    }

    public function delete(AssistantConversation $conversation): void
    {
        $conversation->delete();
    }

    public function deleteAll(User $user, ?Branch $branch): int
    {
        return AssistantConversation::query()->where('user_id', $user->id)->where('branch_id', $branch?->id)->delete();
    }

    /** Removes conversations older than the retention window (all of them when saving is off). */
    public function prune(): int
    {
        $days = $this->retentionDays();
        $query = AssistantConversation::query();
        if ($days > 0) {
            $query->where('last_message_at', '<', now()->subDays($days));
        }

        return $query->delete();
    }
}
