<?php

namespace Tests\Feature;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Parties\Services\CreditLimitService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class CreditLimitTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->setPolicy('block');
    }

    private function setPolicy(string $policy): void
    {
        app(SettingService::class)->set('accounting', 'credit_limit_policy', $policy);
    }

    private function limit(int $minor): void
    {
        $customer = $this->customer();
        $customer->update(['credit_limit_minor' => $minor]);
    }

    /** Booking whose total is 200000 minor units in the given currency. */
    private function booking(string $currency = 'EGP', string $fx = '1'): Booking
    {
        return app(BookingService::class)->create([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $this->customer()->public_id,
            'start_date' => Carbon::now()->addDays(5)->toDateString(),
            'currency' => $currency,
            'fx_rate' => $fx,
            'items' => [[
                'service_type' => 'hotel',
                'description' => 'Hotel nights',
                'quantity' => 2,
                'unit_cost_minor' => 50000,
                'unit_sell_minor' => 100000,
            ]],
        ], $this->user->id);
    }

    private function confirm(Booking $booking, ?string $override = null, bool $can = false): Booking
    {
        return app(BookingService::class)->transition($booking, 'confirmed', null, $this->user->id, $override, $can);
    }

    public function test_policy_off_never_checks_the_limit(): void
    {
        $this->setPolicy('off');
        $this->limit(1000);

        $confirmed = $this->confirm($this->booking());

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame([], $confirmed->warnings());
        $this->assertSame(0, AuditEvent::query()->where('action', 'customer.credit_limit_exceeded')->count());
    }

    public function test_limit_zero_means_unlimited_even_when_blocking(): void
    {
        $this->limit(0);

        $this->assertSame('confirmed', $this->confirm($this->booking())->status);
    }

    public function test_within_limit_is_silent(): void
    {
        $this->limit(200000);

        $confirmed = $this->confirm($this->booking());

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame([], $confirmed->warnings());
        $this->assertArrayNotHasKey('warnings', $confirmed->toApiArray());
    }

    public function test_warn_policy_allows_audits_and_returns_warnings(): void
    {
        $this->setPolicy('warn');
        $this->limit(150000);

        $confirmed = $this->confirm($this->booking());

        $this->assertSame('confirmed', $confirmed->status);
        $warnings = $confirmed->toApiArray()['warnings'];
        $this->assertCount(1, $warnings);
        $this->assertSame('credit_limit_exceeded', $warnings[0]['code']);
        $this->assertSame(150000, $warnings[0]['credit_limit_minor']);
        $this->assertSame(0, $warnings[0]['current_exposure_minor']);
        $this->assertSame(200000, $warnings[0]['projected_exposure_minor']);
        $this->assertSame(1, AuditEvent::query()->where('action', 'customer.credit_limit_exceeded')->count());
    }

    public function test_block_policy_refuses_with_numbers_and_leaves_booking_unchanged(): void
    {
        $this->limit(150000);
        $booking = $this->booking();

        try {
            $this->confirm($booking);
            $this->fail('Expected the credit limit to block confirmation.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('limit 150000', $e->getMessage());
            $this->assertStringContainsString('current exposure 0', $e->getMessage());
            $this->assertStringContainsString('adds 200000', $e->getMessage());
        }

        $this->assertSame('draft', $booking->fresh()->status);
    }

    public function test_override_requires_permission_and_reason_and_is_audited(): void
    {
        $this->limit(150000);

        // Reason without permission, and permission without reason, both stay blocked.
        foreach ([['Needs it', false], [null, true], ['  ', true]] as [$reason, $can]) {
            $booking = $this->booking();
            try {
                $this->confirm($booking, $reason, $can);
                $this->fail('Expected block.');
            } catch (DomainException) {
                $this->assertSame('draft', $booking->fresh()->status);
            }
        }

        $confirmed = $this->confirm($this->booking(), 'VIP, cheque in transit', true);

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertTrue($confirmed->toApiArray()['warnings'][0]['overridden']);
        $event = AuditEvent::query()->where('action', 'customer.credit_limit_overridden')->firstOrFail();
        $this->assertSame('VIP, cheque in transit', $event->after['override_reason']);
    }

    public function test_foreign_currency_booking_is_converted_to_base(): void
    {
        // 200000 USD-minor at 50 = 10,000,000 EGP-minor.
        $this->limit(5_000_000);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('adds 10000000');

        $this->confirm($this->booking('USD', '50'));
    }

    public function test_foreign_currency_booking_within_limit_is_allowed(): void
    {
        $this->limit(15_000_000);

        $this->assertSame('confirmed', $this->confirm($this->booking('USD', '50'))->status);
    }

    public function test_exposure_includes_unpaid_issued_invoices(): void
    {
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->create([
            'type' => 'customer_invoice',
            'party_type' => 'customer',
            'party_id' => $this->customer()->public_id,
            'issue_date' => now()->toDateString(),
            'currency' => 'EGP',
            'lines' => [['account_id' => $this->account('4100')->public_id, 'description' => 'Sale', 'credit_minor' => 100000]],
        ], $this->branch, $this->user->id);
        $invoices->submit($invoice, $this->user->id);
        $invoices->approve($invoice->refresh(), $this->user->id);
        $invoices->issue($invoice->refresh(), $this->user->id);

        $service = app(CreditLimitService::class);
        $this->assertSame(100000, $service->exposure($this->customer()));

        $this->limit(250000);
        try {
            $this->confirm($this->booking());
            $this->fail('Unpaid invoice should count towards exposure.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('current exposure 100000', $e->getMessage());
        }
    }

    public function test_invoice_issue_is_checked_and_can_be_overridden(): void
    {
        $this->limit(50000);
        $invoices = app(InvoiceService::class);
        $make = function () use ($invoices) {
            $invoice = $invoices->create([
                'type' => 'customer_invoice',
                'party_type' => 'customer',
                'party_id' => $this->customer()->public_id,
                'issue_date' => now()->toDateString(),
                'currency' => 'EGP',
                'lines' => [['account_id' => $this->account('4100')->public_id, 'description' => 'Sale', 'credit_minor' => 100000]],
            ], $this->branch, $this->user->id);
            $invoices->submit($invoice, $this->user->id);

            return $invoices->approve($invoice->refresh(), $this->user->id);
        };

        $blocked = $make();
        try {
            $invoices->issue($blocked, $this->user->id);
            $this->fail('Expected the credit limit to block issuing.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Credit limit exceeded', $e->getMessage());
        }
        $this->assertSame('approved', $blocked->fresh()->status);

        $issued = $invoices->issue($blocked->fresh(), $this->user->id, 'Approved by CFO', true);
        $this->assertSame('issued', $issued->status);
        $this->assertTrue($issued->toApiArray()['warnings'][0]['overridden']);
    }

    public function test_cancelled_bookings_are_excluded_and_unposted_confirmed_bookings_are_included(): void
    {
        $this->limit(250000);
        $first = $this->confirm($this->booking());

        // 200000 already committed: a second 200000 booking would exceed 250000.
        $second = $this->booking();
        try {
            $this->confirm($second);
            $this->fail('First confirmed booking should count.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('current exposure 200000', $e->getMessage());
        }

        app(BookingService::class)->cancel($first->refresh(), 'Customer changed plans', 0, $this->user->id);
        $this->assertSame(0, app(CreditLimitService::class)->exposure($this->customer()));
        $this->assertSame('confirmed', $this->confirm($second)->status);
    }

    public function test_confirmed_bookings_count_even_when_auto_posting_is_off(): void
    {
        app(SettingService::class)->set('accounting', 'auto_post_bookings', 'false');
        $this->limit(250000);

        $this->confirm($this->booking());

        $this->assertSame(0, app(CreditLimitService::class)->ledgerBalance($this->customer()));
        $this->assertSame(200000, app(CreditLimitService::class)->exposure($this->customer()));
        $this->expectException(DomainException::class);
        $this->confirm($this->booking());
    }

    public function test_transition_api_returns_warnings_and_honours_override_permission(): void
    {
        foreach (['bookings.transition', 'bookings.override_credit_limit'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo('bookings.transition');
        Sanctum::actingAs($this->user);
        $headers = ['X-Branch-Id' => $this->branch->public_id];
        $this->limit(150000);

        $this->setPolicy('warn');
        $warned = $this->booking();
        $this->withHeaders($headers)->postJson("/api/v1/bookings/{$warned->public_id}/transition", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.warnings.0.code', 'credit_limit_exceeded');

        $this->setPolicy('block');
        $blocked = $this->booking();
        $this->withHeaders($headers)->postJson("/api/v1/bookings/{$blocked->public_id}/transition", ['status' => 'confirmed', 'override_reason' => 'Please'])
            ->assertStatus(422);

        $this->user->givePermissionTo('bookings.override_credit_limit');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withHeaders($headers)->postJson("/api/v1/bookings/{$blocked->public_id}/transition", ['status' => 'confirmed', 'override_reason' => 'Please'])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.warnings.0.overridden', true);
    }

    public function test_customer_profile_exposes_limit_and_exposure(): void
    {
        Permission::query()->firstOrCreate(['name' => 'customers.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo('customers.view');
        Sanctum::actingAs($this->user);

        $this->limit(300000);
        $this->confirm($this->booking());

        $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])
            ->getJson('/api/v1/customers/'.$this->customer()->public_id)
            ->assertOk()
            ->assertJsonPath('data.credit.credit_limit_minor', 300000)
            ->assertJsonPath('data.credit.exposure_minor', 200000)
            ->assertJsonPath('data.credit.policy', 'block');
    }
}
