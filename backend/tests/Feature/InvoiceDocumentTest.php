<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\AuditEvent;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceDocument;
use App\Modules\Invoices\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * Server-rendered invoice and credit-note documents (ADR-0014).
 *
 * The PDF binary is asserted by signature/headers; content is asserted on the
 * HTML the renderer feeds to Dompdf, which keeps the tests fast and readable.
 */
class InvoiceDocumentTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = ['invoices.view', 'invoices.create', 'invoices.approve'];

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

    private function headers(?Branch $branch = null): array
    {
        return ['X-Branch-Id' => ($branch ?? $this->branch)->public_id];
    }

    private function createCustomerInvoice(array $overrides = [], ?Branch $branch = null): Invoice
    {
        return app(InvoiceService::class)->create($overrides + [
            'type' => 'customer_invoice',
            'party_type' => 'customer',
            'party_id' => $this->customer()->public_id,
            'issue_date' => now()->toDateString(),
            'currency' => 'EGP',
            'memo' => 'Booking deposit',
            'lines' => [[
                'account_id' => $this->account('4100')->public_id,
                'description' => 'Tour package sale',
                'quantity' => 2,
                'unit_price_minor' => 50000,
                'credit_minor' => 100000,
                'tax_minor' => 15000,
            ]],
        ], $branch ?? $this->branch, $this->user->id);
    }

    private function issue(Invoice $invoice): Invoice
    {
        $service = app(InvoiceService::class);
        $service->submit($invoice, $this->user->id);
        $service->approve($invoice, $this->user->id);

        return $service->issue($invoice, $this->user->id);
    }

    private function download(string $invoicePublicId, string $query = ''): TestResponse
    {
        return $this->withHeaders($this->headers())
            ->get('/api/v1/invoices/'.$invoicePublicId.'/pdf'.$query);
    }

    public function test_customer_invoice_pdf_downloads_with_pdf_headers_and_is_audited(): void
    {
        $invoice = $this->createCustomerInvoice();
        $journalCount = JournalEntry::query()->count();

        $response = $this->download($invoice->public_id)->assertOk();

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('invoice-'.$invoice->number.'.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));

        // Rendering is read-only: no journal posted, status untouched.
        $this->assertSame($journalCount, JournalEntry::query()->count());
        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->fresh()->status);

        $event = AuditEvent::query()->where('action', 'invoice.exported')->firstOrFail();
        $this->assertSame($invoice->id, $event->entity_id);
        $this->assertSame($invoice->number, $event->after['number']);
        $this->assertSame('bilingual', $event->after['language']);
        $this->assertNull($event->after['total_minor'] ?? null);
    }

    public function test_bilingual_document_contains_letterhead_party_lines_totals_and_fx_free_labels(): void
    {
        $invoice = $this->createCustomerInvoice();

        $html = app(InvoiceDocument::class)->html($invoice, 'bilingual');

        $this->assertStringContainsString($invoice->number, $html);
        $this->assertStringContainsString('Sales invoice', $html);
        $this->assertStringContainsString('Test Customer', $html);
        $this->assertStringContainsString('Head Office', $html);
        $this->assertStringContainsString('Tour package sale', $html);
        $this->assertStringContainsString('1,000.00', $html);
        $this->assertStringContainsString('150.00', $html);
        $this->assertStringContainsString('1,150.00', $html);
        $this->assertStringContainsString('Amount due', $html);
        $this->assertStringContainsString('Booking deposit', $html);
        $this->assertStringNotContainsString('1 EGP =', $html);
    }

    public function test_supplier_bill_and_credit_note_render_distinct_documents(): void
    {
        $supplier = $this->supplier();
        $bill = app(InvoiceService::class)->create([
            'type' => 'supplier_invoice',
            'party_type' => 'supplier',
            'party_id' => $supplier->public_id,
            'issue_date' => now()->toDateString(),
            'currency' => 'EGP',
            'lines' => [[
                'account_id' => $this->account('5100')->public_id,
                'description' => 'Hotel cost',
                'quantity' => 1,
                'unit_price_minor' => 100000,
                'debit_minor' => 100000,
            ]],
        ], $this->branch, $this->user->id);

        $billHtml = app(InvoiceDocument::class)->html($bill, 'en');
        $this->assertStringContainsString('Purchase invoice', $billHtml);
        $this->assertStringContainsString('Test Supplier', $billHtml);
        $this->assertStringContainsString('Hotel cost', $billHtml);

        $issued = $this->issue($this->createCustomerInvoice());
        $credit = app(InvoiceService::class)->creditNote($issued, [], $this->user->id);

        $creditHtml = app(InvoiceDocument::class)->html($credit, 'en');
        $this->assertStringContainsString('Credit note', $creditHtml);
        $this->assertStringContainsString('Credit note for', $creditHtml);
        $this->assertStringContainsString($issued->number, $creditHtml);
        $this->assertStringContainsString('Credited amount', $creditHtml);
        $this->assertStringContainsString('Remaining credit', $creditHtml);
        $this->assertStringNotContainsString('Purchase invoice', $creditHtml);
    }

    public function test_unissued_voided_and_issued_documents_carry_the_right_status_band(): void
    {
        $draft = $this->createCustomerInvoice();
        $draftHtml = app(InvoiceDocument::class)->html($draft, 'en');
        $this->assertStringContainsString('Draft, not issued', $draftHtml);
        $this->assertStringContainsString('status-draft', $draftHtml);

        $voided = $this->createCustomerInvoice();
        app(InvoiceService::class)->void($voided, $this->user->id);
        $voidedHtml = app(InvoiceDocument::class)->html($voided->fresh(), 'en');
        $this->assertStringContainsString('Voided', $voidedHtml);
        $this->assertStringContainsString('do not pay', $voidedHtml);

        $issued = $this->issue($this->createCustomerInvoice());
        $issuedHtml = app(InvoiceDocument::class)->html($issued, 'en');
        $this->assertStringContainsString('Issued', $issuedHtml);
        $this->assertStringNotContainsString('not issued', $issuedHtml);
        $this->assertStringNotContainsString('do not pay', $issuedHtml);
    }

    public function test_pdf_requires_invoices_view_permission(): void
    {
        $invoice = $this->createCustomerInvoice();

        $viewer = User::factory()->create();
        $viewer->branches()->attach($this->branch->id);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($viewer);

        $this->download($invoice->public_id)->assertForbidden();
        $this->assertSame(0, AuditEvent::query()->where('action', 'invoice.exported')->count());
    }

    public function test_pdf_is_denied_for_an_invoice_outside_the_user_branch_scope(): void
    {
        $other = Branch::query()->create([
            'code' => 'ALX',
            'name_ar' => 'فرع الإسكندرية',
            'name_en' => 'Alexandria',
            'accounting_mode' => 'dimension',
            'is_active' => true,
        ]);
        $invoice = $this->createCustomerInvoice([], $other);

        $this->download($invoice->public_id)->assertForbidden();
    }

    public function test_language_is_validated_and_defaults_to_the_installation_setting(): void
    {
        $invoice = $this->createCustomerInvoice();

        $this->download($invoice->public_id, '?language=fr')->assertStatus(422)
            ->assertJsonValidationErrors('language');
        $this->assertSame(0, AuditEvent::query()->where('action', 'invoice.exported')->count());

        app(SettingService::class)->set('documents', 'default_language', 'en');
        $this->download($invoice->public_id)->assertOk();

        $event = AuditEvent::query()->where('action', 'invoice.exported')->firstOrFail();
        $this->assertSame('en', $event->after['language']);
    }

    public function test_arabic_only_document_contains_arabic_labels_and_no_english_column_header(): void
    {
        $invoice = $this->createCustomerInvoice();

        $html = app(InvoiceDocument::class)->html($invoice, 'ar');

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringNotContainsString('Sales invoice', $html);
        $this->assertStringNotContainsString('Amount due', $html);
    }

    public function test_foreign_currency_document_shows_the_fx_snapshot_and_rate_date(): void
    {
        $customer = $this->customer();
        $customer->update(['currency_code' => 'USD']);

        $invoice = $this->createCustomerInvoice([
            'currency' => 'USD',
            'fx_rate' => '50',
        ]);

        $html = app(InvoiceDocument::class)->html($invoice, 'en');
        $this->assertStringContainsString('1 USD = 50 EGP', $html);
        $this->assertStringContainsString(now()->toDateString(), $html);
        $this->assertStringContainsString('1,000.00', $html);
    }
}
