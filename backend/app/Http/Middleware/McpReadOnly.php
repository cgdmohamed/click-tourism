<?php

namespace App\Http\Middleware;

use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confines MCP tokens (ADR-0016) to an allowlist of read-only GET routes.
 *
 * A token carrying "mcp:read" without the full "*" ability is an MCP token.
 * SPA login tokens carry "*" and pass through untouched (createToken default).
 * For MCP tokens the middleware also
 *  - refuses everything while the feature flag mcp.enabled is off (403 mcp_disabled),
 *  - enforces the per-day row budget (429 mcp_budget_exceeded) and the report limiter,
 *  - flags bulk reads (mcp.bulk_read audit event),
 *  - audits every call exactly once (mcp.tool_called or mcp.tool_denied; no payloads, no PII),
 *  - masks contact details in the JSON response when mcp.mask_contacts is on.
 */
class McpReadOnly
{
    /** Internal helper used by the gateway to learn the token owner's branches; not an MCP tool. */
    public const CONTEXT_TOOL = 'mcp_context';

    /** GET route uri => MCP tool name. Exactly the tools of the gateway, never a wildcard. */
    public const ALLOWED = [
        'api/v1/search' => 'erp_search',
        'api/v1/customers' => 'erp_customers_list',
        'api/v1/customers/{customer}' => 'erp_customer_get',
        'api/v1/suppliers' => 'erp_suppliers_list',
        'api/v1/bookings' => 'erp_bookings_list',
        'api/v1/bookings/{booking}' => 'erp_booking_get',
        'api/v1/invoices' => 'erp_invoices_list',
        'api/v1/invoices/{invoice}' => 'erp_invoice_get',
        'api/v1/payments' => 'erp_payments_list',
        'api/v1/expenses' => 'erp_expenses_list',
        'api/v1/leads' => 'erp_leads_list',
        'api/v1/reports/party-statement' => 'erp_party_statement',
        'api/v1/reports/aging' => 'erp_aging',
        'api/v1/reports/trial-balance' => 'erp_trial_balance',
        'api/v1/reports/income-statement' => 'erp_income_statement',
        'api/v1/mcp/context' => self::CONTEXT_TOOL,
    ];

    /** Heavier tools with their own, tighter limiter. */
    public const REPORT_TOOLS = ['erp_party_statement', 'erp_aging', 'erp_trial_balance', 'erp_income_statement'];

    private const CONTACT_KEYS = ['phone', 'email', 'mobile', 'whatsapp'];

    public function __construct(private readonly AuditService $audit, private readonly SettingService $settings) {}

    public static function isMcpToken(mixed $token): bool
    {
        return $token instanceof PersonalAccessToken && $token->can('mcp:read') && ! $token->can('*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! self::isMcpToken($token)) {
            return $next($request);
        }

        $uri = $request->route()?->uri() ?? '';
        $route = '/'.preg_replace('#^api/v1/?#', '', $uri);
        $tool = $request->isMethod('GET') ? (self::ALLOWED[$uri] ?? null) : null;

        $deny = fn (string $reason, string $message, string $code, int $status, array $headers = []) => $this->deny(
            $request, $user->id, $token, $tool, $route, $reason, $message, $code, $status, $headers,
        );

        if (! $this->settings->bool('mcp', 'enabled', false)) {
            return $deny('disabled', 'MCP access is switched off for this installation.', 'mcp_disabled', 403);
        }

        // Employee statements stay out of MCP (blueprint section 4): enforced here, not only in the gateway.
        // The controller validates Laravel's COMBINED input (query + JSON body), so this check reads the same
        // source, allow-lists the party type instead of excluding one value, and read-only tools take no
        // request body at all (a GET with a JSON body is how a query-only check could be bypassed).
        $partyType = $request->input('party_type');
        if (
            $tool === null
            || $this->hasRequestBody($request)
            || ($tool === 'erp_party_statement' && ! (is_string($partyType) && in_array($partyType, ['customer', 'supplier'], true)))
        ) {
            return $deny('scope', 'This token may only use the read-only MCP tools.', 'mcp_scope_denied', 403);
        }

        $internal = $tool === self::CONTEXT_TOOL;

        if (! $internal && in_array($tool, self::REPORT_TOOLS, true)) {
            $key = 'mcp-report:'.$token->getKey();
            if (RateLimiter::tooManyAttempts($key, (int) config('mcp.report_rate_limit_per_minute', 6))) {
                return $deny('report_rate_limited', 'Too many report requests; slow down.', 'rate_limited', 429, [
                    'Retry-After' => (string) RateLimiter::availableIn($key),
                ]);
            }
            RateLimiter::hit($key, 60);
        }

        $budget = $this->dailyBudget();
        if (! $internal && $this->rowsToday($token) >= $budget) {
            return $deny('budget_exceeded', 'The daily MCP row budget for this token is used up. Try again tomorrow.', 'mcp_budget_exceeded', 429, [
                'Retry-After' => (string) max(1, (int) now()->diffInSeconds(now()->addDay()->startOfDay())),
            ]);
        }

        $response = $next($request);
        $status = $response->getStatusCode();

        $rows = 0;
        if ($response instanceof JsonResponse && $status < 300) {
            $rows = $internal ? 0 : self::countRows($response->getData(true));
            if ($rows > 0) {
                $this->addRows($token, $rows);
            }
            $this->detectBulkRead($token, $user->id, $tool, $rows);
        }

        $this->audit->log('mcp.tool_called', null, null, [
            'tool' => $tool,
            'route' => $route,
            'branch' => $request->header('X-Branch-Id'),
            'status' => $status,
            'outcome' => $status < 300 ? 'ok' : (in_array($status, [401, 403], true) ? 'denied' : 'error'),
            'rows' => $rows,
            'token_id' => $token->getKey(),
        ], $user->id);

        if ($response instanceof JsonResponse && $this->settings->bool('mcp', 'mask_contacts', true)) {
            $response->setData(self::mask($response->getData(true)));
        }

        return $response;
    }

    private function deny(Request $request, int $userId, PersonalAccessToken $token, ?string $tool, string $route, string $reason, string $message, string $code, int $status, array $headers = []): Response
    {
        $this->audit->log('mcp.tool_denied', null, null, [
            'tool' => $tool,
            'method' => $request->method(),
            'route' => $route,
            'reason' => $reason,
            'status' => $status,
            'token_id' => $token->getKey(),
        ], $userId);

        return ApiResponse::error($message, $code, $status)->withHeaders($headers);
    }

    /** True when a GET carries body parameters (JSON or form data): MCP tools are parameterised by the query string only. */
    private function hasRequestBody(Request $request): bool
    {
        // An empty body ('' or '[]', what test clients send) carries no parameters and is harmless.
        return $request->request->count() > 0 || $request->json()->count() > 0;
    }

    /** Setting mcp.daily_row_budget, falling back to config when blank or invalid. */
    private function dailyBudget(): int
    {
        // Only a persisted override counts; without one the operator's env/config value applies.
        $stored = $this->settings->get('mcp', 'daily_row_budget');
        $value = $stored !== null && ctype_digit($stored) ? (int) $stored : 0;

        return $value > 0 ? $value : max(1, (int) config('mcp.daily_row_budget', 5000));
    }

    private function rowsKey(PersonalAccessToken $token): string
    {
        return 'mcp-rows:'.$token->getKey().':'.now()->toDateString();
    }

    private function rowsToday(PersonalAccessToken $token): int
    {
        return (int) Cache::get($this->rowsKey($token), 0);
    }

    private function addRows(PersonalAccessToken $token, int $rows): void
    {
        $key = $this->rowsKey($token);
        Cache::add($key, 0, now()->addDays(2));
        Cache::increment($key, $rows);
    }

    /** Many large list pages inside a short window raise one mcp.bulk_read event per window. */
    private function detectBulkRead(PersonalAccessToken $token, int $userId, string $tool, int $rows): void
    {
        if ($rows < (int) config('mcp.bulk_page_min_rows', 20)) {
            return;
        }

        $window = max(1, (int) config('mcp.bulk_window_seconds', 300));
        $key = 'mcp-bulk:'.$token->getKey();
        Cache::add($key, 0, now()->addSeconds($window));
        $pages = (int) Cache::increment($key);

        if ($pages >= (int) config('mcp.bulk_pages', 10) && Cache::add('mcp-bulk-flag:'.$token->getKey(), 1, now()->addSeconds($window))) {
            $this->audit->log('mcp.bulk_read', null, null, [
                'tool' => $tool,
                'pages' => $pages,
                'window_seconds' => $window,
                'rows_today' => $this->rowsToday($token),
                'token_id' => $token->getKey(),
            ], $userId);
        }
    }

    /**
     * Rows in a response: the length of the data list, or the sum of the lists inside a data object
     * (search groups count their items). A single record counts as one row.
     */
    public static function countRows(mixed $body): int
    {
        $data = is_array($body) ? ($body['data'] ?? null) : null;

        return is_array($data) ? max(1, self::rowsIn($data)) : 0;
    }

    private static function rowsIn(array $node, int $depth = 0): int
    {
        if (array_is_list($node)) {
            $grouped = $node !== [];
            $sum = 0;
            foreach ($node as $item) {
                if (is_array($item) && isset($item['items']) && is_array($item['items'])) {
                    $sum += count($item['items']);
                } else {
                    $grouped = false;
                    break;
                }
            }

            return $grouped ? $sum : count($node);
        }

        $sum = 0;
        if ($depth < 3) {
            foreach ($node as $child) {
                if (is_array($child)) {
                    $sum += self::rowsIn($child, $depth + 1);
                }
            }
        }

        return $sum;
    }

    /** Recursively masks phone/email/tax id values and the "subtitle" text of search hits. */
    public static function mask(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $item) {
                $value[$k] = self::mask($item, is_string($k) ? $k : $key);
            }

            return $value;
        }

        if (! is_string($value) || $value === '') {
            return $value;
        }

        if ($key === 'email') {
            return self::maskEmail($value);
        }

        if (in_array($key, self::CONTACT_KEYS, true)) {
            return self::maskPhone($value);
        }

        if ($key === 'tax_id') {
            return '***'.substr(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '', -2);
        }

        if ($key === 'subtitle') {
            $value = preg_replace_callback('/[^\s·@]+@[^\s·@]+/u', fn (array $m) => self::maskEmail($m[0]), $value);

            return preg_replace_callback('/\+?\d[\d\s().-]{5,}\d/u', fn (array $m) => self::maskPhone($m[0]), $value);
        }

        return $value;
    }

    private static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return '***'.substr($digits, -2);
    }

    private static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false || $at === 0
            ? '***'
            : mb_substr($email, 0, 1).'***'.substr($email, $at);
    }
}
