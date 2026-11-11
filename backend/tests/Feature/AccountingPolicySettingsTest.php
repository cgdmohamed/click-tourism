<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Core\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class AccountingPolicySettingsTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        foreach (['settings.view', 'settings.update', 'periods.view', 'periods.manage'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(['settings.view', 'settings.update', 'periods.view', 'periods.manage']);
        Sanctum::actingAs($this->user);
    }

    private function h(): array
    {
        return ['X-Branch-Id' => $this->branch->public_id];
    }

    private function putSetting(string $key, string $value, string $group = 'accounting')
    {
        return $this->withHeaders($this->h())->putJson('/api/v1/settings', [
            'settings' => [['group' => $group, 'key' => $key, 'value' => $value]],
        ]);
    }

    private function postOne(): JournalEntry
    {
        return app(PostingService::class)->post(PostingRequest::make(now(), $this->branch, 'EGP', [
            PostingLine::debit($this->account('1110'), 1000),
            PostingLine::credit($this->account('4100'), 1000),
        ], 'lock', actorId: $this->user->id));
    }

    private function lockedFlag(): string
    {
        $rows = $this->withHeaders($this->h())->getJson('/api/v1/settings')->assertOk()->json('data');

        return collect($rows)->firstWhere('key', 'base_currency_locked')['value'];
    }

    public function test_base_currency_is_unlocked_without_posted_entries(): void
    {
        $this->assertSame('false', $this->lockedFlag());
        $this->putSetting('base_currency', 'SAR', 'general')->assertOk();
        $this->assertSame('SAR', app(SettingService::class)->baseCurrency());
    }

    public function test_base_currency_is_locked_after_the_first_posted_entry(): void
    {
        $this->postOne();

        $this->assertSame('true', $this->lockedFlag());
        $this->putSetting('base_currency', 'USD', 'general')
            ->assertStatus(422)
            ->assertJsonPath('code', 'domain_error');
        $this->assertSame('EGP', app(SettingService::class)->baseCurrency());

        // Re-sending the same value is allowed.
        $this->putSetting('base_currency', 'EGP', 'general')->assertOk();
    }

    public function test_a_failed_base_currency_change_rolls_back_the_whole_batch(): void
    {
        $this->postOne();

        $this->withHeaders($this->h())->putJson('/api/v1/settings', ['settings' => [
            ['group' => 'general', 'key' => 'company_name_en', 'value' => 'Changed'],
            ['group' => 'general', 'key' => 'base_currency', 'value' => 'USD'],
        ]])->assertStatus(422);

        $this->assertNotSame('Changed', app(SettingService::class)->get('general', 'company_name_en'));
    }

    public function test_draft_entries_do_not_lock_the_base_currency(): void
    {
        app(PostingService::class)->draft(PostingRequest::make(now(), $this->branch, 'EGP', [
            PostingLine::debit($this->account('1110'), 1000),
            PostingLine::credit($this->account('4100'), 1000),
        ], 'draft', actorId: $this->user->id));

        $this->assertSame('false', $this->lockedFlag());
        $this->putSetting('base_currency', 'USD', 'general')->assertOk();
    }

    public function test_the_computed_lock_flag_cannot_be_written(): void
    {
        $this->putSetting('base_currency_locked', 'false', 'general')->assertStatus(422);
    }

    public function test_rounding_policy_and_fiscal_year_start_are_validated(): void
    {
        $this->putSetting('rounding_policy', 'floor')->assertStatus(422);
        $this->putSetting('rounding_policy', 'bankers')->assertOk();

        $this->putSetting('fiscal_year_start', '02-29')->assertStatus(422);
        $this->putSetting('fiscal_year_start', '13-01')->assertStatus(422);
        $this->putSetting('fiscal_year_start', '7-1')->assertStatus(422);
        $this->putSetting('fiscal_year_start', '07-01')->assertOk();
    }

    public function test_a_posting_rounds_per_policy_and_still_balances(): void
    {
        $post = fn () => app(PostingService::class)->post(PostingRequest::make(now(), $this->branch, 'EGP', [
            // 5 USD cents at 0.5 = 2.5 EGP minor units: half-up 3, bankers 2.
            PostingLine::debit($this->account('1110'), 5)->in('USD', '0.5'),
            PostingLine::credit($this->account('4100'), 3)->in('EGP', '1'),
        ], 'round', actorId: $this->user->id));

        $halfUp = $post();
        $this->assertSame(3, (int) $halfUp->lines->firstWhere('account.code', '1110')->base_debit_minor);
        $this->assertSame((int) $halfUp->lines->sum('base_debit_minor'), (int) $halfUp->lines->sum('base_credit_minor'));
        $this->assertNull($halfUp->lines->firstWhere('account.code', '6999'));

        app(SettingService::class)->set('accounting', 'rounding_policy', 'bankers');
        $bankers = $post();
        $this->assertSame(2, (int) $bankers->lines->firstWhere('account.code', '1110')->base_debit_minor);
        $this->assertSame(1, (int) $bankers->lines->firstWhere('account.code', '6999')->base_debit_minor);
        $this->assertSame((int) $bankers->lines->sum('base_debit_minor'), (int) $bankers->lines->sum('base_credit_minor'));
    }

    public function test_suggestion_without_years_uses_the_current_year_and_configured_start(): void
    {
        FiscalPeriod::query()->delete();
        FiscalYear::query()->delete();
        $year = now()->year;
        $this->withHeaders($this->h())->getJson('/api/v1/fiscal-years/suggested')
            ->assertOk()
            ->assertJsonPath('data.starts_on', "{$year}-01-01")
            ->assertJsonPath('data.ends_on', "{$year}-12-31")
            ->assertJsonPath('data.name', (string) $year);

        app(SettingService::class)->set('accounting', 'fiscal_year_start', '07-01');
        $next = $year + 1;
        $this->withHeaders($this->h())->getJson('/api/v1/fiscal-years/suggested')
            ->assertJsonPath('data.starts_on', "{$year}-07-01")
            ->assertJsonPath('data.ends_on', "{$next}-06-30")
            ->assertJsonPath('data.name', "{$year}/{$next}");
    }

    public function test_suggestion_follows_the_latest_existing_year(): void
    {
        FiscalYear::query()->create(['name' => '2030', 'starts_on' => '2030-01-01', 'ends_on' => '2030-12-31']);

        $this->withHeaders($this->h())->getJson('/api/v1/fiscal-years/suggested')
            ->assertJsonPath('data.starts_on', '2031-01-01')
            ->assertJsonPath('data.ends_on', '2031-12-31');

        // A mid-year start continues right after the previous year, with no gap.
        app(SettingService::class)->set('accounting', 'fiscal_year_start', '07-01');
        FiscalYear::query()->create(['name' => '2031/2032', 'starts_on' => '2031-01-01', 'ends_on' => '2032-06-30']);
        $this->withHeaders($this->h())->getJson('/api/v1/fiscal-years/suggested')
            ->assertJsonPath('data.starts_on', '2032-07-01')
            ->assertJsonPath('data.ends_on', '2033-06-30')
            ->assertJsonPath('data.name', '2032/2033');
    }

    public function test_store_defaults_missing_fields_from_the_suggestion(): void
    {
        FiscalPeriod::query()->delete();
        FiscalYear::query()->delete();

        $this->withHeaders($this->h())->postJson('/api/v1/fiscal-periods', [])
            ->assertCreated()
            ->assertJsonPath('data.name', (string) now()->year)
            ->assertJsonCount(12, 'data.periods');
    }

    public function test_store_rejects_overlaps_and_unconfirmed_custom_starts(): void
    {
        $this->withHeaders($this->h())->postJson('/api/v1/fiscal-periods', [
            'name' => '2040', 'starts_on' => '2040-01-01', 'ends_on' => '2040-12-31',
        ])->assertCreated();

        $this->withHeaders($this->h())->postJson('/api/v1/fiscal-periods', [
            'name' => 'overlap', 'starts_on' => '2040-06-01', 'ends_on' => '2041-05-31', 'confirm_custom_start' => true,
        ])->assertStatus(422);

        $this->withHeaders($this->h())->postJson('/api/v1/fiscal-periods', [
            'name' => 'custom', 'starts_on' => '2041-04-01', 'ends_on' => '2042-03-31',
        ])->assertStatus(422);

        $this->withHeaders($this->h())->postJson('/api/v1/fiscal-periods', [
            'name' => 'custom', 'starts_on' => '2041-04-01', 'ends_on' => '2042-03-31', 'confirm_custom_start' => true,
        ])->assertCreated();
    }
}
