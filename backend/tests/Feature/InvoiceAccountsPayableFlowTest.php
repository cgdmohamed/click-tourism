<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Parties\Models\PartyAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * Prompt 26 acceptance scenario: a booking with three services supplied by two
 * suppliers produces one customer invoice and two supplier invoices, and every
 * posting balances while landing on the right party subaccount.
 */
class InvoiceAccountsPayableFlowTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        // This scenario proves the invoicing path itself; booking-time
        // automatic posting is covered by BookingServiceTest.
        app(SettingService::class)->set('accounting', 'auto_post_bookings', 'false');

        foreach (['invoices.view', 'invoices.create', 'invoices.approve'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(['invoices.view', 'invoices.create', 'invoices.approve']);

        Sanctum::actingAs($this->user);
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

    private function issue(Invoice $invoice): Invoice
    {
        $service = app(InvoiceService::class);
        $service->submit($invoice, $this->user->id);
        $service->approve($invoice, $this->user->id);

        return $service->issue($invoice, $this->user->id);
    }

    public function test_booking_with_three_services_and_two_suppliers_posts_one_customer_and_two_supplier_invoices(): void
    {
        $airlineHotelier = $this->supplier();
        $airlineHotelier->update(['name_en' => 'Air and Hotel Supplier']);
        $transport = $this->supplier();
        $transport->update(['name_en' => 'Transport Supplier']);

        $booking = app(BookingService::class)->create([
            'customer_id' => $this->customer()->public_id,
            'branch_id' => $this->branch->public_id,
            'start_date' => now()->toDateString(),
            'currency' => 'EGP',
            'items' => [
                [
                    'service_type' => 'air', 'description' => 'Flight ticket',
                    'quantity' => 1, 'unit_cost_minor' => 10000, 'unit_sell_minor' => 30000,
                    'cost_currency' => 'EGP', 'sell_currency' => 'EGP',
                    'supplier_id' => $airlineHotelier->public_id,
                ],
                [
                    'service_type' => 'hotel', 'description' => 'Hotel nights',
                    'quantity' => 2, 'unit_cost_minor' => 5000, 'unit_sell_minor' => 20000,
                    'cost_currency' => 'EGP', 'sell_currency' => 'EGP',
                    'supplier_id' => $airlineHotelier->public_id,
                ],
                [
                    'service_type' => 'transport', 'description' => 'Airport transfer',
                    'quantity' => 1, 'unit_cost_minor' => 15000, 'unit_sell_minor' => 50000,
                    'cost_currency' => 'EGP', 'sell_currency' => 'EGP',
                    'supplier_id' => $transport->public_id,
                ],
            ],
        ], $this->user->id);

        $this->assertSame(120000, (int) $booking->total_sell_minor);
        $this->assertSame(35000, (int) $booking->total_cost_minor);

        $invoices = app(InvoiceService::class);

        // One customer invoice for the full booking sell value.
        $sales = $invoices->createFromBooking($booking, $this->user->id);
        $this->assertSame(Invoice::TYPE_CUSTOMER_INVOICE, $sales->type);
        $this->assertCount(3, $sales->lines);
        $sales = $this->issue($sales);

        // Two supplier bills, grouped by supplier, for the booking cost.
        $billA = $invoices->create([
            'type' => 'supplier_invoice',
            'party_type' => 'supplier',
            'party_id' => $airlineHotelier->public_id,
            'issue_date' => now()->toDateString(),
            'currency' => 'EGP',
            'memo' => 'Booking '.$booking->number,
            'lines' => [
                [
                    'account_id' => $this->account('5100')->public_id,
                    'description' => 'Flight ticket',
                    'quantity' => 1, 'unit_price_minor' => 10000, 'debit_minor' => 10000,
                ],
                [
                    'account_id' => $this->account('5100')->public_id,
                    'description' => 'Hotel nights',
                    'quantity' => 2, 'unit_price_minor' => 5000, 'debit_minor' => 10000,
                ],
            ],
        ], $this->branch, $this->user->id);
        $billA = $this->issue($billA);

        $billB = $invoices->create([
            'type' => 'supplier_invoice',
            'party_type' => 'supplier',
            'party_id' => $transport->public_id,
            'issue_date' => now()->toDateString(),
            'currency' => 'EGP',
            'memo' => 'Booking '.$booking->number,
            'lines' => [[
                'account_id' => $this->account('5100')->public_id,
                'description' => 'Airport transfer',
                'quantity' => 1, 'unit_price_minor' => 15000, 'debit_minor' => 15000,
            ]],
        ], $this->branch, $this->user->id);
        $billB = $this->issue($billB);

        // Exactly one AR document and two AP documents, all issued and open.
        $this->assertSame(1, Invoice::query()->where('type', Invoice::TYPE_CUSTOMER_INVOICE)->count());
        $this->assertSame(2, Invoice::query()->where('type', Invoice::TYPE_SUPPLIER_INVOICE)->count());
        $this->assertSame(120000, (int) $sales->fresh()->outstanding_minor);
        $this->assertSame(20000, (int) $billA->fresh()->outstanding_minor);
        $this->assertSame(15000, (int) $billB->fresh()->outstanding_minor);

        // Party subaccounts: customer receivable +, supplier payables -.
        $receivable = PartyAccount::query()->where('party_type', 'customer')
            ->where('party_id', $this->customer()->id)->where('role', PartyAccount::ROLE_RECEIVABLE)
            ->firstOrFail()->account;
        $payableA = PartyAccount::query()->where('party_type', 'supplier')
            ->where('party_id', $airlineHotelier->id)->where('role', PartyAccount::ROLE_PAYABLE)
            ->firstOrFail()->account;
        $payableB = PartyAccount::query()->where('party_type', 'supplier')
            ->where('party_id', $transport->id)->where('role', PartyAccount::ROLE_PAYABLE)
            ->firstOrFail()->account;

        $this->assertSame(120000, $this->balance($receivable->code));
        $this->assertSame(-20000, $this->balance($payableA->code));
        $this->assertSame(-15000, $this->balance($payableB->code));

        // Control accounts roll the subaccounts up and match the documents.
        $this->assertSame(120000, $this->balance('1100'));
        $this->assertSame(-35000, $this->balance('2100'));

        // Revenue and cost land once each, exactly as invoiced.
        $this->assertSame(-120000, $this->balance('4100'));
        $this->assertSame(35000, $this->balance('5100'));

        // Every invoice journal is balanced and traceable to the document.
        $entries = JournalEntry::query()->where('source_type', 'invoice')->get();
        $this->assertCount(3, $entries);
        foreach ($entries as $entry) {
            $this->assertSame(
                (int) $entry->lines()->sum('base_debit_minor'),
                (int) $entry->lines()->sum('base_credit_minor'),
                "Journal {$entry->number} is not balanced.",
            );
        }
        $this->assertSame($sales->journal_entry_id, (int) $sales->fresh()->journal_entry_id);
        $this->assertNotNull($billA->fresh()->journal_entry_id);
        $this->assertNotNull($billB->fresh()->journal_entry_id);
    }
}
