<?php

namespace App\Modules\Core\Services;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\FiscalYearSuggestionService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\User;
use App\Modules\Treasury\Models\CashAccount;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CrmSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\TreasurySeeder;
use Database\Seeders\UserSeeder;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * First-run setup of ONE installation (single company, self-hosted).
 *
 * Creates company profile, base currency, fiscal calendar, chart of accounts,
 * roles and the initial administrator. It never installs demo users, sample
 * customers or customer-specific data (no branch other than the head office,
 * tax defaults to off with a 0 rate), and it never uses a built-in password:
 * the administrator password must be supplied by the operator.
 */
class InstallationService
{
    /** Passwords that must never become an administrator password. */
    public const WEAK_PASSWORDS = [
        'password', 'password1', 'password123', 'changeme', 'change-me', 'changeit', 'admin', 'administrator',
        'letmein', 'welcome', 'qwerty', 'secret', 'tourism', 'tourismerp', 'example', 'erpadmin',
    ];

    public function __construct(
        private readonly SettingService $settings,
        private readonly FiscalYearSuggestionService $fiscal,
        private readonly AuditService $audit,
    ) {}

    /** The schema must exist; the install itself is not a migration. */
    public function schemaReady(): bool
    {
        foreach (['users', 'settings', 'branches', 'accounts', 'journal_entries', 'currencies', 'roles'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /** An installation is "installed" once it has any user or an install marker. */
    public function isInstalled(): bool
    {
        return User::query()->exists()
            || Setting::query()->where('group', 'general')->where('key', 'installed_at')->exists();
    }

    /**
     * Validates the operator input. Returns the normalised data or throws
     * ValidationException. Nothing is written.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $currencies = array_column(CurrencySeeder::currencies(), 'code');
        $input['base_currency'] = strtoupper(trim((string) ($input['base_currency'] ?? '')));
        $input['admin_email'] = strtolower(trim((string) ($input['admin_email'] ?? '')));

        $validator = Validator::make($input, [
            'company_name_en' => ['required', 'string', 'max:120'],
            'company_name_ar' => ['nullable', 'string', 'max:120'],
            // A relative storage path only (the file is uploaded later); never a URL or a traversal.
            'logo_path' => ['nullable', 'string', 'max:255', 'regex:/^(?!\/)(?!.*\.\.)(?![a-z][a-z0-9+.-]*:)[A-Za-z0-9_\-\/\.]+\.(png|jpe?g|svg|webp)$/i'],
            'base_currency' => ['required', 'in:'.implode(',', $currencies)],
            'fiscal_start' => ['required', 'regex:/^\d{2}-\d{2}$/', function (string $attribute, mixed $value, \Closure $fail): void {
                [$month, $day] = array_map('intval', explode('-', (string) $value));
                if (! checkdate($month, $day, 2001)) {
                    $fail('The fiscal start must be a valid MM-DD date (02-29 is not allowed).');
                }
            }],
            'timezone' => ['required', 'in:'.implode(',', DateTimeZone::listIdentifiers())],
            'locale' => ['required', 'in:ar,en'],
            'tax_enabled' => ['required', 'boolean'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_inclusive' => ['nullable', 'boolean'],
            'branch_name_en' => ['required', 'string', 'max:120'],
            'branch_name_ar' => ['nullable', 'string', 'max:120'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email:rfc', 'max:255'],
            'admin_password' => ['required', 'string', 'min:12', 'max:128', function (string $attribute, mixed $value, \Closure $fail) use ($input): void {
                $error = $this->passwordError((string) $value, (string) $input['admin_email']);
                if ($error !== null) {
                    $fail($error);
                }
            }],
        ]);

        $data = $validator->validate();
        $data['company_name_ar'] = ($data['company_name_ar'] ?? '') !== '' ? $data['company_name_ar'] : $data['company_name_en'];
        $data['branch_name_ar'] = ($data['branch_name_ar'] ?? '') !== '' ? $data['branch_name_ar'] : $data['branch_name_en'];
        $data['logo_path'] = (string) ($data['logo_path'] ?? '');
        $data['tax_enabled'] = filter_var($data['tax_enabled'], FILTER_VALIDATE_BOOL);
        $data['tax_rate'] = (string) ($data['tax_rate'] ?? '0');
        $data['tax_inclusive'] = filter_var($data['tax_inclusive'] ?? false, FILTER_VALIDATE_BOOL);

        return $data;
    }

    /** Returns a message when the password is unacceptable, otherwise null. */
    public function passwordError(string $password, string $email = ''): ?string
    {
        if (in_array(strtolower($password), self::WEAK_PASSWORDS, true)) {
            return 'This password is a well-known default and cannot be used.';
        }
        if (count(array_unique(mb_str_split($password))) < 5) {
            return 'The password is too repetitive; use at least 5 different characters.';
        }
        $local = strtolower(explode('@', $email)[0] ?? '');
        if (strlen($local) >= 4 && str_contains(strtolower($password), $local)) {
            return 'The password must not contain the e-mail address name.';
        }

        return null;
    }

    /**
     * Writes everything in one transaction: a failure leaves the database as
     * it was. Returns the administrator.
     *
     * @param  array<string, mixed>  $data  The result of validate().
     */
    public function install(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $base = (string) $data['base_currency'];

            // Reference data (idempotent seeders, no users).
            foreach ([SettingSeeder::class, CurrencySeeder::class, ChartOfAccountsSeeder::class, TreasurySeeder::class, CrmSeeder::class, RolePermissionSeeder::class] as $seeder) {
                app($seeder)->run();
            }

            // Cash/bank accounts follow the installation's base currency.
            CashAccount::query()->update(['currency_code' => $base]);

            $this->writeSettings($data);

            $branch = Branch::query()->updateOrCreate(
                ['code' => 'HQ'],
                [
                    'name_ar' => $data['branch_name_ar'],
                    'name_en' => $data['branch_name_en'],
                    'accounting_mode' => 'dimension',
                    'is_active' => true,
                ],
            );

            $this->openFiscalYear((string) $data['fiscal_start'], (string) $data['timezone']);

            $user = User::query()->create([
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => $data['admin_password'], // hashed by the model cast
                'locale' => $data['locale'],
                'is_active' => true,
                'primary_branch_id' => $branch->id,
            ]);
            $user->assignRole('admin');
            $user->branches()->syncWithoutDetaching([$branch->id]);

            $this->settings->set('general', 'installed_at', now()->toIso8601String());

            $this->audit->log('installation.completed', $user, null, [
                'company_name_en' => $data['company_name_en'],
                'base_currency' => $base,
                'fiscal_year_start' => $data['fiscal_start'],
                'tax_enabled' => $data['tax_enabled'],
                'tax_rate' => $data['tax_rate'],
                'tax_inclusive' => $data['tax_inclusive'],
                'admin_email' => $data['admin_email'],
            ], $user->id);

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $user;
        });
    }

    /** @param array<string, mixed> $data */
    private function writeSettings(array $data): void
    {
        $this->settings->set('general', 'company_name_en', (string) $data['company_name_en']);
        $this->settings->set('general', 'company_name_ar', (string) $data['company_name_ar']);
        $this->settings->set('general', 'base_currency', (string) $data['base_currency']);
        $this->settings->set('general', 'locale', (string) $data['locale']);
        $this->settings->set('general', 'timezone', (string) $data['timezone']);
        $this->settings->set('general', 'company_logo_path', (string) $data['logo_path']);
        $this->settings->set('accounting', 'fiscal_year_start', (string) $data['fiscal_start']);
        $this->settings->set('crm', 'business_timezone', (string) $data['timezone']);
        $this->settings->set('tax', 'enabled', $data['tax_enabled'] ? 'true' : 'false');
        $this->settings->set('tax', 'default_rate', $data['tax_rate']);
        $this->settings->set('tax', 'inclusive', $data['tax_inclusive'] ? 'true' : 'false');
        // Accounting policy stays unapproved: auto-posting remains disabled until an accountant signs off.
        $this->settings->set('accounting', 'accounting_policy_approved', 'false');
    }

    /** Opens the fiscal year that contains today, with one open period per month of that year. */
    private function openFiscalYear(string $monthDay, string $timezone): FiscalYear
    {
        $today = Carbon::now($timezone)->startOfDay();
        $start = Carbon::parse($today->year.'-'.$monthDay, $timezone)->startOfDay();
        if ($start->greaterThan($today)) {
            $start = $start->subYear();
        }

        $startsOn = $start->toDateString();
        $endsOn = $this->fiscal->endFor($startsOn);

        $year = FiscalYear::query()->firstOrCreate(
            ['starts_on' => $startsOn],
            ['name' => $this->fiscal->nameFor($startsOn, $endsOn), 'ends_on' => $endsOn],
        );

        for ($i = 0; $i < 12; $i++) {
            $periodStart = $start->copy()->addMonthsNoOverflow($i);
            $periodEnd = $i === 11 ? Carbon::parse($endsOn) : $start->copy()->addMonthsNoOverflow($i + 1)->subDay();

            FiscalPeriod::query()->firstOrCreate(
                ['fiscal_year_id' => $year->id, 'starts_on' => $periodStart->toDateString()],
                ['name' => $periodStart->format('Y-m'), 'ends_on' => $periodEnd->toDateString(), 'status' => 'open'],
            );
        }

        return $year;
    }

    /**
     * Fails when demo or shared-password accounts could be created or used.
     * Used by the install command; the message names the supported path.
     */
    public function refusalReason(): ?string
    {
        if (config('demo.enabled') || config('app.env') === 'demo' || UserSeeder::isIsolatedDemo()) {
            return 'erp:install cannot be used in demo mode.';
        }
        if (! $this->schemaReady()) {
            return 'The database schema is missing. Run: php artisan migrate --force';
        }
        if ($this->isInstalled()) {
            return 'This installation already has users or an install marker; erp:install runs once. Use user management in the application.';
        }

        return null;
    }
}
