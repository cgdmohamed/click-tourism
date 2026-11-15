<?php

namespace App\Console\Commands;

use App\Modules\Assistant\Models\AssistantAction;
use App\Modules\Assistant\Services\ChatService;
use App\Modules\Assistant\Services\ProviderManager;
use App\Modules\Assistant\Support\ChatResult;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Measures the assistant with the configured provider (ADR-0036): each scenario is one user message sent
 * through the real chat service against the current data, and what came back (tools called, the card it
 * prepared and its values, a form, the status) is checked against what a good answer looks like. Every
 * scenario runs inside a transaction that is rolled back, so nothing is created. It costs tokens, so it is
 * run by hand (before changing the prompt or the model, and after), never by the schedule or the tests'
 * default run.
 */
class AssistantEval extends Command
{
    protected $signature = 'assistant:eval {--file= : Scenarios file (default: resources/assistant/evals/scenarios.json)} {--user=admin@example.com : The user the scenarios run as} {--only= : Run only the scenario ids containing this text} {--min=0 : Fail (exit 1) when fewer than this percent pass}';

    protected $description = 'Run the assistant scenarios against the configured provider and report what passes (costs tokens; creates nothing)';

    public function handle(ChatService $chat, ProviderManager $providers): int
    {
        if (! $providers->usable()) {
            $this->error('The assistant is not usable here: switch it on, accept the data-processing notice and set the provider key first.');

            return self::FAILURE;
        }
        $file = (string) ($this->option('file') ?: resource_path('assistant/evals/scenarios.json'));
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($decoded) || ! is_array($decoded['scenarios'] ?? null)) {
            $this->error('The scenarios file is missing or is not valid JSON.');

            return self::FAILURE;
        }
        $user = User::query()->where('email', (string) $this->option('user'))->first();
        if ($user === null) {
            $this->error('No such user: '.$this->option('user'));

            return self::FAILURE;
        }
        $branch = $user->primaryBranch ?? $user->branches()->first() ?? Branch::query()->orderBy('id')->first();
        Auth::setUser($user);
        request()->headers->set('X-Branch-Id', (string) $branch?->public_id);
        // The evaluation must not be cut short by the day's token budget, whatever an administrator saved in Settings:
        // the effective limit is forced for this run and restored when it ends.
        ProviderManager::forceLimit('daily_token_budget', 1_000_000_000);

        $placeholders = ['{in_20_days}' => now()->addDays(20)->toDateString(), '{next_month}' => now()->addMonthNoOverflow()->format('Y-m')];
        $only = (string) $this->option('only');
        $rows = [];
        $passed = 0;
        $total = 0;
        try {
            foreach ($decoded['scenarios'] as $scenario) {
                if ($only !== '' && ! str_contains((string) $scenario['id'], $only)) {
                    continue;
                }
                $total++;
                $text = strtr((string) $scenario['text'], $placeholders);
                $expect = json_decode(strtr(json_encode($scenario['expect'], JSON_UNESCAPED_UNICODE), array_map(fn ($v) => $v, $placeholders)), true);

                DB::beginTransaction();
                try {
                    $result = $chat->run($user, [['role' => 'user', 'content' => $text]], (string) ($scenario['locale'] ?? 'ar'), $branch);
                    $problems = $this->problems($expect, $result);
                } catch (\Throwable $e) {
                    $problems = ['error: '.class_basename($e)];
                } finally {
                    DB::rollBack();
                }
                $passed += $problems === [] ? 1 : 0;
                $rows[] = [$problems === [] ? 'PASS' : 'FAIL', $scenario['id'], $problems === [] ? '' : implode('; ', $problems)];
            }
        } finally {
            ProviderManager::forceLimit('daily_token_budget', null);
        }

        $this->table(['', 'scenario', 'what is wrong'], $rows);
        $percent = $total === 0 ? 0 : (int) round($passed * 100 / $total);
        $this->info("{$passed}/{$total} passed ({$percent}%)");

        return $percent < (int) $this->option('min') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $expect
     * @return list<string>
     */
    private function problems(array $expect, ChatResult $result): array
    {
        $problems = [];
        $called = $result->toolNames;
        if ($result->form !== null) {
            $called[] = 'erp_ask_user';
        }
        $card = $result->actions[0] ?? null;

        if (isset($expect['status']) && $result->status !== $expect['status']) {
            $problems[] = "status was {$result->status}, expected {$expect['status']}";
        }
        if (! empty($expect['no_tools']) && $called !== []) {
            $problems[] = 'called '.implode(', ', array_unique($called));
        }
        if (! empty($expect['tools_any']) && array_intersect($expect['tools_any'], $called) === []) {
            $problems[] = 'none of '.implode('/', $expect['tools_any']).' was called (got '.($called === [] ? 'nothing' : implode(', ', array_unique($called))).')';
        }
        if (! empty($expect['tools_none']) && ($bad = array_intersect($expect['tools_none'], $called)) !== []) {
            $problems[] = 'must not call '.implode(', ', array_unique($bad));
        }
        if (isset($expect['card']) && ($card !== null) !== (bool) $expect['card']) {
            $problems[] = $expect['card'] ? 'no card was prepared' : 'a card was prepared ('.($card['tool'] ?? '?').')';
        }
        if (isset($expect['card_tool']) && ($card['tool'] ?? null) !== $expect['card_tool']) {
            $problems[] = 'card was '.($card['tool'] ?? 'none').", expected {$expect['card_tool']}";
        }
        if (isset($expect['form']) && ($result->form !== null) !== (bool) $expect['form']) {
            $problems[] = $expect['form'] ? 'no form was shown' : 'a form was shown';
        }
        if (! empty($expect['asks']) && $result->form === null && ! preg_match('/[?؟]/u', $result->answer)) {
            $problems[] = 'it neither showed a form nor asked a question';
        }
        if (! empty($expect['answer_any']) && ! collect($expect['answer_any'])->contains(fn ($w) => str_contains($result->answer, (string) $w))) {
            $problems[] = 'the answer holds none of '.implode('/', $expect['answer_any']);
        }
        if (! empty($expect['args']) && $card !== null) {
            $payload = AssistantAction::query()->where('public_id', $card['id'])->first()?->payload ?? [];
            foreach ($expect['args'] as $key => $want) {
                $got = $payload[$key] ?? null;
                $ok = is_string($want) && str_starts_with($want, '~') ? str_contains((string) $got, substr($want, 1)) : (string) $got === (string) $want;
                if (! $ok) {
                    $problems[] = "{$key} was ".json_encode($got, JSON_UNESCAPED_UNICODE).", expected {$want}";
                }
            }
        }

        return $problems;
    }
}
