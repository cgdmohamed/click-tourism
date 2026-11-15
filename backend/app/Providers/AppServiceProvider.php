<?php

namespace App\Providers;

use App\Http\Middleware\McpReadOnly;
use App\Modules\Assistant\Services\ProviderManager;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\RoundingPolicy;
use App\Modules\Integrations\Adapters\GraphMetaLeadFetcher;
use App\Modules\Integrations\Adapters\GraphWhatsAppSender;
use App\Modules\Integrations\Contracts\MetaLeadFetcher;
use App\Modules\Integrations\Contracts\WhatsAppSender;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\EmployeeAdvance;
use App\Modules\Parties\Models\EmployeeCustody;
use App\Modules\Parties\Models\Supplier;
use App\Support\ApiResponse;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolved once per request/job; SettingService::set() flushes it.
        $this->app->scoped(RoundingPolicy::class);

        // Integrations sit behind adapter interfaces; tests swap or Http::fake these.
        $this->app->bind(MetaLeadFetcher::class, GraphMetaLeadFetcher::class);
        $this->app->bind(WhatsAppSender::class, GraphWhatsAppSender::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Password reset (ADR-0031): the link opens the SPA page, and the mail is written in both languages.
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim((string) config('app.frontend_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset()));
        ResetPassword::toMailUsing(function ($user, string $token) {
            $url = rtrim((string) config('app.frontend_url'), '/').'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset());
            $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return (new MailMessage)
                ->subject('إعادة تعيين كلمة المرور / Reset your password')
                ->line('طلبنا إعادة تعيين كلمة المرور لحسابك. الرابط صالح لمدة '.$minutes.' دقيقة ويُستخدم مرة واحدة.')
                ->line('We received a request to reset your password. The link is valid for '.$minutes.' minutes and works once.')
                ->action('إعادة التعيين / Reset password', $url)
                ->line('إذا لم تطلب ذلك فتجاهل هذه الرسالة، ولن يتغير شيء. / If you did not ask for this, ignore this message and nothing changes.');
        });

        // Party/source type aliases are persisted in financial tables. Keep
        // this map stable so polymorphic eager loading never relies on PHP
        // class names in stored accounting data.
        Relation::morphMap([
            'customer' => Customer::class,
            'supplier' => Supplier::class,
            'employee' => Employee::class,
            'advance' => EmployeeAdvance::class,
            'custody' => EmployeeCustody::class,
        ]);

        // Public branding (login page): its own counter so it never consumes the
        // login/demo-status budget that shares the default per-IP throttle key.
        RateLimiter::for('branding', fn (Request $request) => Limit::perMinute(120)->by('branding:'.$request->ip()));

        // Report exports: 20 per minute per user, PDFs (the heaviest format)
        // additionally capped at 6 per minute. Keys are namespaced so other
        // throttled routes (search, ...) never consume the export budget.
        RateLimiter::for('report-exports', function (Request $request): array {
            $who = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
            $limits = [Limit::perMinute(20)->by('report-exports:'.$who)];
            if ($request->query('format') === 'pdf') {
                $limits[] = Limit::perMinute(6)->by('report-exports-pdf:'.$who);
            }

            return $limits;
        });

        // Assistant chat (ADR-0017): per user, limit from the setting assistant.rate_limit_per_minute
        // (default 20). A refusal is audited as assistant.denied.
        RateLimiter::for('assistant-chat', function (Request $request): Limit {
            $limit = Limit::perMinute(app(ProviderManager::class)->limit('rate_limit_per_minute'))
                ->by('assistant:'.($request->user()?->getAuthIdentifier() ?? $request->ip()));

            return $limit->response(function (Request $request, array $headers) {
                app(AuditService::class)->log('assistant.denied', null, null, ['reason' => 'rate_limited', 'status' => 429], $request->user()?->id);

                return ApiResponse::error('Too many assistant requests; slow down.', 'assistant_rate_limited', 429)->withHeaders($headers);
            });
        });

        // MCP tokens (mcp:read only): 60 requests/minute per token (ADR-0016).
        // Other tokens (SPA) are not limited by this limiter.
        RateLimiter::for('mcp-read', function (Request $request): Limit {
            $token = $request->user()?->currentAccessToken();

            if (! $token instanceof PersonalAccessToken || ! McpReadOnly::isMcpToken($token)) {
                return Limit::none();
            }

            return Limit::perMinute((int) config('mcp.rate_limit_per_minute', 60))->by('mcp-read:'.$token->getKey());
        });
    }
}
