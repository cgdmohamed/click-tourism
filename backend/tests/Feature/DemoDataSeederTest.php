<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Models\BookingItem;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Sequence;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Core\Services\SettingService;
use App\Modules\CRM\Models\Lead;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Payments\Models\Payment;
use App\Modules\Reports\Services\ReportService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Permission::query()->firstOrCreate(
            ['name' => 'accounts.post', 'guard_name' => 'web']
        );
        Permission::query()->firstOrCreate(['name' => 'journal.reverse', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $owner = User::query()->where('email', 'sales@example.com')->first();
        if ($owner) {
            $owner->givePermissionTo(['accounts.post']);
        }
        // Demo data creates both bookings and invoices. Disable booking
        // auto-posting so revenue is recognized only through invoices,
        // avoiding double revenue recognition.
        app(SettingService::class)->set('accounting', 'accounting_policy_approved', 'true');
        app(SettingService::class)->set('accounting', 'auto_post_bookings', 'false');
        (new DemoDataSeeder(force: true))->run();
    }

    public function test_demo_counts_totals_and_document_numbers_are_consistent(): void
    {
        $branch = Branch::query()->where('code', 'HQ')->firstOrFail();

        $this->assertSame(DemoDataSeeder::EXPECTED_CUSTOMERS, Customer::query()->where('branch_id', $branch->id)->count());
        $this->assertSame(DemoDataSeeder::EXPECTED_SUPPLIERS, Supplier::query()->where('branch_id', $branch->id)->count());
        $this->assertSame(DemoDataSeeder::EXPECTED_EMPLOYEES, Employee::query()->where('branch_id', $branch->id)->count());
        $this->assertSame(DemoDataSeeder::EXPECTED_BOOKINGS, Booking::query()->where('branch_id', $branch->id)->count());
        $this->assertSame(DemoDataSeeder::EXPECTED_BOOKING_ITEMS, BookingItem::query()->count());
        $this->assertSame(DemoDataSeeder::EXPECTED_INVOICES, Invoice::query()->where('branch_id', $branch->id)->count());
        $this->assertSame(DemoDataSeeder::EXPECTED_PAYMENTS, Payment::query()->where('branch_id', $branch->id)->count());
        $this->assertSame(DemoDataSeeder::EXPECTED_LEADS, Lead::query()->where('branch_id', $branch->id)->count());

        $tickets = (int) BookingItem::query()->where('service_type', 'flight')->sum('quantity');
        $this->assertSame(DemoDataSeeder::EXPECTED_TICKETS, $tickets);
        $this->assertSame(DemoDataSeeder::EXPECTED_BOOKING_SALES_MINOR, (int) Booking::query()->sum('total_sell_minor'));
        $this->assertSame(DemoDataSeeder::EXPECTED_BOOKING_COST_MINOR, (int) Booking::query()->sum('total_cost_minor'));
        $this->assertSame(DemoDataSeeder::EXPECTED_INVOICE_TOTAL_MINOR, (int) Invoice::query()->sum('total_minor'));
        $this->assertSame(DemoDataSeeder::EXPECTED_COLLECTIONS_MINOR, (int) Payment::query()->sum('amount_minor'));

        $this->assertSame([
            'BK-HQ-'.now()->year.'-000001', 'BK-HQ-'.now()->year.'-000002', 'BK-HQ-'.now()->year.'-000003',
            'BK-HQ-'.now()->year.'-000004', 'BK-HQ-'.now()->year.'-000005', 'BK-HQ-'.now()->year.'-000006',
        ], Booking::query()->orderBy('id')->pluck('number')->all());
        $this->assertSame([
            'RV-HQ-'.now()->year.'-000001', 'RV-HQ-'.now()->year.'-000002', 'RV-HQ-'.now()->year.'-000003',
        ], Payment::query()->orderBy('id')->pluck('number')->all());
        $this->assertSame([
            'INV-HQ-'.now()->year.'-000001',
            'INV-HQ-'.now()->year.'-000002',
            'BIL-HQ-'.now()->year.'-000001',
            'INV-HQ-'.now()->year.'-000003',
        ], Invoice::query()->orderBy('id')->pluck('number')->all());

        $this->assertSame(7, $this->nextValue('booking', $branch));
        $this->assertSame(4, $this->nextValue('payment', $branch));
        $this->assertSame(4, $this->nextValue('invoice.INV', $branch));
        $this->assertSame(2, $this->nextValue('invoice.BIL', $branch));

        JournalEntry::query()->where('status', 'posted')->with('lines')->get()->each(function (JournalEntry $entry) {
            $this->assertSame(
                (int) $entry->lines->sum('base_debit_minor'),
                (int) $entry->lines->sum('base_credit_minor'),
                "Journal {$entry->number} is not balanced.",
            );
        });
    }

    public function test_dashboard_counts_match_the_seeded_operational_data(): void
    {
        $branch = Branch::query()->where('code', 'HQ')->firstOrFail();
        $dashboard = app(ReportService::class)->dashboard($branch);

        $this->assertSame(DemoDataSeeder::EXPECTED_CUSTOMERS, $dashboard['customers_count']);
        $this->assertSame(DemoDataSeeder::EXPECTED_BOOKINGS, $dashboard['bookings_count']);
        $this->assertSame(DemoDataSeeder::EXPECTED_INVOICES, $dashboard['invoices_count']);
        $this->assertSame(DemoDataSeeder::EXPECTED_TICKETS, $dashboard['tickets_count']);
        $this->assertSame(5, $dashboard['open_bookings']);
        $this->assertSame(3, $dashboard['confirmed_bookings']);
        $this->assertSame(DemoDataSeeder::EXPECTED_COLLECTIONS_MINOR, $dashboard['collected_minor']);
        $this->assertSame(800_000, $dashboard['overdue_minor']);
    }

    public function test_legacy_invoice_sequence_prefix_is_healed_without_resetting_its_number(): void
    {
        $branch = Branch::query()->where('code', 'HQ')->firstOrFail();
        Sequence::query()->create([
            'document_type' => 'invoice.CN',
            'branch_id' => $branch->id,
            'year' => now()->year,
            'prefix' => 'IN',
            'next_value' => 5,
            'gap_policy' => 'allow',
        ]);

        $number = app(SequenceService::class)->next('invoice.CN', $branch);

        $this->assertSame('CN-HQ-'.now()->year.'-000005', $number);
        $this->assertSame(6, $this->nextValue('invoice.CN', $branch));
    }

    private function nextValue(string $documentType, Branch $branch): int
    {
        return (int) Sequence::query()
            ->where('document_type', $documentType)
            ->where('branch_id', $branch->id)
            ->where('year', now()->year)
            ->value('next_value');
    }
}
