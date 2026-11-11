<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Money\Services\FxService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Tax\Models\TaxProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Optional, internally consistent demonstration dataset. Disabled unless
 * DEMO_SEED=true. Tickets are flight booking-item quantities; the application
 * intentionally has no separate ticket entity.
 *
 * Business documents are created through production services so numbering,
 * tax calculations, allocations and balanced journal postings are exercised.
 */
class DemoDataSeeder extends Seeder
{
    public function __construct(private readonly bool $force = false) {}

    public const EXPECTED_CUSTOMERS = 6;

    public const EXPECTED_SUPPLIERS = 5;

    public const EXPECTED_EMPLOYEES = 2;

    public const EXPECTED_BOOKINGS = 6;

    public const EXPECTED_BOOKING_ITEMS = 10;

    public const EXPECTED_TICKETS = 11;

    public const EXPECTED_INVOICES = 4;

    public const EXPECTED_PAYMENTS = 3;

    public const EXPECTED_LEADS = 3;

    public const EXPECTED_BOOKING_SALES_MINOR = 8_696_800;

    public const EXPECTED_BOOKING_COST_MINOR = 5_660_000;

    public const EXPECTED_INVOICE_TOTAL_MINOR = 1_241_000;

    public const EXPECTED_COLLECTIONS_MINOR = 1_957_400;

    public function run(): void
    {
        if (! $this->force && ! filter_var(env('DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $branch = Branch::query()->where('code', 'HQ')->first();
        if (! $branch || Booking::query()->where('branch_id', $branch->id)->exists()) {
            return;
        }

        // The demo data exercises VAT, so seeding it enables the tax setting.
        app(SettingService::class)->set('tax', 'enabled', 'true');

        // Only the isolated, disposable demo installation can opt into the
        // provisional accounting policy needed to generate realistic samples.
        // Never approve accounting policies implicitly for real installations.
        if (filter_var(env('DEMO_MODE', false), FILTER_VALIDATE_BOOL)
            && env('APP_ENV') === 'demo'
            && env('DB_HOST') === 'demo-db'
            && env('DB_DATABASE') === 'demo_erp') {
            $settings = app(SettingService::class);
            $settings->set('accounting', 'accounting_policy_approved', 'true');
            $settings->set('accounting', 'auto_post_bookings', 'false');
        }

        $this->seedFxRates();

        $owner = User::query()->where('email', 'accountant@example.com')->first();
        // Work order 02: invoice approval is maker-checker by default, so the
        // demo data needs a second person to approve what the accountant
        // submits. Falls back to the owner only when no second user exists.
        $approver = User::query()->where('email', 'admin@example.com')->first() ?? $owner;
        $taxProfile = TaxProfile::query()->where('name', 'VAT 14% (Egypt)')->first();
        $customers = $this->seedCustomers($branch);
        $suppliers = $this->seedSuppliers($branch);
        $this->seedEmployees($branch);
        $bookings = $this->seedBookings($branch, $owner, $taxProfile, $customers, $suppliers);
        $this->seedPayments($branch, $owner, $customers, $bookings);
        $this->seedInvoices($branch, $owner, $approver, $customers, $suppliers, $bookings);
        $this->seedLeads($branch, $owner);
    }

    /** @return array<int, Customer> */
    private function seedCustomers(Branch $branch): array
    {
        $definitions = [
            ['name_ar' => 'أحمد محمود', 'name_en' => 'Ahmed Mahmoud', 'phone' => '01000000001', 'email' => 'ahmed@example.com', 'type' => 'individual'],
            ['name_ar' => 'شركة النور للسياحة', 'name_en' => 'Al Nour Travel Co.', 'phone' => '01000000002', 'email' => 'info@alnour.example', 'type' => 'company'],
            ['name_ar' => 'سارة علي', 'name_en' => 'Sara Ali', 'phone' => '01000000003', 'email' => 'sara@example.com', 'type' => 'individual'],
            ['name_ar' => 'عمر حسن', 'name_en' => 'Omar Hassan', 'phone' => '01000000004', 'email' => 'omar@example.com', 'type' => 'individual'],
            ['name_ar' => 'بلو سكاي للسفر', 'name_en' => 'Blue Sky Travel', 'phone' => '01000000005', 'email' => 'ops@bluesky.example', 'type' => 'company'],
            ['name_ar' => 'مريم عادل', 'name_en' => 'Mariam Adel', 'phone' => '01000000006', 'email' => 'mariam@example.com', 'type' => 'individual'],
        ];

        return array_map(fn (array $data) => Customer::query()->firstOrCreate(
            ['phone' => $data['phone']],
            $data + [
                'branch_id' => $branch->id,
                'currency_code' => 'EGP',
                'credit_limit_minor' => 0,
                'is_active' => true,
            ],
        ), $definitions);
    }

    /** @return array<int, Supplier> */
    private function seedSuppliers(Branch $branch): array
    {
        $definitions = [
            ['category' => 'hotel_eg', 'name_ar' => 'فندق النيل', 'name_en' => 'Nile Hotel', 'phone' => '0200000001'],
            ['category' => 'airline', 'name_ar' => 'مصر للطيران', 'name_en' => 'EgyptAir', 'phone' => '0200000002'],
            ['category' => 'transport', 'name_ar' => 'شركة الأمانة للنقل', 'name_en' => 'Amana Transport', 'phone' => '0200000003'],
            ['category' => 'visa', 'name_ar' => 'بوابة التأشيرات', 'name_en' => 'Visa Gateway', 'phone' => '0200000004'],
            ['category' => 'agent', 'name_ar' => 'وكالة الأفق', 'name_en' => 'Horizon Agency', 'phone' => '0200000005'],
        ];

        return array_map(fn (array $data) => Supplier::query()->firstOrCreate(
            ['phone' => $data['phone']],
            $data + [
                'branch_id' => $branch->id,
                'settlement_currency' => 'EGP',
                'is_active' => true,
            ],
        ), $definitions);
    }

    private function seedEmployees(Branch $branch): void
    {
        $definitions = [
            ['internal_code' => 'EMP-001', 'name_ar' => 'خالد سمير', 'name_en' => 'Khaled Samir', 'category' => 'staff', 'phone' => '01000000101', 'email' => 'khaled@example.com'],
            ['internal_code' => 'EMP-002', 'name_ar' => 'منى إبراهيم', 'name_en' => 'Mona Ibrahim', 'category' => 'executive', 'phone' => '01000000102', 'email' => 'mona@example.com'],
        ];

        foreach ($definitions as $data) {
            Employee::query()->firstOrCreate(
                ['internal_code' => $data['internal_code']],
                $data + ['branch_id' => $branch->id, 'is_active' => true],
            );
        }
    }

    /**
     * @param  array<int, Customer>  $customers
     * @param  array<int, Supplier>  $suppliers
     * @return array<int, Booking>
     */
    private function seedBookings(Branch $branch, ?User $owner, ?TaxProfile $taxProfile, array $customers, array $suppliers): array
    {
        $service = app(BookingService::class);
        $today = Carbon::today();
        $definitions = [
            [
                'customer' => 0, 'start' => $today->copy()->addDays(10), 'end' => $today->copy()->addDays(17),
                'adults' => 2, 'children' => 1, 'status' => ['confirmed'],
                'notes' => 'Cairo and Nile cruise package.',
                'items' => [
                    $this->item('hotel', 0, 'Nile Hotel - 7 nights, double room', 7, 180_000, 260_000, $taxProfile),
                    $this->item('transport', 2, 'Airport transfers and daily transport', 1, 150_000, 240_000),
                ],
            ],
            [
                'customer' => 1, 'start' => $today->copy()->addDays(25), 'end' => $today->copy()->addDays(30),
                'adults' => 4, 'status' => ['quoted'], 'notes' => 'Corporate group quotation.',
                'items' => [$this->item('flight', 1, 'Return flights Cairo - Sharm El Sheikh', 4, 320_000, 450_000, $taxProfile)],
            ],
            [
                'customer' => 2, 'start' => $today->copy()->subDays(7), 'end' => $today->copy()->addDays(1),
                'adults' => 2, 'status' => ['confirmed'], 'notes' => 'Alexandria flight and hotel package.',
                'items' => [
                    $this->item('flight', 1, 'Return flight tickets', 2, 250_000, 350_000),
                    $this->item('hotel', 0, 'Three hotel nights', 3, 130_000, 200_000),
                ],
            ],
            [
                'customer' => 3, 'start' => $today->copy()->addDays(5), 'end' => $today->copy()->addDays(12),
                'adults' => 2, 'status' => ['confirmed', 'in_progress'], 'notes' => 'Visa and flight package.',
                'items' => [
                    $this->item('visa', 3, 'Tourist visas', 2, 100_000, 150_000),
                    $this->item('flight', 1, 'International flight tickets', 2, 280_000, 390_000),
                ],
            ],
            [
                'customer' => 4, 'start' => $today->copy()->addDays(40), 'end' => $today->copy()->addDays(45),
                'adults' => 6, 'status' => [], 'notes' => 'Draft corporate program.',
                'items' => [$this->item('program', 4, 'Five-day corporate program', 1, 600_000, 850_000)],
            ],
            [
                'customer' => 5, 'start' => $today->copy()->subDays(20), 'end' => $today->copy()->subDays(14),
                'adults' => 3, 'status' => ['confirmed', 'in_progress', 'completed', 'closed'], 'notes' => 'Completed Red Sea package.',
                'items' => [
                    $this->item('flight', 1, 'Domestic flight tickets', 3, 200_000, 300_000),
                    $this->item('transport', 2, 'Resort transfers', 1, 120_000, 200_000),
                ],
            ],
        ];

        $result = [];
        foreach ($definitions as $definition) {
            $booking = $service->create([
                'branch_id' => $branch->public_id,
                'customer_id' => $customers[$definition['customer']]->public_id,
                'owner_user_id' => $owner?->public_id,
                'start_date' => $definition['start']->toDateString(),
                'end_date' => $definition['end']->toDateString(),
                'pax_adults' => $definition['adults'],
                'pax_children' => $definition['children'] ?? 0,
                'currency' => 'EGP',
                'fx_rate' => '1',
                'notes' => $definition['notes'],
                'items' => array_map(function (array $item) use ($suppliers) {
                    $supplier = $item['supplier'];
                    unset($item['supplier']);
                    $item['supplier_id'] = $suppliers[$supplier]->public_id;

                    return $item;
                }, $definition['items']),
            ], $owner?->id);

            foreach ($definition['status'] as $status) {
                $booking = $service->transition($booking, $status, 'Demo lifecycle', $owner?->id);
            }
            $result[] = $booking->refresh();
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function item(string $type, int $supplier, string $description, int $quantity, int $cost, int $sell, ?TaxProfile $taxProfile = null): array
    {
        return array_filter([
            'service_type' => $type,
            'supplier' => $supplier,
            'description' => $description,
            'quantity' => $quantity,
            'unit_cost_minor' => $cost,
            'unit_sell_minor' => $sell,
            'tax_profile_id' => $taxProfile?->id,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<int, Customer>  $customers
     * @param  array<int, Booking>  $bookings
     */
    private function seedPayments(Branch $branch, ?User $owner, array $customers, array $bookings): void
    {
        $cash = Account::query()->where('code', '1110')->firstOrFail();
        $service = app(PaymentService::class);
        $definitions = [
            ['customer' => 0, 'booking' => 0, 'amount' => 1_157_400, 'method' => 'cash'],
            ['customer' => 2, 'booking' => 2, 'amount' => 500_000, 'method' => 'bank_transfer'],
            ['customer' => 3, 'booking' => 3, 'amount' => 300_000, 'method' => 'card'],
        ];

        foreach ($definitions as $definition) {
            $service->record([
                'branch_id' => $branch->public_id,
                'customer_id' => $customers[$definition['customer']]->public_id,
                'booking_id' => $bookings[$definition['booking']]->public_id,
                'cash_account_id' => $cash->public_id,
                'method' => $definition['method'],
                'amount_minor' => $definition['amount'],
                'currency' => 'EGP',
                'fx_rate' => '1',
                'paid_at' => now()->toIso8601String(),
            ], $owner?->id);
        }
    }

    /**
     * @param  array<int, Customer>  $customers
     * @param  array<int, Supplier>  $suppliers
     * @param  array<int, Booking>  $bookings
     */
    private function seedInvoices(Branch $branch, ?User $owner, ?User $approver, array $customers, array $suppliers, array $bookings): void
    {
        $service = app(InvoiceService::class);
        $today = today()->toDateString();

        $issuedCustomer = $service->create([
            'type' => Invoice::TYPE_CUSTOMER_INVOICE,
            'party_type' => 'customer',
            'party_id' => $customers[4]->public_id,
            'booking_id' => $bookings[4]->id,
            'issue_date' => $today,
            'due_date' => today()->addDays(14)->toDateString(),
            'currency' => 'EGP',
            'memo' => 'Corporate program deposit invoice.',
            'lines' => [[
                'account_id' => '4100', 'description' => 'Corporate travel services',
                'credit_minor' => 250_000, 'tax_minor' => 35_000, 'tax_account_id' => '2200',
            ]],
        ], $branch, $owner?->id);
        $service->submit($issuedCustomer, $owner?->id);
        $service->approve($issuedCustomer, $approver?->id);
        $service->issue($issuedCustomer, $owner?->id);

        $service->create([
            'type' => Invoice::TYPE_CUSTOMER_INVOICE,
            'party_type' => 'customer',
            'party_id' => $customers[1]->public_id,
            'booking_id' => $bookings[1]->id,
            'issue_date' => $today,
            'due_date' => today()->addDays(21)->toDateString(),
            'currency' => 'EGP',
            'memo' => 'Draft corporate quotation invoice.',
            'lines' => [[
                'account_id' => '4100', 'description' => 'Flight reservation services', 'credit_minor' => 180_000,
            ]],
        ], $branch, $owner?->id);

        $issuedSupplier = $service->create([
            'type' => Invoice::TYPE_SUPPLIER_INVOICE,
            'party_type' => 'supplier',
            'party_id' => $suppliers[4]->public_id,
            'issue_date' => $today,
            'due_date' => today()->addDays(30)->toDateString(),
            'currency' => 'EGP',
            'memo' => 'Ground handling supplier invoice.',
            'lines' => [[
                'account_id' => '5100', 'description' => 'Ground handling services',
                'debit_minor' => 400_000, 'tax_minor' => 56_000, 'tax_account_id' => '1160',
            ]],
        ], $branch, $owner?->id);
        $service->submit($issuedSupplier, $owner?->id);
        $service->approve($issuedSupplier, $approver?->id);
        $service->issue($issuedSupplier, $owner?->id);

        $submitted = $service->create([
            'type' => Invoice::TYPE_CUSTOMER_INVOICE,
            'party_type' => 'customer',
            'party_id' => $customers[2]->public_id,
            'booking_id' => $bookings[2]->id,
            'issue_date' => $today,
            'due_date' => today()->addDays(14)->toDateString(),
            'currency' => 'EGP',
            'memo' => 'Submitted Alexandria package invoice.',
            'lines' => [[
                'account_id' => '4100', 'description' => 'Package services', 'credit_minor' => 320_000,
            ]],
        ], $branch, $owner?->id);
        $service->submit($submitted, $owner?->id);
    }

    private function seedLeads(Branch $branch, ?User $owner): void
    {
        $service = app(LeadService::class);
        foreach ([
            ['name' => 'مصطفى كامل', 'phone' => '01000000010', 'email' => 'mostafa@example.com', 'source' => 'facebook', 'notes' => 'Asked about a five-day Luxor program.'],
            ['name' => 'Hana Youssef', 'phone' => '01000000011', 'source' => 'whatsapp', 'notes' => 'Wants a family package in December.'],
            ['name' => 'شركة المستقبل', 'phone' => '01000000012', 'email' => 'travel@future.example', 'source' => 'manual', 'notes' => 'Corporate travel account inquiry.'],
        ] as $lead) {
            $service->create($lead + [
                'branch_id' => $branch->public_id,
                'consent' => true,
            ], $owner?->id);
        }
    }

    private function seedFxRates(): void
    {
        $fx = app(FxService::class);
        $date = Carbon::now()->startOfMonth()->toDateString();

        foreach (['USD' => '48.5000000000', 'SAR' => '12.9000000000', 'EUR' => '52.3000000000', 'AED' => '13.2000000000'] as $currency => $rate) {
            $fx->upsertRate($currency, $rate, $date);
        }
    }
}
