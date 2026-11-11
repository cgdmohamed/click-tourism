<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingDocument;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Parties\Models\PartyAccount;
use App\Modules\Treasury\Models\CashAccount;
use Database\Seeders\TreasurySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class AccountingDocumentTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'documents.view', 'documents.create', 'documents.approve',
        'journal.view', 'journal.create',
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

        $this->seed(TreasurySeeder::class);

        Sanctum::actingAs($this->user);
    }

    private function headers(?Branch $branch = null, array $extra = []): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id] + $extra;
    }

    private function receiptVoucherPayload(array $overrides = []): array
    {
        $customer = $this->customer();

        return $overrides + [
            'type' => 'receipt_voucher',
            'party_type' => 'customer',
            'party_id' => $customer->public_id,
            'document_date' => now()->toDateString(),
            'currency' => 'EGP',
            'lines' => [
                [
                    'account_id' => $this->account('1110')->public_id,
                    'description' => 'Customer receipt',
                    'debit_minor' => 100000,
                ],
                [
                    'account_id' => $this->account('1100')->public_id,
                    'description' => 'AR settlement',
                    'credit_minor' => 100000,
                    'party_type' => 'customer',
                    'party_id' => $customer->public_id,
                ],
            ],
        ];
    }

    private function paymentVoucherPayload(array $overrides = []): array
    {
        $supplier = $this->supplier();

        return $overrides + [
            'type' => 'payment_voucher',
            'party_type' => 'supplier',
            'party_id' => $supplier->public_id,
            'document_date' => now()->toDateString(),
            'currency' => 'EGP',
            'cash_account_id' => $this->cashAccountPublicId(),
            'lines' => [
                [
                    'account_id' => $this->account('2100')->public_id,
                    'description' => 'Supplier payment',
                    'debit_minor' => 100000,
                    'party_type' => 'supplier',
                    'party_id' => $supplier->public_id,
                ],
                [
                    'account_id' => $this->account('1110')->public_id,
                    'description' => 'Cash payment',
                    'credit_minor' => 100000,
                ],
            ],
        ];
    }

    private function create(array $payload = []): TestResponse
    {
        return $this->withHeaders($this->headers())->postJson('/api/v1/accounting-documents', $payload);
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

    public function test_document_lifecycle_posts_to_ledger(): void
    {
        $id = $this->create($this->receiptVoucherPayload())->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->assertSame(0, JournalEntry::query()->where('source_type', 'accounting_document')->count());

        $this->postJson("/api/v1/accounting-documents/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'accounting_document')->count());

        app(SettingService::class)->set('documents', 'segregation', 'false');
        $this->postJson("/api/v1/accounting-documents/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'accounting_document')->count());

        $posted = $this->postJson("/api/v1/accounting-documents/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertNotNull($posted->json('data.journal_entry_id'));

        $entry = JournalEntry::query()->where('source_type', 'accounting_document')->firstOrFail();
        $this->assertSame($entry->lines->sum('base_debit_minor'), $entry->lines->sum('base_credit_minor'));

        $this->assertSame(100000, $this->balance('1110'));
        $this->assertSame(-100000, $this->balance('1100'));
    }

    public function test_document_posting_is_idempotent(): void
    {
        $id = $this->create($this->receiptVoucherPayload())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/accounting-documents/{$id}/submit")->assertOk();
        app(SettingService::class)->set('documents', 'segregation', 'false');
        $this->postJson("/api/v1/accounting-documents/{$id}/approve")->assertOk();

        $first = $this->postJson("/api/v1/accounting-documents/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');
        $second = $this->postJson("/api/v1/accounting-documents/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $this->assertSame($first->json('data.journal_entry_id'), $second->json('data.journal_entry_id'));
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'accounting_document')->count());
    }

    public function test_maker_cannot_approve_own_document(): void
    {
        $this->user->givePermissionTo('documents.approve');
        $id = $this->create($this->receiptVoucherPayload(['submit' => true]))->assertCreated()->json('data.id');

        // Maker cannot approve their own submitted document (segregation on by default).
        $this->postJson("/api/v1/accounting-documents/{$id}/approve")->assertStatus(422);
        $this->assertSame('submitted', AccountingDocument::query()->where('public_id', $id)->firstOrFail()->status);

        // With segregation off, the maker can approve.
        app(SettingService::class)->set('documents', 'segregation', 'false');
        $this->postJson("/api/v1/accounting-documents/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_only_approved_documents_can_be_posted(): void
    {
        $id = $this->create($this->receiptVoucherPayload())->assertCreated()->json('data.id');

        $this->postJson("/api/v1/accounting-documents/{$id}/post")->assertStatus(422);

        $this->postJson("/api/v1/accounting-documents/{$id}/submit")->assertOk();
        app(SettingService::class)->set('documents', 'segregation', 'false');
        $this->postJson("/api/v1/accounting-documents/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/accounting-documents/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');
    }

    public function test_draft_and_submitted_documents_can_be_cancelled(): void
    {
        $id = $this->create($this->receiptVoucherPayload())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/accounting-documents/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $id2 = $this->create($this->receiptVoucherPayload())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/accounting-documents/{$id2}/submit")->assertOk();
        $this->postJson("/api/v1/accounting-documents/{$id2}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(0, JournalEntry::query()->where('source_type', 'accounting_document')->count());
    }

    public function test_posted_document_cannot_be_cancelled_but_corrected(): void
    {
        $id = $this->create($this->receiptVoucherPayload())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/accounting-documents/{$id}/submit")->assertOk();
        app(SettingService::class)->set('documents', 'segregation', 'false');
        $this->postJson("/api/v1/accounting-documents/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/accounting-documents/{$id}/post")->assertOk();

        $this->postJson("/api/v1/accounting-documents/{$id}/cancel")->assertStatus(422);

        // The reversal document is created in the submitted state so it goes
        // through the normal approval workflow (maker-checker applies).
        $correction = $this->postJson("/api/v1/accounting-documents/{$id}/correct", [
            'reason' => 'Wrong amount entered',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.reversal_of_id', $id)
            ->json('data.id');

        $this->postJson("/api/v1/accounting-documents/{$correction}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/accounting-documents/{$correction}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $this->assertSame(0, $this->balance('1110')); // receipt + reversal nets to zero
        $this->assertSame(0, $this->balance('1100'));
        $this->assertSame(2, JournalEntry::query()->where('source_type', 'accounting_document')->count());
    }

    public function test_draft_document_can_be_edited_and_totals_recompute(): void
    {
        $id = $this->create($this->receiptVoucherPayload())->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/accounting-documents/{$id}", [
            'memo' => 'Updated memo',
            'lines' => [
                [
                    'account_id' => $this->account('1110')->public_id,
                    'description' => 'Half the receipt',
                    'debit_minor' => 50000,
                ],
                [
                    'account_id' => $this->account('1100')->public_id,
                    'description' => 'AR settlement',
                    'credit_minor' => 50000,
                    'party_type' => 'customer',
                    'party_id' => $this->customer()->public_id,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.memo', 'Updated memo')
            ->assertJsonPath('data.total_minor', 50000);

        // Unbalanced edits are rejected and leave the draft untouched.
        $this->patchJson("/api/v1/accounting-documents/{$id}", [
            'lines' => [
                ['account_id' => $this->account('1110')->public_id, 'debit_minor' => 1],
                ['account_id' => $this->account('1100')->public_id, 'credit_minor' => 2],
            ],
        ])->assertStatus(422);

        $document = AccountingDocument::query()->where('public_id', $id)->firstOrFail();
        $this->assertSame(50000, $document->total_minor);
        $this->assertSame(2, $document->lines()->count());
    }

    public function test_validation_rules_are_enforced(): void
    {
        $this->create([])->assertStatus(422);
        $this->create(['type' => 'receipt_voucher', 'party_type' => 'customer', 'party_id' => 'missing'])->assertStatus(422);
        $this->create($this->receiptVoucherPayload(['lines' => []]))->assertStatus(422);

        $unbalanced = $this->receiptVoucherPayload();
        $unbalanced['lines'] = [
            ['account_id' => $this->account('1110')->public_id, 'debit_minor' => 100],
            ['account_id' => $this->account('1100')->public_id, 'credit_minor' => 100],
            ['account_id' => $this->account('4100')->public_id, 'credit_minor' => 100],
        ];
        $this->create($unbalanced)->assertStatus(422);

        $this->assertSame(0, AccountingDocument::query()->count());
    }

    public function test_closed_period_blocks_posting(): void
    {
        $id = $this->create($this->receiptVoucherPayload())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/accounting-documents/{$id}/submit")->assertOk();
        app(SettingService::class)->set('documents', 'segregation', 'false');
        $this->postJson("/api/v1/accounting-documents/{$id}/approve")->assertOk();

        FiscalPeriod::query()->update(['status' => 'closed']);

        $this->postJson("/api/v1/accounting-documents/{$id}/post")->assertStatus(422);
        $this->assertSame('approved', AccountingDocument::query()->where('public_id', $id)->firstOrFail()->status);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'accounting_document')->count());
    }

    public function test_branch_scope_and_permissions_are_enforced(): void
    {
        $id = $this->create($this->receiptVoucherPayload(['submit' => true]))->assertCreated()->json('data.id');

        $other = Branch::query()->create([
            'code' => 'B2', 'name_ar' => 'ف', 'name_en' => 'Branch 2', 'accounting_mode' => 'dimension', 'is_active' => true,
        ]);
        $stranger = User::factory()->create();
        $stranger->branches()->attach($other->id);
        $stranger->givePermissionTo(self::PERMISSIONS);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($stranger);
        $this->withHeaders($this->headers($other))->getJson("/api/v1/accounting-documents/{$id}")->assertStatus(403);
        $this->withHeaders($this->headers($other))->postJson("/api/v1/accounting-documents/{$id}/post")->assertStatus(403);
        $this->assertCount(0, $this->withHeaders($this->headers($other))->getJson('/api/v1/accounting-documents')->json('data'));

        $clerk = User::factory()->create();
        $clerk->branches()->attach($this->branch->id);
        $clerk->givePermissionTo(['documents.view', 'documents.create']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($clerk);
        $this->withHeaders($this->headers())->postJson("/api/v1/accounting-documents/{$id}/approve")->assertStatus(403);
        $this->withHeaders($this->headers())->postJson("/api/v1/accounting-documents/{$id}/post")->assertStatus(403);
    }

    public function test_idempotent_creation_by_key(): void
    {
        $payload = $this->receiptVoucherPayload();
        $first = $this->withHeaders(['Idempotency-Key' => 'doc-key-1'])
            ->postJson('/api/v1/accounting-documents', $payload)
            ->assertCreated()->json('data.id');
        $second = $this->withHeaders(['Idempotency-Key' => 'doc-key-1'])
            ->postJson('/api/v1/accounting-documents', $payload)
            ->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, AccountingDocument::query()->count());
    }

    public function test_payment_voucher_posts_correctly(): void
    {
        $id = $this->create($this->paymentVoucherPayload())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/accounting-documents/{$id}/submit")->assertOk();
        app(SettingService::class)->set('documents', 'segregation', 'false');
        $this->postJson("/api/v1/accounting-documents/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/accounting-documents/{$id}/post")->assertOk();

        // Paying a supplier debits AP (reduces the liability) and credits cash.
        $apAccount = (string) app(SettingService::class)->get('accounting', 'ap_account_code', '2100');
        $this->assertSame(100000, $this->balance($apAccount));
        $this->assertSame(-100000, $this->balance('1110'));
    }

    public function test_simple_receipt_uses_cash_and_customer_subaccount_without_manual_lines(): void
    {
        $customer = $this->customer();
        $partyAccount = app(InvoiceService::class)->provisionSubaccount(
            'customer', $customer->id, PartyAccount::ROLE_RECEIVABLE
        );

        $id = $this->create([
            'type' => 'receipt_voucher',
            'simple_mode' => true,
            'party_type' => 'customer',
            'party_id' => $customer->public_id,
            'cash_account_id' => $this->cashAccountPublicId(),
            'amount_minor' => 150000,
            'currency' => 'EGP',
        ])->assertCreated()->assertJsonPath('data.total_minor', 150000)->json('data.id');

        $document = AccountingDocument::query()->where('public_id', $id)->firstOrFail();
        $this->assertCount(2, $document->lines);
        $this->assertSame(150000, (int) $document->lines->sum('debit_minor'));
        $this->assertSame(150000, (int) $document->lines->sum('credit_minor'));
        $this->assertSame(1, $document->lines->where('account_id', $partyAccount->id)->count());

        $this->postJson("/api/v1/accounting-documents/{$id}/submit")->assertOk();
        app(SettingService::class)->set('documents', 'segregation', 'false');
        $this->postJson("/api/v1/accounting-documents/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/accounting-documents/{$id}/post")->assertOk();
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'accounting_document')->count());
    }

    public function test_simple_supplier_payment_generates_balanced_lines(): void
    {
        $supplier = $this->supplier();
        $partyAccount = app(InvoiceService::class)->provisionSubaccount(
            'supplier', $supplier->id, PartyAccount::ROLE_PAYABLE
        );

        $id = $this->create([
            'type' => 'payment_voucher',
            'simple_mode' => true,
            'party_type' => 'supplier',
            'party_id' => $supplier->public_id,
            'cash_account_id' => $this->cashAccountPublicId(),
            'amount_minor' => 75000,
            'currency' => 'EGP',
        ])->assertCreated()->assertJsonPath('data.total_minor', 75000)->json('data.id');

        $lines = AccountingDocument::query()->where('public_id', $id)->firstOrFail()->lines;
        $this->assertSame(75000, (int) $lines->where('account_id', $partyAccount->id)->sum('debit_minor'));
        $this->assertSame(75000, (int) $lines->where('account_id', $this->account('1110')->id)->sum('credit_minor'));
    }

    public function test_simple_mode_rejects_unsupported_types_and_manual_lines(): void
    {
        $customer = $this->customer();
        app(InvoiceService::class)->provisionSubaccount('customer', $customer->id, PartyAccount::ROLE_RECEIVABLE);
        $payload = [
            'type' => 'receipt_voucher',
            'simple_mode' => true,
            'party_type' => 'customer',
            'party_id' => $customer->public_id,
            'cash_account_id' => $this->cashAccountPublicId(),
            'amount_minor' => 10000,
            'currency' => 'EGP',
        ];
        $this->create($payload + ['lines' => [['account_id' => $this->account('1110')->public_id]]])->assertStatus(422);
        $this->create(array_merge($payload, ['type' => 'adjustment_voucher']))->assertStatus(422);
        $this->create(array_merge($payload, ['party_type' => 'supplier']))->assertStatus(422);
        $this->create(array_merge($payload, ['cash_account_id' => 'missing']))->assertStatus(422);
        $this->assertSame(0, AccountingDocument::query()->count());
    }

    public function test_simple_voucher_does_not_silently_settle_foreign_receivables_at_current_rate(): void
    {
        $customer = $this->customer();
        app(InvoiceService::class)->provisionSubaccount('customer', $customer->id, PartyAccount::ROLE_RECEIVABLE);
        $this->create([
            'type' => 'receipt_voucher',
            'simple_mode' => true,
            'party_type' => 'customer',
            'party_id' => $customer->public_id,
            'cash_account_id' => $this->cashAccountPublicId(),
            'amount_minor' => 10000,
            'currency' => 'USD',
            'fx_rate' => '52',
        ])->assertStatus(422);
        $this->assertSame(0, AccountingDocument::query()->count());
    }

    private function cashAccountPublicId(): string
    {
        return CashAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1110'))->first()->public_id;
    }

    private function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    public function test_voucher_tax_is_rejected_when_tax_is_disabled(): void
    {
        $payload = $this->receiptVoucherPayload();
        $payload['lines'][0]['tax_minor'] = 1000;

        app(SettingService::class)->set('tax', 'enabled', 'false');
        $this->create($payload)->assertStatus(422)
            ->assertJsonPath('message', 'Line 1: Tax is disabled in settings.');
        $this->assertSame(0, AccountingDocument::query()->count());

        app(SettingService::class)->set('tax', 'enabled', 'true');
        $this->create($payload)->assertCreated();
    }
}
