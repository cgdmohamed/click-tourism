<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Invoices\Services\InvoiceSettlementService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\ReceiptDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** ADR-0035: the receipt voucher (سند قبض) PDF of one customer receipt. */
class ReceiptVoucherTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        Permission::query()->firstOrCreate(['name' => 'payments.view', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'payments.create', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(['payments.view', 'payments.create']);
        Sanctum::actingAs($this->user);
        app(SettingService::class)->set('general', 'company_name_ar', 'كليك للسياحة');
        app(SettingService::class)->set('general', 'company_name_en', 'Click Tourism');
    }

    private function receipt(int $amount = 62000, string $currency = 'EGP'): Payment
    {
        return app(PaymentService::class)->record([
            'branch_id' => $this->branch->public_id, 'customer_id' => $this->customer()->public_id,
            'cash_account_id' => $this->account('1110')->public_id, 'amount_minor' => $amount, 'currency' => $currency,
            'fx_rate' => $currency === 'EGP' ? '1' : '48.5', 'method' => 'cash', 'paid_at' => now()->toIso8601String(),
        ], $this->user->id);
    }

    private function pdf(Payment $payment, string $query = '')
    {
        return $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])->get('/api/v1/payments/'.$payment->public_id.'/pdf'.$query);
    }

    public function test_the_voucher_downloads_as_a_pdf(): void
    {
        $payment = $this->receipt();

        $response = $this->pdf($payment)->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('receipt-'.$payment->number.'.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertDatabaseHas('audit_events', ['action' => 'payment.exported']);
    }

    public function test_the_voucher_shows_who_paid_how_much_and_into_which_account(): void
    {
        $payment = $this->receipt(62000);
        $html = app(ReceiptDocument::class)->receiptHtml($payment, 'en');

        $this->assertStringContainsString($payment->number, $html);
        $this->assertStringContainsString('Receipt voucher', $html);
        $this->assertStringContainsString('Test Customer', $html);
        $this->assertStringContainsString('620.00', $html);
        $this->assertStringContainsString('Unallocated advance', $html);
        $this->assertStringContainsString('Click Tourism', $html);
        $this->assertStringNotContainsString('Cancelled receipt', $html);
    }

    public function test_the_arabic_labels_are_shaped_and_present(): void
    {
        $html = app(ReceiptDocument::class)->receiptHtml($this->receipt(), 'bilingual');

        $this->assertStringContainsString('class="ar"', $html);
        $this->assertStringContainsString('Receipt voucher', $html);
        $this->assertStringNotContainsString('سند قبض', $html, 'Arabic is stored in presentation form for the renderer, never as raw logical text');
    }

    public function test_a_receipt_applied_to_an_invoice_lists_the_invoice(): void
    {
        app(SettingService::class)->set('invoices', 'segregation', 'false');
        $service = app(InvoiceService::class);
        $invoice = $service->create([
            'type' => 'customer_invoice', 'party_type' => 'customer', 'party_id' => $this->customer()->public_id,
            'issue_date' => now()->toDateString(), 'currency' => 'EGP',
            'lines' => [['account_id' => $this->account('4100')->public_id, 'credit_minor' => 100000]],
        ], $this->branch, $this->user->id);
        $service->submit($invoice, $this->user->id);
        $service->approve($invoice, $this->user->id);
        $service->issue($invoice, $this->user->id);
        $payment = $this->receipt(40000);
        app(InvoiceSettlementService::class)->applyReceipt($payment, $invoice, 40000, $this->user->id);

        $html = app(ReceiptDocument::class)->receiptHtml($payment->fresh(), 'en');

        $this->assertStringContainsString($invoice->number, $html);
        $this->assertStringNotContainsString('Unallocated advance', $html);
    }

    public function test_a_foreign_receipt_shows_its_rate_and_base_equivalent(): void
    {
        $this->customer()->update(['currency_code' => 'USD']);
        $html = app(ReceiptDocument::class)->receiptHtml($this->receipt(10000, 'USD'), 'en');

        $this->assertStringContainsString('1 USD = 48.5 EGP', $html);
        $this->assertStringContainsString('4,850.00', $html);
    }

    public function test_a_cancelled_receipt_is_stamped_cancelled(): void
    {
        $payment = $this->receipt();
        app(PaymentService::class)->cancel($payment, 'Entered twice', $this->user->id);

        $html = app(ReceiptDocument::class)->receiptHtml($payment->fresh(), 'en');

        $this->assertStringContainsString('Cancelled receipt', $html);
        $this->pdf($payment->fresh())->assertOk();
    }

    public function test_it_needs_the_view_permission_and_the_receipts_branch(): void
    {
        $payment = $this->receipt();

        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);
        $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])->get('/api/v1/payments/'.$payment->public_id.'/pdf')->assertForbidden();

        $viewer->givePermissionTo('payments.view');
        $other = Branch::query()->create(['code' => 'ZZ', 'name_ar' => 'فرع', 'name_en' => 'Other', 'accounting_mode' => 'branch_local', 'is_active' => true]);
        $viewer->branches()->sync([$other->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withHeaders(['X-Branch-Id' => $other->public_id])->get('/api/v1/payments/'.$payment->public_id.'/pdf')->assertStatus(403);
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->pdf($this->receipt(), '?language=fr')->assertStatus(422);
    }
}
