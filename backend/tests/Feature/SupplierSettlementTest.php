<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Branch;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Payments\Models\SupplierObligation;
use App\Modules\Payments\Models\SupplierPayment;
use App\Modules\Payments\Models\SupplierPaymentApplication;
use App\Modules\Payments\Services\SupplierSettlementService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class SupplierSettlementTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private Supplier $testSupplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $this->testSupplier = $this->supplier();
    }

    private function obligation(array $overrides = []): SupplierObligation
    {
        return app(SupplierSettlementService::class)->createObligation(array_merge([
            'branch_id' => $this->branch->public_id,
            'supplier_id' => $this->testSupplier->public_id,
            'amount_minor' => 100000,
            'currency' => 'EGP',
            'invoice_reference' => 'INV-'.uniqid(),
        ], $overrides), $this->user->id);
    }

    private function pay(array $overrides = [], ?string $key = null): SupplierPayment
    {
        return app(SupplierSettlementService::class)->recordPayment(array_merge([
            'branch_id' => $this->branch->public_id,
            'supplier_id' => $this->testSupplier->public_id,
            'cash_account_id' => $this->account('1110')->public_id,
            'amount_minor' => 100000,
            'currency' => 'EGP',
            'fx_rate' => '1',
            'paid_at' => Carbon::now()->toIso8601String(),
        ], $overrides), $this->user->id, $key);
    }

    private function entry(string $sourceType, int $sourceId, string $event): JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('event', $event)
            ->firstOrFail();
    }

    public function test_it_creates_an_obligation_with_an_invoice_reference(): void
    {
        $obligation = $this->obligation(['invoice_reference' => 'SUP-2026-001']);

        $this->assertSame('open', $obligation->status);
        $this->assertSame(100000, $obligation->balanceMinor());
        $this->assertSame('SUP-2026-001', $obligation->invoice_reference);
    }

    public function test_a_supplier_payment_settles_an_obligation_and_posts_to_ap(): void
    {
        $obligation = $this->obligation();

        $payment = $this->pay(['supplier_obligation_id' => $obligation->public_id]);

        $this->assertSame(0, $payment->unallocatedMinor());
        $this->assertSame('paid', $obligation->refresh()->status);

        $entry = $this->entry('supplier_payment', $payment->id, 'supplier_payment');

        $this->assertSame(
            100000,
            (int) $entry->lines()->whereHas('account', fn ($query) => $query->where('code', 'like', '2100%'))->sum('debit_minor'),
        );
        $this->assertSame(
            100000,
            (int) $entry->lines()->whereHas('account', fn ($query) => $query->where('code', '1110'))->sum('credit_minor'),
        );
    }

    public function test_a_partial_payment_leaves_the_obligation_partially_paid(): void
    {
        $obligation = $this->obligation();

        $this->pay([
            'supplier_obligation_id' => $obligation->public_id,
            'amount_minor' => 40000,
        ]);

        $obligation->refresh();

        $this->assertSame('partially_paid', $obligation->status);
        $this->assertSame(60000, $obligation->balanceMinor());
    }

    public function test_it_rejects_a_payment_above_the_obligation_balance(): void
    {
        $obligation = $this->obligation(['amount_minor' => 50000]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('exceeds the outstanding balance');

        $this->pay([
            'supplier_obligation_id' => $obligation->public_id,
            'amount_minor' => 60000,
        ]);
    }

    public function test_a_payment_without_an_obligation_is_an_advance_that_can_be_applied_later(): void
    {
        $obligation = $this->obligation();
        $payment = $this->pay(['amount_minor' => 80000]);

        $this->assertSame(80000, $payment->unallocatedMinor());

        $advance = $this->entry('supplier_payment', $payment->id, 'supplier_advance');
        $this->assertSame(
            80000,
            (int) $advance->lines()->whereHas('account', fn ($query) => $query->where('code', '1150'))->sum('debit_minor'),
        );

        $applied = app(SupplierSettlementService::class)->applyAdvance($payment, [
            'supplier_obligation_id' => $obligation->public_id,
            'amount_minor' => 50000,
        ], $this->user->id);

        $this->assertSame(30000, $applied->unallocatedMinor());
        $this->assertSame('partially_paid', $obligation->refresh()->status);

        $application = $applied->applications()->firstOrFail();
        $entry = $this->entry('supplier_payment_application', $application->id, 'apply');

        $this->assertSame(
            50000,
            (int) $entry->lines()->whereHas('account', fn ($query) => $query->where('code', 'like', '2100%'))->sum('debit_minor'),
        );
        $this->assertSame(
            50000,
            (int) $entry->lines()->whereHas('account', fn ($query) => $query->where('code', '1150'))->sum('credit_minor'),
        );
    }

    public function test_it_rejects_applying_more_than_the_advance_amount(): void
    {
        $obligation = $this->obligation();
        $payment = $this->pay(['amount_minor' => 30000]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('exceeds the unapplied amount');

        app(SupplierSettlementService::class)->applyAdvance($payment, [
            'supplier_obligation_id' => $obligation->public_id,
            'amount_minor' => 40000,
        ], $this->user->id);
    }

    public function test_it_rejects_a_duplicate_invoice_reference_for_the_same_supplier(): void
    {
        $this->obligation(['invoice_reference' => 'DUP-1']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('invoice reference already exists');

        $this->obligation(['invoice_reference' => 'DUP-1']);
    }

    public function test_it_rejects_a_payment_in_a_different_currency(): void
    {
        $obligation = $this->obligation();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('must match the obligation currency');

        $this->pay([
            'supplier_obligation_id' => $obligation->public_id,
            'currency' => 'USD',
            'fx_rate' => '0.02',
        ]);
    }

    public function test_recording_with_the_same_idempotency_key_returns_the_same_payment(): void
    {
        $obligation = $this->obligation();

        $first = $this->pay(['supplier_obligation_id' => $obligation->public_id], 'supplier-key');
        $second = $this->pay(['supplier_obligation_id' => $obligation->public_id], 'supplier-key');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SupplierPayment::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'supplier_payment')->count());
    }

    public function test_applying_an_advance_is_idempotent_over_http(): void
    {
        $this->grant('supplier_payments.create');
        $obligation = $this->obligation(['amount_minor' => 50000]);
        $advance = $this->pay(['amount_minor' => 50000]);

        Sanctum::actingAs($this->user);

        $payload = [
            'supplier_obligation_id' => $obligation->public_id,
            'amount_minor' => 50000,
        ];

        $this->withHeader('Idempotency-Key', 'apply-key-1')
            ->postJson('/api/v1/supplier-payments/'.$advance->public_id.'/apply', $payload)
            ->assertOk()
            ->assertJsonPath('data.unallocated_minor', 0);

        $this->withHeader('Idempotency-Key', 'apply-key-1')
            ->postJson('/api/v1/supplier-payments/'.$advance->public_id.'/apply', $payload)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, SupplierPaymentApplication::query()->count());
        $this->assertSame(
            1,
            JournalEntry::query()->where('source_type', 'supplier_payment_application')->count(),
        );
    }

    public function test_a_user_cannot_settle_a_branch_they_are_not_assigned_to(): void
    {
        $other = Branch::query()->create([
            'code' => 'ALX',
            'name_ar' => 'الإسكندرية',
            'name_en' => 'Alexandria',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);

        $this->expectException(AuthorizationException::class);

        $this->obligation(['branch_id' => $other->public_id]);
    }

    public function test_a_user_cannot_view_an_obligation_from_another_branch(): void
    {
        $other = Branch::query()->create([
            'code' => 'ALX',
            'name_ar' => 'الإسكندرية',
            'name_en' => 'Alexandria',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);

        $obligation = SupplierObligation::query()->create([
            'supplier_id' => $this->supplier($other)->id,
            'branch_id' => $other->id,
            'amount_minor' => 1000,
            'currency_code' => 'EGP',
            'status' => 'open',
        ]);

        $this->grant('supplier_obligations.view');
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/supplier-obligations/'.$obligation->public_id)
            ->assertStatus(403);
    }

    private function grant(string $permission): void
    {
        Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo($permission);
    }

    public function test_the_supplier_obligation_endpoint_requires_the_permission(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/supplier-obligations', [
            'supplier_id' => $this->testSupplier->public_id,
            'branch_id' => $this->branch->public_id,
            'amount_minor' => 1000,
            'currency' => 'EGP',
        ])->assertStatus(403);
    }

    public function test_it_creates_an_obligation_and_a_payment_through_the_api(): void
    {
        $this->grant('supplier_obligations.create');
        $this->grant('supplier_payments.create');
        $this->grant('supplier_obligations.view');

        Sanctum::actingAs($this->user);

        $created = $this->postJson('/api/v1/supplier-obligations', [
            'supplier_id' => $this->testSupplier->public_id,
            'branch_id' => $this->branch->public_id,
            'amount_minor' => 25000,
            'currency' => 'EGP',
            'invoice_reference' => 'API-INV-1',
        ])->assertCreated()->assertJsonPath('data.status', 'open');

        $obligationId = $created->json('data.id');

        $this->withHeader('Idempotency-Key', 'api-supplier-payment-1')
            ->postJson('/api/v1/supplier-payments', [
                'supplier_id' => $this->testSupplier->public_id,
                'supplier_obligation_id' => $obligationId,
                'branch_id' => $this->branch->public_id,
                'cash_account_id' => $this->account('1110')->public_id,
                'amount_minor' => 25000,
                'currency' => 'EGP',
                'fx_rate' => '1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.unallocated_minor', 0);

        $this->getJson('/api/v1/supplier-obligations?status=paid')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'paid');
    }
}
