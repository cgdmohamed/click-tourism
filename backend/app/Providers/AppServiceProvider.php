<?php

namespace App\Providers;

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
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

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
    }
}
