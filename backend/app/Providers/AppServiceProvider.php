<?php

namespace App\Providers;

use App\Http\Middleware\McpReadOnly;
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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
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
