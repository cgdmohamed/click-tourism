<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Commissions\Models\Commission;
use App\Modules\Commissions\Models\CommissionPayment;
use App\Modules\Commissions\Models\CommissionRule;
use App\Modules\Commissions\Services\CommissionCalculator;
use App\Modules\Commissions\Services\CommissionRuleService;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Treasury\Models\CashAccount;
use App\Support\Money;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TreasurySeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class CommissionTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private User $maker;

    private User $checker;

    private Employee $seller;

    private CommissionRuleService $rules;

    private CommissionService $commissions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->seed(TreasurySeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->settings()->set('commissions', 'segregation', 'false');

        $this->maker = $this->user;
        $this->maker->assignRole('accountant');

        $this->checker = User::factory()->create();
        $this->checker->branches()->attach($this->branch->id);
        $this->checker->assignRole('finance_manager');

        // The sales employee owns the bookings, so owner-based rules pay him.
        $salesUser = User::factory()->create();
        $salesUser->branches()->attach($this->branch->id);
        $this->seller = $this->employee('S1', $this->branch, $salesUser);

        $this->rules = app(CommissionRuleService::class);
        $this->commissions = app(CommissionService::class);
    }

    private function settings(): SettingService
    {
        return app(SettingService::class);
    }

    private function employee(string $code, Branch $branch, ?User $user = null): Employee
    {
        return Employee::query()->create([
            'internal_code' => $code,
            'name_ar' => 'موظف '.$code,
            'name_en' => 'Employee '.$code,
            'category' => 'staff',
            'branch_id' => $branch->id,
            'user_id' => $user?->id,
            'is_active' => true,
        ]);
    }

    private function otherBranch(): Branch
    {
        $branch = Branch::query()->create([
            'code' => 'AL2', 'name_ar' => 'فرع الإسكندرية', 'name_en' => 'Alexandria',
            'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $this->user->branches()->attach($branch->id);

        return $branch;
    }

    /** @param array<string, mixed> $overrides */
    private function rule(array $overrides = [], bool $approve = true): CommissionRule
    {
        $rule = $this->rules->create($overrides + [
            'code' => 'R'.(CommissionRule::query()->count() + 1),
            'name_ar' => 'عمولة', 'name_en' => 'Commission',
            'beneficiary_type' => 'employee',
            'basis' => 'percent_revenue',
            'rate_bp' => 1000,
            'effective_from' => Carbon::now()->subMonth()->toDateString(),
        ], $this->maker->id);

        if ($approve) {
            $this->rules->submit($rule, $this->maker->id);
            $rule = $this->rules->approve($rule, $this->checker->id);
        }

        return $rule;
    }

    private function booking(int $sell = 100000, int $cost = 40000, string $currency = 'EGP', string $rate = '1', ?Branch $branch = null, ?string $service = 'hotel'): Booking
    {
        $branch ??= $this->branch;
        $booking = app(BookingService::class)->create([
            'branch_id' => $branch->public_id,
            'customer_id' => $this->customer($branch)->public_id,
            'owner_user_id' => $this->seller->user->public_id,
            'start_date' => Carbon::now()->addDays(5)->toDateString(),
            'currency' => $currency,
            'fx_rate' => $rate,
            'items' => [[
                'service_type' => $service,
                'description' => 'Service',
                'quantity' => 1,
                'unit_cost_minor' => $cost,
                'unit_sell_minor' => $sell,
            ]],
        ], $this->maker->id);

        return app(BookingService::class)->transition($booking, 'confirmed', null, $this->maker->id);
    }

    private function pay(Booking $booking, int $amount, ?string $currency = null): Payment
    {
        return app(PaymentService::class)->record([
            'branch_id' => $booking->branch->public_id,
            'customer_id' => $booking->customer->public_id,
            'booking_id' => $booking->public_id,
            'cash_account_id' => $this->account('1110')->public_id,
            'method' => 'cash',
            'amount_minor' => $amount,
            'currency' => $currency ?? $booking->currency_code,
            'fx_rate' => (string) $booking->fx_rate,
            'paid_at' => Carbon::now()->toIso8601String(),
        ], $this->maker->id);
    }

    private function onlyCommission(): Commission
    {
        $this->assertSame(1, Commission::query()->count());

        return Commission::query()->firstOrFail();
    }

    /** @return array{0: int, 1: int} debit and credit totals of an entry in base minor units */
    private function totals(JournalEntry $entry): array
    {
        return [(int) $entry->lines()->sum('base_debit_minor'), (int) $entry->lines()->sum('base_credit_minor')];
    }

    // ---- exact maths --------------------------------------------------------

    public function test_percent_rounding_is_exact_and_follows_the_rounding_policy(): void
    {
        $calc = app(CommissionCalculator::class);

        // 12.5% of 100.05 = 12.50625 -> 12.51
        $this->assertSame(1251, $calc->calculate('percent_revenue', 1250, null, 10005, 0)['amount_minor']);
        // exact halves: 0.5 / 1.5 / 2.5 minor units
        $this->assertSame(1, $calc->calculate('percent_revenue', 5000, null, 1, 0, Money::ROUND_HALF_UP)['amount_minor']);
        $this->assertSame(0, $calc->calculate('percent_revenue', 5000, null, 1, 0, Money::ROUND_BANKERS)['amount_minor']);
        $this->assertSame(3, $calc->calculate('percent_revenue', 5000, null, 5, 0, Money::ROUND_HALF_UP)['amount_minor']);
        $this->assertSame(2, $calc->calculate('percent_revenue', 5000, null, 5, 0, Money::ROUND_BANKERS)['amount_minor']);
        // a large amount stays exact in integers
        $this->assertSame(123456789012, $calc->calculate('percent_revenue', 10000, null, 123456789012, 0)['amount_minor']);
    }

    public function test_margin_basis_never_goes_negative_and_fixed_ignores_the_basis(): void
    {
        $calc = app(CommissionCalculator::class);

        $this->assertSame(['basis_minor' => 60000, 'amount_minor' => 6000], $calc->calculate('percent_margin', 1000, null, 100000, 40000));
        $this->assertSame(['basis_minor' => 0, 'amount_minor' => 0], $calc->calculate('percent_margin', 1000, null, 30000, 40000));
        $this->assertSame(['basis_minor' => 0, 'amount_minor' => 2500], $calc->calculate('fixed', null, 2500, 99999, 1));
    }

    public function test_foreign_currency_booking_is_converted_to_base_before_the_rate_applies(): void
    {
        $this->rule(['rate_bp' => 500]);
        $booking = $this->booking(sell: 100000, cost: 0, currency: 'USD', rate: '50.5');
        $this->pay($booking, 100000);

        $commission = $this->onlyCommission();
        // 1,000.00 USD * 50.5 = 50,500.00 EGP; 5% = 2,525.00 EGP
        $this->assertSame(5050000, $commission->basis_amount_minor);
        $this->assertSame(252500, $commission->amount_minor);
        $this->assertSame('EGP', $commission->currency_code);
    }

    // ---- trigger ------------------------------------------------------------

    public function test_commission_accrues_only_when_the_booking_is_fully_collected_and_never_twice(): void
    {
        $this->rule(['rate_bp' => 1000]);
        $booking = $this->booking();

        $this->pay($booking, 60000);
        $this->assertSame(0, Commission::query()->count());

        $this->pay($booking, 40000);
        $commission = $this->onlyCommission();
        $this->assertSame('accrued', $commission->status);
        $this->assertSame(10000, $commission->amount_minor);
        $this->assertSame('employee', $commission->beneficiary_type);
        $this->assertSame($this->seller->id, $commission->beneficiary_id);
        $this->assertNull($commission->journal_entry_id, 'Accrual alone must not touch the ledger.');

        $this->commissions->syncBooking($booking, $this->maker->id);
        $this->commissions->syncBooking($booking, $this->maker->id);
        $this->assertSame(1, Commission::query()->count());
    }

    public function test_no_rules_means_no_commission(): void
    {
        $booking = $this->booking();
        $this->pay($booking, 100000);

        $this->assertSame(0, Commission::query()->count());
    }

    // ---- precedence, effective dates, versions --------------------------------

    public function test_most_specific_rule_wins_and_rules_do_not_stack(): void
    {
        $this->rule(['code' => 'GEN', 'rate_bp' => 500]);
        $this->rule(['code' => 'HOTEL', 'rate_bp' => 800, 'service_type' => 'hotel']);
        $this->rule(['code' => 'BRANCH', 'rate_bp' => 700, 'branch_id' => $this->branch->public_id]);

        $booking = $this->booking(service: 'hotel');
        $this->pay($booking, 100000);

        $commission = $this->onlyCommission();
        $this->assertSame('HOTEL', $commission->rule_code);
        $this->assertSame(8000, $commission->amount_minor);
    }

    public function test_latest_effective_date_breaks_a_tie(): void
    {
        $this->rule(['code' => 'OLD', 'rate_bp' => 500, 'effective_from' => Carbon::now()->subMonths(3)->toDateString()]);
        $this->rule(['code' => 'NEW', 'rate_bp' => 600, 'effective_from' => Carbon::now()->subDays(2)->toDateString()]);

        $booking = $this->booking();
        $this->pay($booking, 100000);

        $this->assertSame('NEW', $this->onlyCommission()->rule_code);
    }

    public function test_expired_future_retired_and_unapproved_rules_do_not_apply(): void
    {
        $this->rule(['code' => 'EXP', 'effective_to' => Carbon::now()->subDays(5)->toDateString()]);
        $this->rule(['code' => 'FUT', 'effective_from' => Carbon::now()->addDays(5)->toDateString()]);
        $retired = $this->rule(['code' => 'RET']);
        $this->rules->retire($retired, $this->maker->id);
        $this->rule(['code' => 'DRAFT'], approve: false);

        $booking = $this->booking();
        $this->pay($booking, 100000);

        $this->assertSame(0, Commission::query()->count());
    }

    public function test_a_new_rule_version_supersedes_the_old_one_from_its_effective_date(): void
    {
        $v1 = $this->rule(['code' => 'SALES', 'rate_bp' => 500]);

        $v2 = $this->rules->newVersion($v1, $this->maker->id);
        $this->assertSame(2, $v2->version);
        $this->assertSame('draft', $v2->status);
        $v2 = $this->rules->update($v2, ['rate_bp' => 900, 'effective_from' => Carbon::now()->addDays(10)->toDateString()], $this->maker->id);
        $this->rules->submit($v2, $this->maker->id);
        $this->rules->approve($v2, $this->checker->id);

        $v1->refresh();
        $this->assertSame('approved', $v1->status);
        $this->assertSame(Carbon::now()->addDays(9)->toDateString(), $v1->effective_to->toDateString());

        // Today still uses version 1.
        $booking = $this->booking();
        $this->pay($booking, 100000);
        $commission = $this->onlyCommission();
        $this->assertSame(1, $commission->rule_version);
        $this->assertSame(5000, $commission->amount_minor);

        // From the new date the engine picks version 2.
        $applied = $this->rules->resolve($booking, Carbon::now()->addDays(10)->toDateString());
        $this->assertSame(2, $applied->first()['rule']->version);

        // The accrual keeps its snapshot even though the rule moved on.
        $this->assertSame(500, $commission->rate_bp);
    }

    public function test_approved_rule_versions_are_immutable_and_only_one_draft_version_exists(): void
    {
        $v1 = $this->rule(['code' => 'LOCK']);

        try {
            $this->rules->update($v1, ['rate_bp' => 1], $this->maker->id);
            $this->fail('An approved version must not be editable.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('new version', $e->getMessage());
        }

        $this->rules->newVersion($v1, $this->maker->id);
        $this->expectException(DomainException::class);
        $this->rules->newVersion($v1, $this->maker->id);
    }

    public function test_rule_approval_enforces_segregation_when_enabled(): void
    {
        $this->settings()->set('commissions', 'segregation', 'true');
        $rule = $this->rule(approve: false);
        $this->rules->submit($rule, $this->maker->id);

        try {
            $this->rules->approve($rule, $this->maker->id);
            $this->fail('The author must not approve their own rule.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('cannot approve', $e->getMessage());
        }

        $this->assertSame('approved', $this->rules->approve($rule, $this->checker->id)->status);
    }

    public function test_rule_validation(): void
    {
        foreach ([
            ['beneficiary_type' => 'agent'],
            ['rate_bp' => 0],
            ['rate_bp' => 10001],
            ['basis' => 'fixed', 'rate_bp' => null, 'fixed_minor' => 0],
            ['effective_to' => Carbon::now()->subYear()->toDateString()],
            ['service_type' => 'spaceship'],
            ['beneficiary_type' => 'employee', 'beneficiary_id' => 'nope'],
        ] as $index => $bad) {
            try {
                $this->rule($bad + ['code' => 'BAD'.$index], approve: false);
                $this->fail('Rule should be invalid: '.json_encode($bad));
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    // ---- approval, posting, payment -------------------------------------------

    private function accruedCommission(int $rateBp = 1000): Commission
    {
        $this->rule(['rate_bp' => $rateBp]);
        $booking = $this->booking();
        $this->pay($booking, 100000);

        return $this->onlyCommission();
    }

    public function test_approve_post_and_pay_follow_the_ledger_rules(): void
    {
        $commission = $this->accruedCommission();

        $commission = $this->commissions->approve($commission, $this->checker->id);
        $this->assertSame('approved', $commission->status);
        $this->assertNull($commission->journal_entry_id, 'commission_auto_post=false must not post on approval.');

        $commission = $this->commissions->post($commission, $this->checker->id);
        $this->assertSame('posted', $commission->status);

        $entry = $commission->journalEntry;
        $this->assertSame([10000, 10000], $this->totals($entry));
        $this->assertSame('commission', $entry->source_type);
        $this->assertSame($commission->id, $entry->source_id);
        $expense = $entry->lines->firstWhere(fn ($l) => $l->account->code === '6700');
        $this->assertSame(10000, (int) $expense->debit_minor);
        $payable = $entry->lines->firstWhere(fn ($l) => $l->credit_minor > 0);
        $this->assertSame('employee', $payable->party_type);
        $this->assertSame($this->seller->id, $payable->party_id);
        $this->assertSame('2100', $payable->account->parent->code, 'Payable is a party subaccount under the payables control account (ADR-0015).');

        // Partial payout, then the rest.
        $cash = CashAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1110'))->firstOrFail();
        $first = $this->commissions->pay($commission, ['cash_account_id' => $cash->public_id, 'amount_minor' => 4000], $this->checker->id);
        $this->assertSame([4000, 4000], $this->totals($first->journalEntry));
        $commission->refresh();
        $this->assertSame('posted', $commission->status);
        $this->assertSame(6000, $commission->outstandingMinor());

        $this->commissions->pay($commission, ['cash_account_id' => $cash->public_id], $this->checker->id);
        $commission->refresh();
        $this->assertSame('paid', $commission->status);
        $this->assertSame(10000, $commission->paid_minor);

        // Payable subaccount nets to zero after full settlement.
        $balance = (int) DB::table('journal_lines')->where('account_id', $payable->account_id)
            ->selectRaw('COALESCE(SUM(base_credit_minor - base_debit_minor), 0) as b')->value('b');
        $this->assertSame(0, $balance);

        $this->expectException(DomainException::class);
        $this->commissions->pay($commission, ['cash_account_id' => $cash->public_id, 'amount_minor' => 1], $this->checker->id);
    }

    public function test_payment_cannot_exceed_the_payable_and_needs_a_base_currency_account(): void
    {
        $commission = $this->accruedCommission();
        $commission = $this->commissions->post($this->commissions->approve($commission, $this->checker->id), $this->checker->id);
        $cash = CashAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1110'))->firstOrFail();

        try {
            $this->commissions->pay($commission, ['cash_account_id' => $cash->public_id, 'amount_minor' => 10001], $this->checker->id);
            $this->fail('Overpayment must be refused.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('exceeds', $e->getMessage());
        }

        $this->assertSame(0, CommissionPayment::query()->count());
    }

    public function test_agent_commission_posts_to_the_supplier_payable_subaccount(): void
    {
        $agent = Supplier::query()->create([
            'category' => 'agent', 'name_ar' => 'وكيل', 'name_en' => 'Agent One',
            'settlement_currency' => 'EGP', 'branch_id' => $this->branch->id, 'is_active' => true,
        ]);
        $this->rule(['code' => 'AG', 'beneficiary_type' => 'agent', 'beneficiary_id' => $agent->public_id, 'basis' => 'fixed', 'rate_bp' => null, 'fixed_minor' => 7500]);

        $booking = $this->booking();
        $this->pay($booking, 100000);

        $commission = $this->onlyCommission();
        $this->assertSame('agent', $commission->beneficiary_type);
        $commission = $this->commissions->post($this->commissions->approve($commission, $this->checker->id), $this->checker->id);

        $line = $commission->journalEntry->lines->firstWhere(fn ($l) => $l->credit_minor > 0);
        $this->assertSame('supplier', $line->party_type);
        $this->assertSame($agent->id, $line->party_id);
        $this->assertSame(7500, (int) $line->credit_minor);
    }

    public function test_branch_beneficiary_uses_the_payables_control_account(): void
    {
        $this->rule(['code' => 'BR', 'beneficiary_type' => 'branch', 'rate_bp' => 200]);
        $booking = $this->booking();
        $this->pay($booking, 100000);

        $commission = $this->onlyCommission();
        $this->assertSame($this->branch->id, $commission->beneficiary_id);
        $commission = $this->commissions->post($this->commissions->approve($commission, $this->checker->id), $this->checker->id);
        $line = $commission->journalEntry->lines->firstWhere(fn ($l) => $l->credit_minor > 0);
        $this->assertSame('2100', $line->account->code);
        $this->assertSame('branch', $line->party_type);
    }

    // ---- disabled behaviour ---------------------------------------------------

    public function test_posting_is_refused_until_the_accounting_policy_is_approved(): void
    {
        $commission = $this->commissions->approve($this->accruedCommission(), $this->checker->id);
        $this->settings()->set('accounting', 'accounting_policy_approved', 'false');

        try {
            $this->commissions->post($commission, $this->checker->id);
            $this->fail('Posting must be refused.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('policy', $e->getMessage());
        }

        $this->assertSame(0, JournalEntry::query()->where('source_type', 'commission')->count());
        $this->assertSame('approved', $commission->refresh()->status);
    }

    public function test_auto_post_is_off_by_default_and_posts_on_approval_when_enabled(): void
    {
        $this->assertFalse($this->settings()->bool('accounting', 'commission_auto_post'));

        $commission = $this->accruedCommission();
        $this->settings()->set('accounting', 'commission_auto_post', 'true');
        $commission = $this->commissions->approve($commission, $this->checker->id);

        $this->assertSame('posted', $commission->status);
        $this->assertNotNull($commission->journal_entry_id);
    }

    public function test_auto_post_with_an_unapproved_policy_approves_without_posting_or_failing(): void
    {
        $commission = $this->accruedCommission();
        $this->settings()->set('accounting', 'commission_auto_post', 'true');
        $this->settings()->set('accounting', 'accounting_policy_approved', 'false');

        $commission = $this->commissions->approve($commission, $this->checker->id);

        $this->assertSame('approved', $commission->status);
        $this->assertNull($commission->journal_entry_id);
    }

    // ---- override approval ----------------------------------------------------

    public function test_override_must_be_approved_by_someone_else(): void
    {
        $this->settings()->set('commissions', 'segregation', 'true');
        $commission = $this->accruedCommission();

        $commission = $this->commissions->override($commission, 12345, 'Negotiated bonus', $this->maker->id);
        $this->assertSame(12345, $commission->amount_minor);
        $this->assertSame(10000, $commission->calculated_minor, 'The calculated amount stays visible.');

        try {
            $this->commissions->approve($commission, $this->maker->id);
            $this->fail('The overrider must not approve.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('overrode', $e->getMessage());
        }

        $approved = $this->commissions->approve($commission, $this->checker->id);
        $this->assertSame('approved', $approved->status);
        $this->assertSame(12345, $approved->amount_minor);

        $this->expectException(DomainException::class);
        $this->commissions->override($approved, 1, 'Too late', $this->maker->id);
    }

    public function test_a_beneficiary_cannot_approve_their_own_commission(): void
    {
        $this->settings()->set('commissions', 'segregation', 'true');
        $commission = $this->accruedCommission();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('own commission');
        $this->commissions->approve($commission, $this->seller->user_id);
    }

    public function test_a_rejected_commission_is_not_accrued_again(): void
    {
        $commission = $this->accruedCommission();
        $this->commissions->reject($commission, 'Not eligible', $this->checker->id);

        $this->commissions->syncBooking($commission->booking_id, $this->maker->id);

        $this->assertSame(1, Commission::query()->count());
        $this->assertSame('rejected', $commission->refresh()->status);
    }

    // ---- reversals ------------------------------------------------------------

    public function test_cancelling_the_booking_cancels_an_unposted_accrual(): void
    {
        $commission = $this->accruedCommission();
        $booking = $commission->booking;

        // Refund the money first so cancellation is allowed to proceed.
        app(BookingService::class)->cancel($booking, 'Customer changed plans', 0, $this->maker->id);

        $commission->refresh();
        $this->assertSame('cancelled', $commission->status);
        $this->assertNull($commission->active_key);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'commission')->count());
    }

    public function test_cancelling_the_booking_reverses_a_posted_commission(): void
    {
        $commission = $this->accruedCommission();
        $commission = $this->commissions->post($this->commissions->approve($commission, $this->checker->id), $this->checker->id);

        app(BookingService::class)->cancel($commission->booking, 'Customer changed plans', 0, $this->maker->id);

        $commission->refresh();
        $this->assertSame('reversed', $commission->status);
        $this->assertNotNull($commission->reversal_journal_entry_id);
        $reversal = $commission->reversalJournalEntry;
        $this->assertSame($commission->journal_entry_id, $reversal->reversal_of_id);
        $this->assertSame('commission', $reversal->source_type);

        $expenseNet = (int) DB::table('journal_lines')->where('account_id', $this->account('6700')->id)
            ->selectRaw('COALESCE(SUM(base_debit_minor - base_credit_minor), 0) as b')->value('b');
        $this->assertSame(0, $expenseNet);
    }

    public function test_a_refund_that_breaks_full_collection_reverses_the_commission(): void
    {
        $commission = $this->accruedCommission();
        $commission = $this->commissions->post($this->commissions->approve($commission, $this->checker->id), $this->checker->id);
        $payment = Payment::query()->firstOrFail();

        $payments = app(PaymentService::class);
        $refund = $payments->requestRefund([
            'payment_id' => $payment->public_id,
            'booking_id' => $commission->booking->public_id,
            'amount_minor' => 1000,
            'reason' => 'Hotel downgrade',
        ], $this->maker->id);
        $payments->approveRefund($refund, $this->maker->id);

        $commission->refresh();
        $this->assertSame('reversed', $commission->status);
        $this->assertStringContainsString('fully collected', (string) $commission->reversal_reason);

        // Collecting the shortfall again accrues a fresh commission with a new number.
        $this->pay($commission->booking->refresh(), 1000);
        $this->assertSame(2, Commission::query()->count());
        $this->assertSame('accrued', Commission::query()->latest('id')->first()->status);
    }

    public function test_unallocating_the_receipt_cancels_the_accrual(): void
    {
        $commission = $this->accruedCommission();
        $payment = Payment::query()->firstOrFail();

        app(PaymentService::class)->unallocate($payment, $commission->booking->public_id, $this->maker->id);

        $this->assertSame('cancelled', $commission->refresh()->status);
    }

    public function test_cancelling_the_receipt_cancels_the_accrual(): void
    {
        $commission = $this->accruedCommission();
        $payment = Payment::query()->firstOrFail();

        app(PaymentService::class)->cancel($payment, 'Bounced', $this->maker->id);

        $this->assertSame('cancelled', $commission->refresh()->status);
    }

    public function test_a_blocked_reversal_is_flagged_instead_of_breaking_the_cancellation(): void
    {
        $commission = $this->accruedCommission();
        $commission = $this->commissions->post($this->commissions->approve($commission, $this->checker->id), $this->checker->id);

        $this->settings()->set('accounting', 'accounting_policy_approved', 'false');
        $this->commissions->syncBooking($commission->booking_id);
        // booking still fully collected: nothing to reverse
        $this->assertSame('posted', $commission->refresh()->status);

        DB::table('bookings')->where('id', $commission->booking_id)->update(['status' => 'cancelled']);
        $this->commissions->syncBooking($commission->booking_id);

        $commission->refresh();
        $this->assertSame('posted', $commission->status);
        $this->assertTrue($commission->needs_reversal);

        // Once the policy is approved again an accountant reverses it manually.
        $this->settings()->set('accounting', 'accounting_policy_approved', 'true');
        $reversed = $this->commissions->reverse($commission, 'Booking cancelled', $this->maker->id);
        $this->assertSame('reversed', $reversed->status);
        $this->assertFalse($reversed->needs_reversal);
    }

    public function test_a_payout_made_before_a_reversal_stays_booked_and_is_reported(): void
    {
        $commission = $this->accruedCommission();
        $commission = $this->commissions->post($this->commissions->approve($commission, $this->checker->id), $this->checker->id);
        $cash = CashAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1110'))->firstOrFail();
        $this->commissions->pay($commission, ['cash_account_id' => $cash->public_id, 'amount_minor' => 3000], $this->checker->id);

        $this->commissions->reverse($commission->refresh(), 'Booking cancelled', $this->maker->id);

        $commission->refresh();
        $this->assertSame('reversed', $commission->status);
        $this->assertSame(3000, $commission->paid_minor);
        $payable = $commission->journalEntry->lines->firstWhere(fn ($l) => $l->credit_minor > 0)->account_id;
        $balance = (int) DB::table('journal_lines')->where('account_id', $payable)
            ->selectRaw('COALESCE(SUM(base_debit_minor - base_credit_minor), 0) as b')->value('b');
        $this->assertSame(3000, $balance, 'The beneficiary now owes the paid amount back.');
    }

    // ---- cross-branch eligibility -----------------------------------------------

    public function test_employee_of_another_branch_earns_only_when_the_rule_allows_cross_branch(): void
    {
        $other = $this->otherBranch();
        $remote = $this->employee('R1', $other);
        $this->rule(['code' => 'REMOTE', 'beneficiary_id' => $remote->public_id, 'rate_bp' => 300]);

        $booking = $this->booking();
        $this->pay($booking, 100000);
        $this->assertSame(0, Commission::query()->count(), 'Cross-branch pay is off by default.');

        $this->rule(['code' => 'REMOTE2', 'beneficiary_id' => $remote->public_id, 'rate_bp' => 300, 'allow_cross_branch' => true]);
        $this->commissions->syncBooking($booking, $this->maker->id);

        $commission = $this->onlyCommission();
        $this->assertSame($remote->id, $commission->beneficiary_id);
        $this->assertSame($this->branch->id, $commission->branch_id, 'The expense lands on the booking branch.');
        $this->assertSame(3000, $commission->amount_minor);
    }

    public function test_inactive_beneficiaries_are_skipped(): void
    {
        $this->rule();
        $this->seller->update(['is_active' => false]);

        $booking = $this->booking();
        $this->pay($booking, 100000);

        $this->assertSame(0, Commission::query()->count());
    }

    public function test_rule_scoped_to_another_branch_does_not_apply(): void
    {
        $other = $this->otherBranch();
        $this->rule(['branch_id' => $other->public_id]);

        $booking = $this->booking();
        $this->pay($booking, 100000);

        $this->assertSame(0, Commission::query()->count());
    }

    public function test_postgres_safe_unique_slot_prevents_duplicate_active_accruals(): void
    {
        $commission = $this->accruedCommission();

        $this->expectException(QueryException::class);
        Commission::query()->create([
            'number' => 'DUP', 'booking_id' => $commission->booking_id, 'branch_id' => $commission->branch_id,
            'commission_rule_id' => $commission->commission_rule_id, 'rule_code' => 'X', 'rule_version' => 1,
            'beneficiary_type' => $commission->beneficiary_type, 'beneficiary_id' => $commission->beneficiary_id,
            'basis' => 'fixed', 'calculated_minor' => 1, 'amount_minor' => 1, 'currency_code' => 'EGP',
            'status' => 'accrued', 'active_key' => $commission->active_key, 'accrued_on' => now()->toDateString(),
        ]);
    }

    // ---- statements -----------------------------------------------------------

    public function test_beneficiary_statement_summarises_accruals_payouts_and_reversals(): void
    {
        $commission = $this->accruedCommission();
        $commission = $this->commissions->post($this->commissions->approve($commission, $this->checker->id), $this->checker->id);
        $cash = CashAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1110'))->firstOrFail();
        $this->commissions->pay($commission, ['cash_account_id' => $cash->public_id, 'amount_minor' => 2500], $this->checker->id);

        $statement = $this->commissions->statement('employee', $this->seller->id, now()->startOfYear()->toDateString(), now()->addDay()->toDateString(), [$this->branch->id]);

        $this->assertSame(7500, $statement['summary']['payable_minor']);
        $this->assertSame(2500, $statement['summary']['paid_minor']);
        $this->assertCount(2, $statement['lines']);

        // A user without access to the branch sees nothing.
        $hidden = $this->commissions->statement('employee', $this->seller->id, now()->startOfYear()->toDateString(), now()->addDay()->toDateString(), []);
        $this->assertSame([], $hidden['lines']);
    }

    // ---- API ------------------------------------------------------------------

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    public function test_api_rule_lifecycle_and_permissions(): void
    {
        $payload = [
            'code' => 'api1', 'name_ar' => 'قاعدة', 'name_en' => 'API rule',
            'beneficiary_type' => 'employee', 'basis' => 'percent_margin', 'rate_bp' => 250,
            'effective_from' => now()->toDateString(), 'service_type' => 'hotel',
        ];
        $headers = ['X-Branch-Id' => $this->branch->public_id];

        $reservations = User::factory()->create();
        $reservations->branches()->attach($this->branch->id);
        $reservations->assignRole('reservations');
        $this->actAs($reservations);
        $this->getJson('/api/v1/commission-rules', $headers)->assertForbidden();
        $this->postJson('/api/v1/commission-rules', $payload, $headers)->assertForbidden();

        $this->actAs($this->maker);
        $created = $this->postJson('/api/v1/commission-rules', $payload, $headers)->assertCreated();
        $id = $created->json('data.id');
        $this->assertSame('API1', $created->json('data.code'));
        $this->assertSame('draft', $created->json('data.status'));

        $this->postJson('/api/v1/commission-rules', ['rate_bp' => 99999] + $payload, $headers)->assertUnprocessable();
        $this->postJson("/api/v1/commission-rules/{$id}/submit", [], $headers)->assertOk()->assertJsonPath('data.status', 'pending_approval');

        $this->actAs($this->checker);
        $this->postJson("/api/v1/commission-rules/{$id}/approve", [], $headers)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson('/api/v1/commission-rules?status=approved', $headers)->assertOk()->assertJsonPath('data.0.code', 'API1');
        $this->patchJson("/api/v1/commission-rules/{$id}", ['rate_bp' => 10], $headers)->assertUnprocessable();
        $this->postJson("/api/v1/commission-rules/{$id}/new-version", [], $headers)->assertCreated()->assertJsonPath('data.version', 2);
    }

    public function test_api_commission_workflow_and_branch_isolation(): void
    {
        $commission = $this->accruedCommission();
        $headers = ['X-Branch-Id' => $this->branch->public_id];
        $cash = CashAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1110'))->firstOrFail();

        $this->actAs($this->checker);
        $this->getJson('/api/v1/commissions?status=accrued', $headers)->assertOk()->assertJsonPath('data.0.id', $commission->public_id);
        $this->postJson("/api/v1/commissions/{$commission->public_id}/override", ['amount_minor' => 9000, 'reason' => 'Split with colleague'], $headers)
            ->assertOk()->assertJsonPath('data.amount_minor', 9000)->assertJsonPath('data.calculated_minor', 10000);
        $this->postJson("/api/v1/commissions/{$commission->public_id}/approve", [], $headers)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/commissions/{$commission->public_id}/post", [], $headers)->assertOk()->assertJsonPath('data.status', 'posted');
        $this->postJson("/api/v1/commissions/{$commission->public_id}/pay", ['cash_account_id' => $cash->public_id, 'amount_minor' => 9000], $headers)
            ->assertCreated()->assertJsonPath('data.amount_minor', 9000);
        $this->getJson("/api/v1/commissions/{$commission->public_id}", $headers)->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payments.0.amount_minor', 9000);

        $this->getJson('/api/v1/commissions/beneficiaries', $headers)->assertOk()->assertJsonPath('data.0.paid_minor', 9000);
        $this->getJson('/api/v1/commissions/statement?beneficiary_type=employee&beneficiary_id='.$this->seller->public_id, $headers)
            ->assertOk()->assertJsonPath('data.summary.paid_minor', 9000);

        // A user assigned only to another branch cannot touch it.
        $other = $this->otherBranch();
        $outsider = User::factory()->create();
        $outsider->branches()->attach($other->id);
        $outsider->assignRole('accountant');
        $this->actAs($outsider);
        $this->getJson("/api/v1/commissions/{$commission->public_id}", ['X-Branch-Id' => $other->public_id])->assertForbidden();
        $this->postJson("/api/v1/commissions/{$commission->public_id}/reverse", ['reason' => 'x'], ['X-Branch-Id' => $other->public_id])->assertForbidden();
        $this->getJson('/api/v1/commissions', ['X-Branch-Id' => $other->public_id])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_changing_commission_auto_post_requires_the_policy_permission(): void
    {
        $this->actAs($this->maker); // accountant: holds accounting.approve_policy
        $this->putJson('/api/v1/settings', ['settings' => [['group' => 'accounting', 'key' => 'commission_auto_post', 'value' => 'true']]])->assertOk();
        $this->assertTrue($this->settings()->bool('accounting', 'commission_auto_post'));

        $viewer = User::factory()->create();
        $viewer->branches()->attach($this->branch->id);
        $viewer->givePermissionTo('settings.update');
        $this->actAs($viewer);
        $this->putJson('/api/v1/settings', ['settings' => [['group' => 'accounting', 'key' => 'commission_auto_post', 'value' => 'false']]])->assertForbidden();
        $this->putJson('/api/v1/settings', ['settings' => [['group' => 'accounting', 'key' => 'commission_expense_account_code', 'value' => '2100']]])->assertUnprocessable();
    }
}
