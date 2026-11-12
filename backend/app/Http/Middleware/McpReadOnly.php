<?php

namespace App\Http\Middleware;

use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confines MCP tokens (ADR-0016) to an allowlist of read-only GET routes.
 *
 * A token carrying "mcp:read" without the full "*" ability is an MCP token.
 * SPA login tokens carry "*" and pass through untouched (createToken default).
 * For MCP tokens the middleware also audits every call (no payloads, no PII)
 * and masks contact details in the JSON response when mcp.mask_contacts is on.
 */
class McpReadOnly
{
    /** GET route uri => MCP tool name. Stage 1 exposes exactly three tools. */
    public const ALLOWED = [
        'api/v1/search' => 'erp_search',
        'api/v1/bookings' => 'erp_bookings_list',
        'api/v1/invoices' => 'erp_invoices_list',
    ];

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

        if ($tool === null) {
            $this->audit->log('mcp.tool_denied', null, null, [
                'method' => $request->method(),
                'route' => $route,
                'token_id' => $token->getKey(),
            ], $user->id);

            return ApiResponse::error('This token may only use the read-only MCP tools.', 'mcp_scope_denied', 403);
        }

        $response = $next($request);

        $this->audit->log('mcp.tool_called', null, null, [
            'tool' => $tool,
            'route' => $route,
            'branch' => $request->header('X-Branch-Id'),
            'status' => $response->getStatusCode(),
            'token_id' => $token->getKey(),
        ], $user->id);

        if ($response instanceof JsonResponse && $this->settings->bool('mcp', 'mask_contacts', true)) {
            $response->setData(self::mask($response->getData(true)));
        }

        return $response;
    }

    /** Recursively masks phone/email values and the "subtitle" text of search hits. */
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
