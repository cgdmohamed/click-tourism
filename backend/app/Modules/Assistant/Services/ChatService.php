<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Support\ChatResult;
use App\Modules\Assistant\Support\OutputSanitizer;
use App\Modules\Assistant\Support\ProviderException;
use App\Modules\Assistant\Support\Redactor;
use App\Modules\Assistant\Support\TurnTimeoutException;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use Illuminate\Support\Facades\Cache;

/**
 * One stateless chat turn (ADR-0017): the client sends the whole conversation, the server runs the
 * tool loop against the provider and returns the answer. Nothing about the conversation is stored;
 * the only trace is the audit row `assistant.chat` (user, branch, tool names, token counts, status).
 *
 * The model can call only the catalogue tools; each call runs as the current user through
 * ToolExecutor. The server caps tool calls per turn, accounts tokens per user per day, redacts
 * what leaves the server, sanitises the answer and builds the citations itself from the calls it
 * really executed.
 */
class ChatService
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly ToolCatalogue $catalogue,
        private readonly ToolExecutor $executor,
        private readonly OutputSanitizer $output,
        private readonly AuditService $audit,
        private readonly SettingService $settings,
    ) {}

    public function tokenBudget(): int
    {
        return $this->providers->limit('daily_token_budget');
    }

    public function tokensToday(User $user): int
    {
        return (int) Cache::get($this->tokensKey($user), 0);
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     *
     * @throws ProviderException when the provider fails (after the audit row was written)
     */
    public function run(User $user, array $messages, string $locale, ?Branch $branch): ChatResult
    {
        $provider = $this->providers->provider();
        $redactor = new Redactor($this->settings->bool('assistant', 'redact_pii', true));
        $maxCalls = $this->providers->limit('max_tool_calls_per_turn');
        $options = ['model' => $this->providers->model(), 'max_output_tokens' => $this->providers->limit('max_output_tokens')];
        $tools = $this->catalogue->modelTools();

        $conversation = [['role' => 'system', 'content' => $this->systemPrompt($locale, $branch)]];
        foreach ($messages as $message) {
            $conversation[] = ['role' => $message['role'], 'content' => $redactor->redact($message['content'])];
        }

        $prompt = $completion = $calls = $toolErrors = $rejected = $rounds = 0;
        $executed = [];
        $citations = [];
        $records = [];
        $truncated = false;
        $status = 'ok';
        $answer = null;
        $capReached = false;
        $deadline = microtime(true) + max(1, (int) config('assistant.turn_timeout', 100));

        try {
            // At most max calls + 1 tool rounds, then one forced text round.
            while ($rounds < $maxCalls + 2) {
                if ($rounds > 0 && $this->tokensToday($user) >= $this->tokenBudget()) {
                    $status = 'budget_exceeded';
                    $truncated = true;
                    break;
                }

                // The whole turn is bounded: each provider call gets the time that is left (at most the per-call limit).
                $remaining = (int) floor($deadline - microtime(true));
                if ($remaining < 3) {
                    $this->log($user, $branch, $locale, 'timeout', $options['model'], $prompt, $completion, $executed, $toolErrors, $rejected, $rounds, $calls);
                    throw new TurnTimeoutException('The assistant turn ran out of time.');
                }

                $rounds++;
                $reply = $provider->chat($conversation, $tools, $options + [
                    'allow_tools' => ! $capReached,
                    'timeout' => min((int) config('assistant.provider_timeout', 60), $remaining),
                ]);
                $prompt += $reply->promptTokens;
                $completion += $reply->completionTokens;
                $this->addTokens($user, $reply->promptTokens + $reply->completionTokens);
                if ($reply->finishReason === 'length') {
                    $truncated = true;
                }

                if ($reply->toolCalls === [] || $capReached) {
                    $answer = $reply->content;
                    break;
                }

                $conversation[] = [
                    'role' => 'assistant',
                    'content' => $reply->content,
                    'tool_calls' => array_map(fn (array $call) => [
                        'id' => $call['id'],
                        'type' => 'function',
                        'function' => ['name' => $call['name'], 'arguments' => $call['arguments']],
                    ], $reply->toolCalls),
                ];

                foreach ($reply->toolCalls as $call) {
                    if ($calls >= $maxCalls) {
                        $capReached = $truncated = true;
                        $status = 'tool_limit';
                        $conversation[] = $this->toolMessage($call['id'], 'tool_limit_reached', 'The limit of tool calls for this answer is reached. Answer with what you have and say what is missing.');

                        continue;
                    }
                    $calls++;
                    $outcome = $this->executor->execute($user, $call['name'], $call['arguments'], $redactor, $branch?->public_id, $locale);
                    $conversation[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => $outcome->text];

                    if ($outcome->rejected) {
                        $rejected++;
                    } elseif ($outcome->ok) {
                        $executed[] = $outcome->tool;
                    } else {
                        $toolErrors++;
                        $executed[] = $outcome->tool;
                    }
                    if ($outcome->ok) {
                        $truncated = $truncated || $outcome->truncated;
                        $citations[] = $outcome->citation;
                        array_push($records, ...$outcome->records);
                    }
                }
            }
        } catch (ProviderException $e) {
            $this->log($user, $branch, $locale, 'provider_error', $options['model'], $prompt, $completion, $executed, $toolErrors, $rejected, $rounds, $calls);
            throw $e;
        }

        $text = $answer === null ? '' : $redactor->restore($this->output->clean($answer, (int) config('assistant.max_answer_chars')));
        if ($text === '') {
            $text = $locale === 'ar'
                ? 'لم أتمكن من تكوين إجابة. يرجى إعادة صياغة السؤال.'
                : 'I could not form an answer. Please rephrase the question.';
            $status = $status === 'ok' ? 'empty_answer' : $status;
        }

        $result = new ChatResult(
            $text,
            $this->citationsFor($text, $citations, $records),
            $prompt,
            $completion,
            $truncated,
            $status,
            $executed,
            $toolErrors,
            $rejected,
            $rounds,
        );
        $this->log($user, $branch, $locale, $status, $options['model'], $prompt, $completion, $executed, $toolErrors, $rejected, $rounds, $calls);

        return $result;
    }

    /**
     * Server-built citations only: one per successful tool call (deduplicated), plus the rows of
     * those calls whose id the answer names. The model cannot add or alter a citation.
     *
     * @param  list<array<string, mixed>>  $calls
     * @param  list<array<string, mixed>>  $records
     * @return list<array{tool: string, entity: string, id: ?string, route: string, as_of: string}>
     */
    private function citationsFor(string $answer, array $calls, array $records): array
    {
        $out = [];
        $seen = [];
        $candidates = [...$calls, ...array_filter($records, fn (array $r) => str_contains($answer, $r['id']))];
        foreach ($candidates as $citation) {
            $key = $citation['tool'].'|'.$citation['entity'].'|'.($citation['id'] ?? '').'|'.$citation['route'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $citation;
        }

        return array_slice($out, 0, 30);
    }

    private function toolMessage(string $callId, string $code, string $message): array
    {
        return [
            'role' => 'tool',
            'tool_call_id' => $callId,
            'content' => "<erp_error>\n".json_encode(['error' => ['code' => $code, 'message' => $message]])."\n</erp_error>",
        ];
    }

    private function systemPrompt(string $locale, ?Branch $branch): string
    {
        $file = resource_path('assistant/'.config('assistant.system_prompt'));
        $prompt = trim((string) file_get_contents($file));

        return $prompt."\n\nContext: interface language = {$locale}; today = ".now()->toDateString()
            .'; active branch id = '.($branch?->public_id ?? 'none').'. Tool calls always use this branch.';
    }

    private function tokensKey(User $user): string
    {
        return 'assistant-tokens:'.$user->getKey().':'.now()->toDateString();
    }

    private function addTokens(User $user, int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }
        $key = $this->tokensKey($user);
        Cache::add($key, 0, now()->addDays(2));
        Cache::increment($key, $tokens);
    }

    /** @param  list<string>  $tools */
    private function log(User $user, ?Branch $branch, string $locale, string $status, string $model, int $prompt, int $completion, array $tools, int $toolErrors, int $rejected, int $rounds, int $calls): void
    {
        $this->audit->log('assistant.chat', null, null, [
            'status' => $status,
            'provider' => $this->providers->providerName(),
            'model' => $model,
            'locale' => $locale,
            'branch' => $branch?->public_id,
            'tools' => $tools,
            'tool_calls' => $calls,
            'tool_errors' => $toolErrors,
            'rejected_tools' => $rejected,
            'rounds' => $rounds,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
            'redact_pii' => $this->settings->bool('assistant', 'redact_pii', true),
        ], $user->id, $branch?->id);
    }
}
