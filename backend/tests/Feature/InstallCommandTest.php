<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\Tax\Models\TaxProfile;
use App\Modules\Treasury\Models\CashAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Vq7#rT-2mXz!n9Lc';

    protected function tearDown(): void
    {
        putenv('ERP_ADMIN_PASSWORD');
        putenv('ERP_ADMIN_PASSWORD_FILE');
        putenv('ERP_COMPANY_NAME');
        putenv('ERP_BASE_CURRENCY');
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function installOptions(array $override = []): array
    {
        return $override + [
            '--company-name' => 'Nile Horizons Travel',
            '--base-currency' => 'USD',
            '--fiscal-start' => '07-01',
            '--timezone' => 'Asia/Dubai',
            '--locale' => 'en',
            '--admin-email' => 'Owner@Nile-Horizons.test',
            '--admin-name' => 'Owner',
            '--logo-path' => 'branding/logo.png',
        ];
    }

    private function install(array $override = [], string $password = self::PASSWORD)
    {
        putenv('ERP_ADMIN_PASSWORD='.$password);

        return $this->artisan('erp:install', $this->installOptions($override));
    }

    public function test_installs_company_profile_chart_roles_and_admin_without_demo_data(): void
    {
        $this->install(['--tax-enabled' => true])->expectsOutputToContain('Installation complete')->assertSuccessful();

        $settings = app(SettingService::class);
        $this->assertSame('Nile Horizons Travel', $settings->get('general', 'company_name_en'));
        $this->assertSame('Nile Horizons Travel', $settings->get('general', 'company_name_ar'));
        $this->assertSame('USD', $settings->baseCurrency());
        $this->assertSame('branding/logo.png', $settings->get('general', 'company_logo_path'));
        $this->assertSame('07-01', $settings->get('accounting', 'fiscal_year_start'));
        $this->assertTrue($settings->bool('tax', 'enabled'));
        $this->assertFalse($settings->bool('accounting', 'accounting_policy_approved'));
        $this->assertNotNull($settings->get('general', 'installed_at'));

        $admin = User::query()->sole();
        $this->assertSame('owner@nile-horizons.test', $admin->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->is_active);
        $this->assertSame($admin->primary_branch_id, Branch::query()->sole()->id);
        $this->assertSame('HQ', Branch::query()->sole()->code);

        $this->assertGreaterThan(20, Account::query()->count());
        $this->assertTrue(CashAccount::query()->where('currency_code', '!=', 'USD')->doesntExist());
        $this->assertSame(['No tax'], TaxProfile::query()->pluck('name')->all());
        $this->assertSame(1, AuditEvent::query()->where('action', 'installation.completed')->count());
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(AuditEvent::query()->get()->toArray()));
    }

    public function test_fiscal_calendar_follows_the_configured_start(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10', 'Asia/Dubai'));
        $this->install()->assertSuccessful();

        $year = FiscalYear::query()->sole();
        $this->assertSame('2025-07-01', $year->starts_on->toDateString());
        $this->assertSame('2026-06-30', $year->ends_on->toDateString());
        $this->assertSame('2025/2026', $year->name);
        $periods = FiscalPeriod::query()->orderBy('starts_on')->get();
        $this->assertCount(12, $periods);
        $this->assertSame('2026-06-30', $periods->last()->ends_on->toDateString());
        $this->assertSame('open', $periods->first()->status);
    }

    public function test_it_fails_closed_without_an_admin_password(): void
    {
        putenv('ERP_ADMIN_PASSWORD');

        $this->artisan('erp:install', $this->installOptions() + ['--no-interaction' => true])
            ->expectsOutputToContain('There is no default password')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Account::query()->count());
    }

    public function test_it_reads_the_password_from_a_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'erp');
        file_put_contents($file, self::PASSWORD."\n");
        putenv('ERP_ADMIN_PASSWORD_FILE='.$file);

        $this->artisan('erp:install', $this->installOptions())->assertSuccessful();
        unlink($file);

        $this->assertTrue(Hash::check(self::PASSWORD, User::query()->sole()->password));
    }

    public function test_it_prompts_for_the_password_interactively(): void
    {
        putenv('ERP_ADMIN_PASSWORD');

        $this->artisan('erp:install', $this->installOptions())
            ->expectsQuestion('Initial administrator password (minimum 12 characters)', self::PASSWORD)
            ->expectsQuestion('Repeat the password', self::PASSWORD)
            ->assertSuccessful();

        $this->assertSame(1, User::query()->count());
    }

    #[DataProvider('badPasswords')]
    public function test_it_rejects_weak_passwords_and_writes_nothing(string $password, string $message): void
    {
        $this->install(password: $password)->expectsOutputToContain($message)->assertFailed();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Account::query()->count());
    }

    /** @return array<string, array{string, string}> */
    public static function badPasswords(): array
    {
        return [
            'too short' => ['Short1!', 'at least 12'],
            'well known' => ['administrator', 'well-known default'],
            'repetitive' => ['aaaaaaaaaaaaaaaa', 'repetitive'],
            'contains email name' => ['xx-owner-nile-1234', 'e-mail'],
        ];
    }

    #[DataProvider('badInputs')]
    public function test_it_validates_company_input(array $override, string $message): void
    {
        $this->install($override)->expectsOutputToContain($message)->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function badInputs(): array
    {
        return [
            'unknown currency' => [['--base-currency' => 'XXX'], 'base currency'],
            'leap day fiscal start' => [['--fiscal-start' => '02-29'], 'valid MM-DD'],
            'malformed fiscal start' => [['--fiscal-start' => '7-1'], 'fiscal start'],
            'url logo' => [['--logo-path' => 'https://evil.test/logo.png'], 'logo path'],
            'traversal logo' => [['--logo-path' => '../../etc/passwd.png'], 'logo path'],
            'bad timezone' => [['--timezone' => 'Mars/Base'], 'timezone'],
            'bad email' => [['--admin-email' => 'not-an-email'], 'email'],
            'missing company' => [['--company-name' => ''], 'company name'],
        ];
    }

    public function test_it_runs_only_once(): void
    {
        $this->install()->assertSuccessful();

        $this->install(['--admin-email' => 'second@nile-horizons.test'])
            ->expectsOutputToContain('already has users')->assertFailed();

        $this->assertSame(1, User::query()->count());
    }

    public function test_it_requires_a_migrated_schema(): void
    {
        Schema::rename('settings', 'settings_renamed');

        $this->install()->expectsOutputToContain('migrate --force')->assertFailed();
    }

    public function test_it_is_refused_in_demo_mode(): void
    {
        config(['demo.enabled' => true]);

        $this->install()->expectsOutputToContain('demo mode')->assertFailed();
        $this->assertSame(0, User::query()->count());
    }

    public function test_dry_run_validates_without_writing(): void
    {
        $this->install(['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Account::query()->count());
    }

    public function test_setup_admin_alias_delegates_and_never_uses_a_default_password(): void
    {
        putenv('ERP_ADMIN_PASSWORD');
        putenv('ERP_COMPANY_NAME=Alias Co');
        putenv('ERP_BASE_CURRENCY=EGP');

        $this->artisan('setup:admin', ['email' => 'a@alias.test', '--no-interaction' => true])->assertFailed();
        $this->assertSame(0, User::query()->count());

        putenv('ERP_ADMIN_PASSWORD='.self::PASSWORD);
        $this->artisan('setup:admin', ['email' => 'a@alias.test'])->assertSuccessful();
        $this->assertSame('a@alias.test', User::query()->sole()->email);
    }
}
