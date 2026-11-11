<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Models\InvoiceApplication;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Invoices\Services\InvoiceSettlementService;
use App\Modules\Parties\Models\PartyAccount;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'invoices.view', 'invoices.create', 'invoices.approve',
        'journal.view', 'journal.create', 'payments.create',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(self::PERMISSIONS);

        Sanctum::actingAs($this->user);
    }

    private function headers(?Branch $branch = null, array $extra = []): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id] + $extra;
    }

    private function customerInvoicePayload(array $overrides = []): array
    {
        $customer = $this->customer();
        $arAccount = $this->account('1100');

        return $overrides + [
            'type' => 'customer_invoice',
            'party_type' => 'customer',
            'party_id' => $customer->public_id,
            'issue_date' => now()->toDateString(),
            'currency' => 'EGP',
            'lines' => [
                [
                    'account_id' => $this->account('4100')->public_id,
                    'description' => 'Tour package sale',
                    'credit_minor' => 100000,
                ],
            ],
        ];
    }

    private function supplierInvoicePayload(array $overrides = []): array
    {
        $supplier = $this->supplier();

        return $overrides + [
            'type' => 'supplier_invoice',
            'party_type' => 'supplier',
            'party_id' => $supplier->public_id,
            'issue_date' => now()->toDateString(),
            'currency' => 'EGP',
            'lines' => [
                [
                    'account_id' => $this->account('5100')->public_id,
                    'description' => 'Hotel cost',
                    'debit_minor' => 100000,
                    'tax_minor' => 15000,
                    'tax_account_id' => $this->account('1160')->public_id,
                ],
            ],
        ];
    }

    private function create(array $payload = []): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson('/api/v1/invoices', $payload);
    }

    private function balance(string $code): int
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jl.account_id', $this->accountFamily($code))
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor),0) as d, COALESCE(SUM(jl.base_credit_minor),0) as c')
            ->first();

        return (int) $row->d - (int) $row->c;
    }

    public function test_invoice_index_eager_loads_customer_and_supplier_parties(): void
    {
        $this->create($this->customerInvoicePayload())->assertCreated();
        $this->create($this->supplierInvoicePayload())->assertCreated();

        $response = $this->withHeaders($this->headers())->getJson('/api/v1/invoices')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertNotEmpty($response->json('data.0.party.id'));
        $this->assertNotEmpty($response->json('data.1.party.id'));
    }

    public function test_customer_invoice_lifecycle_posts_to_customer_subaccount(): void
    {
        $payload = $this->customerInvoicePayload();
        $id = $this->create($payload)->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_minor', 100000)
            ->json('data.id');

        // Subaccount provisioned under AR control account.
        $this->assertSame(1, PartyAccount::query()->where('party_type', 'customer')
            ->where('party_id', $this->customer()->id)
            ->where('role', 'receivable')->count());

        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());

        $this->postJson("/api/v1/invoices/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->postJson("/api/v1/invoices/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $issued = $this->postJson("/api/v1/invoices/{$id}/issue")->assertOk()->assertJsonPath('data.status', 'issued');
        $this->assertNotNull($issued->json('data.journal_entry_id'));

        $entry = JournalEntry::query()->where('source_type', 'invoice')->where('source_id', Invoice::query()->where('public_id', $id)->firstOrFail()->id)->firstOrFail();
        $this->assertSame($entry->lines->sum('base_debit_minor'), $entry->lines->sum('base_credit_minor'));

        // Customer subaccount debited, revenue credited.
        $subaccount = PartyAccount::query()->where('party_type', 'customer')->where('party_id', $this->customer()->id)->where('role', 'receivable')->firstOrFail()->account;
        $this->assertSame(100000, $this->balance($subaccount->code));
        $this->assertSame(-100000, $this->balance('4100'));
    }

    public function test_supplier_invoice_posts_to_supplier_subaccount_and_tax(): void
    {
        $supplier = $this->supplier();
        $payload = $this->supplierInvoicePayload(['party_id' => $supplier->public_id]);
        $id = $this->create($payload)->assertCreated()->json('data.id');

        $this->postJson("/api/v1/invoices/{$id}/submit")->assertOk();
        $this->postJson("/api/v1/invoices/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/invoices/{$id}/issue")->assertOk();

        $subaccount = PartyAccount::query()->where('party_type', 'supplier')->where('party_id', $supplier->id)->where('role', 'payable')->firstOrFail()->account;
        $this->assertSame(-115000, $this->balance($subaccount->code));
        $this->assertSame(100000, $this->balance('5100'));
        $this->assertSame(15000, $this->balance('1160'));
    }

    public function test_the_creator_cannot_approve_their_own_invoice_unless_segregation_is_off(): void
    {
        app(SettingService::class)->set('invoices', 'segregation', 'true');

        $id = $this->create($this->customerInvoicePayload(['submit' => true]))->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->json('data.id');

        $this->postJson("/api/v1/invoices/{$id}/approve")->assertStatus(422);
        $this->assertSame('submitted', Invoice::query()->where('public_id', $id)->firstOrFail()->status);

        // A different approver is never blocked by the maker-checker rule.
        $approver = User::factory()->create();
        $approver->branches()->attach($this->branch->id);
        $approver->givePermissionTo(['invoices.view', 'invoices.approve']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($approver);
        $this->withHeaders($this->headers())->postJson("/api/v1/invoices/{$id}/approve")->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // With segregation off, the maker may approve their own invoice.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->user);
        app(SettingService::class)->set('invoices', 'segregation', 'false');
        $own = $this->create($this->supplierInvoicePayload(['submit' => true]))->assertCreated()->json('data.id');

        $this->postJson("/api/v1/invoices/{$own}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_customer_invoice_partial_settlement_posts_realized_fx_gain(): void
    {
        $customer = $this->customer();
        $customer->update(['currency_code' => 'USD']);
        $service = app(InvoiceService::class);
        $invoice = $service->create([
            'type' => 'customer_invoice',
            'party_type' => 'customer',
            'party_id' => $customer->public_id,
            'issue_date' => now()->toDateString(),
            'currency' => 'USD',
            'fx_rate' => '50',
            'lines' => [[
                'account_id' => $this->account('4100')->public_id,
                'credit_minor' => 100000,
            ]],
        ], $this->branch, $this->user->id);
        $service->submit($invoice, $this->user->id);
        $service->approve($invoice, $this->user->id);
        $service->issue($invoice, $this->user->id);

        $receipt = app(PaymentService::class)->record([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $customer->public_id,
            'cash_account_id' => $this->account('1110')->public_id,
            'amount_minor' => 40000,
            'currency' => 'USD',
            'fx_rate' => '52',
            'paid_at' => now()->toIso8601String(),
            'method' => 'cash',
        ], $this->user->id);

        app(InvoiceSettlementService::class)->applyReceipt($receipt, $invoice, 40000, $this->user->id);
        $entry = JournalEntry::query()->where('source_type', 'invoice_application')->firstOrFail();
        $this->assertSame(80000, (int) $entry->lines()->whereHas('account', fn ($q) => $q->where('code', '7900'))->sum('base_credit_minor'));
        $this->assertSame(2080000, (int) $entry->lines()->sum('base_debit_minor'));
        $this->assertSame(2080000, (int) $entry->lines()->sum('base_credit_minor'));
        $this->assertSame(60000, (int) $invoice->fresh()->outstanding_minor);
    }

    public function test_invoice_collection_accepts_split_methods_and_partial_then_full_receipts(): void
    {
        $this->user->givePermissionTo('payments.create');
        $payload = $this->customerInvoicePayload();
        $id = $this->create($payload)->assertCreated()->json('data.id');
        $invoice = Invoice::query()->where('public_id', $id)->firstOrFail();
        $service = app(InvoiceService::class);
        $service->submit($invoice, $this->user->id);
        $service->approve($invoice, $this->user->id);
        $service->issue($invoice, $this->user->id);

        $cash = $this->account('1110');
        $cashMethod = PaymentMethod::query()->create([
            'code' => 'COLLECTION-CASH', 'name_ar' => 'نقدي', 'name_en' => 'Cash',
            'type' => 'cash', 'default_cash_account_id' => $cash->id, 'is_active' => true,
        ]);
        $cardMethod = PaymentMethod::query()->create([
            'code' => 'COLLECTION-CARD', 'name_ar' => 'بطاقة', 'name_en' => 'Card',
            'type' => 'card', 'default_cash_account_id' => $cash->id, 'is_active' => true,
        ]);

        $collection = $this->withHeaders($this->headers())->postJson("/api/v1/invoices/{$id}/collections", [
            'receipts' => [
                [
                    'amount_minor' => 30000, 'cash_account_id' => $cash->public_id,
                    'payment_method_id' => $cashMethod->public_id, 'paid_at' => now()->toDateString(),
                ],
                [
                    'amount_minor' => 20000, 'cash_account_id' => $cash->public_id,
                    'payment_method_id' => $cardMethod->public_id, 'paid_at' => now()->toDateString(),
                ],
            ],
        ])->assertCreated()->assertJsonPath('data.invoice.outstanding_minor', 50000);
        $this->assertCount(2, $collection->json('data.receipts'));
        $this->assertSame(2, InvoiceApplication::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(2, Payment::query()->where('status', 'posted')->count());
        $this->assertSame(0, (int) Payment::query()->sum('unallocated_minor'));

        $this->withHeaders($this->headers())->postJson("/api/v1/invoices/{$id}/collections", [
            'receipts' => [[
                'amount_minor' => 50000, 'cash_account_id' => $cash->public_id,
                'payment_method_id' => $cashMethod->public_id, 'paid_at' => now()->toDateString(),
            ]],
        ])->assertCreated()->assertJsonPath('data.invoice.outstanding_minor', 0);
        $this->assertSame(3, Payment::query()->count());
        $this->assertSame(3, InvoiceApplication::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_split_invoice_collections_roll_back_every_receipt_on_failure(): void
    {
        $this->user->givePermissionTo('payments.create');
        $payload = $this->customerInvoicePayload();
        $id = $this->create($payload)->assertCreated()->json('data.id');
        $invoice = Invoice::query()->where('public_id', $id)->firstOrFail();
        $service = app(InvoiceService::class);
        $service->submit($invoice, $this->user->id);
        $service->approve($invoice, $this->user->id);
        $service->issue($invoice, $this->user->id);

        $cash = $this->account('1110');
        $method = PaymentMethod::query()->create([
            'code' => 'COLLECTION-TEST', 'name_ar' => 'نقدي', 'name_en' => 'Cash',
            'type' => 'cash', 'default_cash_account_id' => $cash->id, 'is_active' => true,
        ]);
        $inactive = PaymentMethod::query()->create([
            'code' => 'COLLECTION-INACTIVE', 'name_ar' => 'متوقف', 'name_en' => 'Inactive',
            'type' => 'card', 'default_cash_account_id' => $cash->id, 'is_active' => false,
        ]);
        $receipts = [
            ['amount_minor' => 30000, 'cash_account_id' => $cash->public_id,
                'payment_method_id' => $method->public_id, 'paid_at' => now()->toDateString()],
            ['amount_minor' => 20000, 'cash_account_id' => $cash->public_id,
                'payment_method_id' => $inactive->public_id, 'paid_at' => now()->toDateString()],
        ];
        $this->withHeaders($this->headers())->postJson("/api/v1/invoices/{$id}/collections", [
            'receipts' => $receipts,
        ])->assertStatus(422);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, InvoiceApplication::query()->count());
        $this->assertSame(100000, (int) $invoice->fresh()->outstanding_minor);

        $receipts[1]['payment_method_id'] = $method->public_id;
        $receipts[1]['amount_minor'] = 80000;
        $this->withHeaders($this->headers())->postJson("/api/v1/invoices/{$id}/collections", [
            'receipts' => $receipts,
        ])->assertStatus(422);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_only_approved_invoices_can_be_issued_and_once(): void
    {
        $id = $this->create($this->customerInvoicePayload())->assertCreated()->json('data.id');

        $this->postJson("/api/v1/invoices/{$id}/issue")->assertStatus(422);

        $this->postJson("/api/v1/invoices/{$id}/submit")->assertOk();
        $this->postJson("/api/v1/invoices/{$id}/issue")->assertStatus(422);

        $this->postJson("/api/v1/invoices/{$id}/approve")->assertOk();
        $first = $this->postJson("/api/v1/invoices/{$id}/issue")->assertOk()->assertJsonPath('data.status', 'issued');
        $second = $this->postJson("/api/v1/invoices/{$id}/issue")->assertStatus(422);

        $invoiceRow = Invoice::query()->where('public_id', $id)->firstOrFail();
        $this->assertNotNull($invoiceRow->journal_entry_id);
        $this->assertSame($first->json('data.journal_entry_id'), JournalEntry::query()->find($invoiceRow->journal_entry_id)->public_id);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'invoice')->count());
    }

    public function test_draft_and_submitted_invoices_can_be_voided(): void
    {
        $id = $this->create($this->customerInvoicePayload())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/invoices/{$id}/void")->assertOk()->assertJsonPath('data.status', 'voided');

        $id2 = $this->create($this->customerInvoicePayload())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/invoices/{$id2}/submit")->assertOk();
        $this->postJson("/api/v1/invoices/{$id2}/void")->assertOk()->assertJsonPath('data.status', 'voided');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());
    }

    public function test_issued_invoice_cannot_be_voided_but_credited(): void
    {
        $id = $this->create($this->customerInvoicePayload(['submit' => true]))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/invoices/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/invoices/{$id}/issue")->assertOk();

        $this->postJson("/api/v1/invoices/{$id}/void")->assertStatus(422);

        $credit = $this->postJson("/api/v1/invoices/{$id}/credit-note", ['total_minor' => -100000])
            ->assertCreated()
            ->assertJsonPath('data.type', 'credit_note')
            ->assertJsonPath('data.credit_note_of_id', $id)
            ->json('data.id');

        $this->postJson("/api/v1/invoices/{$credit}/issue")->assertOk();

        $subaccount = PartyAccount::query()->where('party_type', 'customer')->where('party_id', $this->customer()->id)->where('role', 'receivable')->firstOrFail()->account;
        $this->assertSame(0, $this->balance($subaccount->code));
        $this->assertSame(0, $this->balance('4100'));
    }

    public function test_partial_credit_note_uses_the_requested_amount_and_is_capped(): void
    {
        $id = $this->create($this->customerInvoicePayload(['submit' => true]))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/invoices/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/invoices/{$id}/issue")->assertOk();

        $this->postJson("/api/v1/invoices/{$id}/credit-note", ['total_minor' => 30000])
            ->assertCreated()
            ->assertJsonPath('data.total_minor', 30000);

        $this->postJson("/api/v1/invoices/{$id}/credit-note", ['total_minor' => 80000])->assertStatus(422);
        $this->postJson("/api/v1/invoices/{$id}/credit-note", ['total_minor' => 70000])->assertCreated();
    }

    public function test_draft_invoice_edit_recomputes_totals_and_allows_memo_only_changes(): void
    {
        $id = $this->create($this->customerInvoicePayload())->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/invoices/{$id}", ['memo' => 'Edited'])
            ->assertOk()
            ->assertJsonPath('data.total_minor', 100000);

        $this->patchJson("/api/v1/invoices/{$id}", ['lines' => [
            ['account_id' => $this->account('4100')->public_id, 'credit_minor' => 250000],
        ]])->assertOk()
            ->assertJsonPath('data.total_minor', 250000)
            ->assertJsonPath('data.outstanding_minor', 250000);

        $this->postJson("/api/v1/invoices/{$id}/submit")->assertOk();
        $this->postJson("/api/v1/invoices/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/invoices/{$id}/issue")->assertOk();
    }

    public function test_simple_invoice_line_preserves_quantity_and_unit_price(): void
    {
        $payload = $this->customerInvoicePayload();
        $payload['lines'][0]['quantity'] = 2;
        $payload['lines'][0]['unit_price_minor'] = 50000;

        $id = $this->create($payload)->assertCreated()
            ->assertJsonPath('data.total_minor', 100000)
            ->assertJsonPath('data.lines.0.quantity', 2)
            ->assertJsonPath('data.lines.0.unit_price_minor', 50000)
            ->json('data.id');

        $this->postJson("/api/v1/invoices/{$id}/submit")->assertOk();
        $this->postJson("/api/v1/invoices/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/invoices/{$id}/issue")->assertOk();
        $this->assertSame(-100000, $this->balance('4100'));
    }

    public function test_invoice_rejects_quantity_price_amount_mismatch(): void
    {
        $payload = $this->customerInvoicePayload();
        $payload['lines'][0]['quantity'] = 2;
        $payload['lines'][0]['unit_price_minor'] = 40000;

        $this->create($payload)->assertStatus(422);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_validation_and_business_rules(): void
    {
        $this->create([])->assertStatus(422);
        $this->create(['type' => 'customer_invoice', 'party_type' => 'customer', 'party_id' => 'missing'])->assertStatus(422);
        $this->create($this->customerInvoicePayload(['lines' => []]))->assertStatus(422);

        $unbalanced = $this->customerInvoicePayload();
        $unbalanced['lines'] = [
            ['account_id' => $this->account('4100')->public_id, 'debit_minor' => 100, 'credit_minor' => 100],
        ];
        $this->create($unbalanced)->assertStatus(422);

        $noAmount = $this->customerInvoicePayload();
        $noAmount['lines'] = [
            ['account_id' => $this->account('4100')->public_id],
        ];
        $this->create($noAmount)->assertStatus(422);

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_closed_period_blocks_issue(): void
    {
        $id = $this->create($this->customerInvoicePayload(['submit' => true]))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/invoices/{$id}/approve")->assertOk();

        FiscalPeriod::query()->update(['status' => 'closed']);

        $this->postJson("/api/v1/invoices/{$id}/issue")->assertStatus(422);
        $this->assertSame('approved', Invoice::query()->where('public_id', $id)->firstOrFail()->status);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'invoice')->count());
    }

    public function test_branch_scope_and_permissions_are_enforced(): void
    {
        $id = $this->create($this->customerInvoicePayload(['submit' => true]))->assertCreated()->json('data.id');

        $other = Branch::query()->create([
            'code' => 'B2', 'name_ar' => 'ف', 'name_en' => 'Branch 2', 'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $stranger = User::factory()->create();
        $stranger->branches()->attach($other->id);
        $stranger->givePermissionTo(self::PERMISSIONS);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($stranger);
        $this->withHeaders($this->headers($other))->getJson("/api/v1/invoices/{$id}")->assertStatus(403);
        $this->withHeaders($this->headers($other))->postJson("/api/v1/invoices/{$id}/issue")->assertStatus(403);
        $this->assertCount(0, $this->withHeaders($this->headers($other))->getJson('/api/v1/invoices')->json('data'));

        $clerk = User::factory()->create();
        $clerk->branches()->attach($this->branch->id);
        $clerk->givePermissionTo(['invoices.view', 'invoices.create']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($clerk);
        $this->withHeaders($this->headers())->postJson("/api/v1/invoices/{$id}/approve")->assertStatus(403);
        $this->withHeaders($this->headers())->postJson("/api/v1/invoices/{$id}/issue")->assertStatus(403);
    }

    public function test_idempotent_creation_by_key(): void
    {
        $payload = $this->customerInvoicePayload();
        $first = $this->withHeaders(['Idempotency-Key' => 'inv-key-1'])->postJson('/api/v1/invoices', $payload)->assertCreated()->json('data.id');
        $second = $this->withHeaders(['Idempotency-Key' => 'inv-key-1'])->postJson('/api/v1/invoices', $payload)->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_invoice_tax_is_rejected_when_tax_is_disabled(): void
    {
        app(SettingService::class)->set('tax', 'enabled', 'false');

        $this->create($this->supplierInvoicePayload())->assertStatus(422)
            ->assertJsonPath('message', 'Line 1: Tax is disabled in settings.');

        $customerWithTax = $this->customerInvoicePayload();
        $customerWithTax['lines'][0]['tax_minor'] = 14000;
        $this->create($customerWithTax)->assertStatus(422);

        // Zero tax is always fine.
        $this->create($this->customerInvoicePayload())->assertCreated();

        app(SettingService::class)->set('tax', 'enabled', 'true');
        $this->create($this->supplierInvoicePayload())->assertCreated();
    }
}
