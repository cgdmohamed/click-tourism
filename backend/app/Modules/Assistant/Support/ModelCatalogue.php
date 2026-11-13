<?php

namespace App\Modules\Assistant\Support;

/**
 * Pure helpers over the model list a provider returns (ADR-0018): which ids an assistant can chat
 * with, and which one an installation should offer first. No I/O, so the rules are unit-testable.
 */
final class ModelCatalogue
{
    /**
     * Preference order for the recommended model. The first id that the account actually lists
     * wins; when none is listed the newest "mini" model, then the newest chat model, is chosen.
     */
    public const PREFERRED = [
        'gpt-4.1-mini',
        'gpt-4o-mini',
        'gpt-4.1',
        'gpt-4o',
        'o4-mini',
        'o3-mini',
        'gpt-4-turbo',
        'gpt-3.5-turbo',
    ];

    /**
     * Chat families an assistant can use. Anything else (embeddings, audio, images, moderation,
     * search, codex, legacy completion models) is filtered out even when it carries a gpt- prefix.
     */
    private const CHAT_PREFIXES = ['gpt-', 'o1', 'o3', 'o4', 'o5', 'chatgpt-'];

    /** Substrings that mark a non-chat model. Exclusions are checked first. */
    private const NON_CHAT = [
        'embed', 'embedding', 'tts', 'whisper', 'transcri', 'audio', 'realtime', 'image',
        'dall-e', 'sora', 'moderation', 'search', 'codex', 'computer-use', 'computer_use',
        'davinci', 'babbage', 'ada',
    ];

    /** Whether a model id belongs to a family the assistant can hold a chat conversation with. */
    public static function isChat(string $id): bool
    {
        $id = strtolower(trim($id));
        if ($id === '') {
            return false;
        }

        foreach (self::NON_CHAT as $needle) {
            if (str_contains($id, $needle)) {
                return false;
            }
        }

        foreach (self::CHAT_PREFIXES as $prefix) {
            if (str_starts_with($id, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keeps the chat-capable rows, preserving the provider's order. Each row needs at least an id;
     * `created` is optional and only used for the "newest" fallbacks.
     *
     * @param  list<array<string, mixed>>  $models
     * @return list<array{id: string, created: int}>
     */
    public static function chatModels(array $models): array
    {
        $rows = [];
        foreach ($models as $model) {
            $id = is_array($model) ? ($model['id'] ?? null) : null;
            if (! is_string($id) || ! self::isChat($id)) {
                continue;
            }
            $rows[] = ['id' => $id, 'created' => (int) (is_array($model) ? ($model['created'] ?? 0) : 0)];
        }

        return $rows;
    }

    /**
     * Deterministic recommendation: preference list, then the newest id containing "mini", then
     * the newest remaining id. Returns null when nothing is available.
     *
     * @param  list<array<string, mixed>>  $models  rows with at least `id` (and `created` when known)
     */
    public static function recommend(array $models): ?string
    {
        $rows = [];
        foreach ($models as $model) {
            $id = is_array($model) ? ($model['id'] ?? null) : null;
            if (is_string($id) && $id !== '') {
                $rows[] = ['id' => $id, 'created' => (int) ($model['created'] ?? 0)];
            }
        }

        if ($rows === []) {
            return null;
        }

        $ids = array_column($rows, 'id');
        foreach (self::PREFERRED as $preferred) {
            if (in_array($preferred, $ids, true)) {
                return $preferred;
            }
        }

        $mini = self::newest($rows, static fn (string $id): bool => str_contains(strtolower($id), 'mini'));

        return $mini ?? self::newest($rows);
    }

    /**
     * The row with the largest `created`. Ties fall back to the lexicographically smallest id so the
     * result never depends on the order the provider happens to return.
     *
     * @param  list<array{id: string, created: int}>  $rows
     */
    private static function newest(array $rows, ?callable $filter = null): ?string
    {
        $best = null;
        foreach ($rows as $row) {
            if ($filter !== null && ! $filter($row['id'])) {
                continue;
            }
            if ($best === null
                || $row['created'] > $best['created']
                || ($row['created'] === $best['created'] && strcmp($row['id'], $best['id']) < 0)) {
                $best = $row;
            }
        }

        return $best['id'] ?? null;
    }
}
