<?php

namespace App\Modules\Assistant\Services;

use App\Http\Middleware\McpReadOnly;
use App\Modules\Assistant\Support\Redactor;
use App\Modules\Assistant\Support\ResultSanitizer;
use App\Modules\Assistant\Support\ToolOutcome;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs one assistant tool call as the signed-in user (ADR-0017).
 *
 * The call is an internal GET sub-request to the same route the MCP gateway uses, dispatched
 * through the router so the route's own middleware applies unchanged: authentication of the
 * CURRENT user, permission checks, BranchScope, validation. A user without the permission gets
 * the route's 403, mapped to a tool error; no data. The result then gets the MCP treatment:
 * contact masking, the daily row budget (setting mcp.daily_row_budget, counted per user), the
 * report limiter, and the untrusted-data envelope with truncation.
 */
class ToolExecutor
{
    public function __construct(
        private readonly ToolCatalogue $catalogue,
        private readonly SettingService $settings,
        private readonly ResultSanitizer $sanitizer,
    ) {}

    public function execute(User $user, string $name, string $rawArguments, Redactor $redactor, ?string $branchPublicId, string $locale): ToolOutcome
    {
        if (! $this->catalogue->has($name)) {
            return $this->fail($name, 'unknown_tool', 'This tool does not exist. Only the listed read-only tools can be used.', rejected: true);
        }

        $args = $rawArguments === '' ? [] : json_decode($rawArguments, true);
        if (! is_array($args)) {
            return $this->fail($name, 'validation_failed', 'The arguments are not a JSON object.');
        }
        $args = $this->restore($args, $redactor);

        $problems = $this->catalogue->errors($name, $args);
        if ($problems !== []) {
            return $this->fail($name, 'validation_failed', 'Invalid arguments: '.implode('; ', array_slice($problems, 0, 5)));
        }

        $tool = $this->catalogue->find($name);

        if ($tool['report']) {
            $key = 'assistant-report:'.$user->getKey();
            if (RateLimiter::tooManyAttempts($key, (int) config('mcp.report_rate_limit_per_minute', 6))) {
                return $this->fail($name, 'rate_limited', 'Too many report requests; slow down.');
            }
            RateLimiter::hit($key, 60);
        }

        if ($this->rowsToday($user) >= $this->rowBudget()) {
            return $this->fail($name, 'budget_exceeded', 'The daily row budget is used up. Do not retry before tomorrow.');
        }

        $path = $this->catalogue->path($name, $args);
        $response = $this->dispatch($user, $path, $this->catalogue->query($name, $args), $branchPublicId, $locale);
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getContent(), true);

        if ($status >= 400 || ! is_array($body)) {
            return $this->httpError($name, $status, is_array($body) ? $body : []);
        }

        $rows = McpReadOnly::countRows($body);
        if ($rows > 0) {
            $key = $this->rowsKey($user);
            Cache::add($key, 0, now()->addDays(2));
            Cache::increment($key, $rows);
        }

        $mask = $this->maskContacts();
        if ($mask) {
            $body = McpReadOnly::mask($body);
        }
        $wrapped = $this->sanitizer->envelope($name, $path, $branchPublicId, $body, $mask, (int) config('assistant.max_tool_result_bytes'), $this->settings->bool('assistant', 'redact_pii', true));

        $asOf = now()->toIso8601String();
        $entity = $this->catalogue->entity($name);
        $citation = [
            'tool' => $name,
            'entity' => $entity,
            'id' => $this->catalogue->isRecordTool($name) ? (string) $args['id'] : null,
            'route' => $path,
            'as_of' => $asOf,
        ];

        return new ToolOutcome($name, true, null, $wrapped['text'], $wrapped['truncated'], $citation, $this->records($name, $entity, $path, $body, $asOf));
    }

    /** Contacts stay masked when either the MCP masking setting or the assistant redaction setting is on. */
    private function maskContacts(): bool
    {
        return $this->settings->bool('mcp', 'mask_contacts', true) || $this->settings->bool('assistant', 'redact_pii', true);
    }

    private function dispatch(User $user, string $path, array $query, ?string $branchPublicId, string $locale): Response
    {
        $request = Request::create('/api/v1'.$path, 'GET', $query);
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Accept-Language', $locale);
        if ($branchPublicId !== null) {
            $request->headers->set('X-Branch-Id', $branchPublicId);
        }
        $request->setUserResolver(fn () => $user);
        Auth::guard('sanctum')->setUser($user);

        $original = app('request');
        app()->instance('request', $request);

        try {
            return app('router')->dispatch($request);
        } catch (Throwable $e) {
            $handler = app(ExceptionHandler::class);
            $handler->report($e);

            return $handler->render($request, $e);
        } finally {
            app()->instance('request', $original);
        }
    }

    /** Stable, non-leaking codes (same set as the gateway). The upstream body is never forwarded. */
    private function httpError(string $tool, int $status, array $body): ToolOutcome
    {
        return match (true) {
            $status === 401 => $this->fail($tool, 'unauthenticated', 'The session is not authenticated.'),
            $status === 403 && ($body['code'] ?? null) === 'account_disabled' => $this->fail($tool, 'account_disabled', 'This account is disabled.'),
            $status === 403 => $this->fail($tool, 'forbidden', 'Not available to your role or branch.'),
            $status === 404 => $this->fail($tool, 'not_found', 'Record not found.'),
            $status === 422 => $this->fail($tool, 'validation_failed', 'The request was rejected as invalid.'),
            $status === 429 => $this->fail($tool, 'rate_limited', 'Too many requests; slow down.'),
            default => $this->fail($tool, 'upstream_unavailable', 'The ERP is temporarily unavailable.'),
        };
    }

    private function fail(string $tool, string $code, string $message, bool $rejected = false): ToolOutcome
    {
        return new ToolOutcome($tool, false, $code, $this->sanitizer->error($code, $message), rejected: $rejected);
    }

    /** Puts the user's own typed values back into tool arguments (e.g. a phone number to search). */
    private function restore(array $args, Redactor $redactor): array
    {
        array_walk_recursive($args, function (&$value) use ($redactor) {
            if (is_string($value)) {
                $value = $redactor->restore($value);
            }
        });

        return $args;
    }

    /**
     * Rows of a result that the model may cite by id: list rows with a public id, and search hits
     * (group type to entity). The ChatService keeps only those whose id the answer actually names.
     *
     * @return list<array{tool: string, entity: string, id: string, labels: list<string>, route: string, as_of: string}>
     */
    private function records(string $tool, string $entity, string $path, array $body, string $asOf): array
    {
        $data = $body['data'] ?? null;
        if (! is_array($data) || $this->catalogue->isRecordTool($tool)) {
            return [];
        }

        $found = [];
        $add = function (mixed $row, string $rowEntity) use (&$found, $tool, $path, $asOf): void {
            $id = is_array($row) ? ($row['id'] ?? null) : null;
            if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id) === 1 && count($found) < 100) {
                // The document number or name the answer prints: the screen links it to the record.
                $labels = [];
                foreach (['number', 'name', 'name_ar', 'name_en'] as $field) {
                    $value = is_string($row[$field] ?? null) ? trim($row[$field]) : '';
                    if (mb_strlen($value) >= 4 && mb_strlen($value) <= 80) {
                        $labels[] = $value;
                    }
                }
                $found[] = ['tool' => $tool, 'entity' => $rowEntity, 'id' => $id, 'labels' => array_values(array_unique($labels)), 'route' => $path, 'as_of' => $asOf];
            }
        };

        if ($tool === 'erp_search') {
            $singular = ['customers' => 'customer', 'suppliers' => 'supplier', 'bookings' => 'booking', 'payments' => 'payment', 'leads' => 'lead'];
            foreach ((array) ($data['groups'] ?? []) as $group) {
                $rowEntity = $singular[(string) ($group['type'] ?? '')] ?? null;
                foreach ($rowEntity === null ? [] : (array) ($group['items'] ?? []) as $item) {
                    $add($item, $rowEntity);
                }
            }

            return $found;
        }

        if ($entity !== 'report' && array_is_list($data)) {
            foreach ($data as $row) {
                $add($row, $entity);
            }
        }

        return $found;
    }

    private function rowBudget(): int
    {
        // Same resolution as McpReadOnly: only a persisted override counts, else the env/config value.
        $stored = $this->settings->get('mcp', 'daily_row_budget');
        $value = $stored !== null && ctype_digit($stored) ? (int) $stored : 0;

        return $value > 0 ? $value : max(1, (int) config('mcp.daily_row_budget', 5000));
    }

    private function rowsKey(User $user): string
    {
        return 'assistant-rows:'.$user->getKey().':'.now()->toDateString();
    }

    private function rowsToday(User $user): int
    {
        return (int) Cache::get($this->rowsKey($user), 0);
    }
}
