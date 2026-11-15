<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\FixedAssetService;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Models\TourDeparture;
use App\Modules\Bookings\Models\TourPackage;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Employee;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Parties\Services\EmployeeFinanceService;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\SupplierSettlementService;
use App\Modules\Treasury\Models\CashAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A complete-looking company for the public demo: several bank and wallet accounts, Umrah, Hajj, domestic and
 * international packages with departures, a few months of bookings, invoices, receipts, supplier bills and
 * payments, expenses in every state, and a lead pipeline, so every screen and report has something to show.
 *
 * Everything goes through the same services as the application, so each entry is balanced and traceable.
 * It runs after DemoDataSeeder, is idempotent (skips when packages already exist) and only in the demo. A step
 * that fails is logged and skipped, so one bad row never stops the demo from booting.
 */
class DemoShowcaseSeeder extends Seeder
{
    public function __construct(private readonly bool $force = false) {}

    /** @var list<string> steps that failed on the last run (read by tests) */
    public array $failed = [];

    private Branch $branch;

    private ?User $owner = null;

    private ?User $approver = null;

    public function run(): void
    {
        if (! $this->force && ! filter_var(env('DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
            return;
        }
        $branch = Branch::query()->where('code', 'HQ')->first();
        if (! $branch || TourPackage::query()->exists()) {
            return;
        }
        $this->branch = $branch;
        $this->owner = User::query()->where('email', 'sales@example.com')->first() ?? User::query()->where('email', 'accountant@example.com')->first();
        $accountant = User::query()->where('email', 'accountant@example.com')->first() ?? $this->owner;
        $this->approver = User::query()->where('email', 'admin@example.com')->first() ?? $accountant;
        $this->owner = $accountant;

        $this->step('treasury', fn () => $this->treasury());
        $suppliers = $this->step('suppliers', fn () => $this->suppliers()) ?? [];
        $customers = $this->step('customers', fn () => $this->customers()) ?? [];
        $departures = $this->step('packages', fn () => $this->packages()) ?? [];
        $bookings = $this->step('bookings', fn () => $this->bookings($customers, $suppliers, $departures)) ?? [];
        $this->step('invoices', fn () => $this->invoices($bookings));
        $this->step('receipts', fn () => $this->receipts($bookings));
        $this->step('supplier-bills', fn () => $this->supplierBills($suppliers));
        $this->step('supplier-payments', fn () => $this->supplierPayments($suppliers));
        $this->step('expenses', fn () => $this->expenses($suppliers));
        $this->step('employees', fn () => $this->employees());
        $this->step('fixed-assets', fn () => $this->fixedAssets());
        $this->step('employee-advances', fn () => $this->advances());
        $this->step('leads', fn () => $this->leads());
    }

    /**
     * The day that many days ago, never before the first day of this year: the install opens the current
     * year's fiscal periods only, so a posting dated in the previous year (early January) would be refused.
     */
    private function ago(int $days): Carbon
    {
        $day = Carbon::now()->subDays($days);
        $first = Carbon::now()->startOfYear();

        return $day->lessThan($first) ? $first->copy()->addHours(9) : $day;
    }

    /** @template T @param  callable(): T  $do @return T|null */
    private function step(string $name, callable $do): mixed
    {
        try {
            return $do();
        } catch (Throwable $e) {
            $this->failed[] = $name.': '.$e->getMessage();
            Log::warning('demo_showcase.step_failed', ['step' => $name, 'exception' => $e::class]);

            return null;
        }
    }

    // ---- treasury -----------------------------------------------------------------------------

    private function treasury(): void
    {
        $accounts = [
            ['code' => '1121', 'name_ar' => 'بنك مصر - حساب جاري', 'name_en' => 'Banque Misr - current account', 'kind' => 'bank', 'bank' => 'Banque Misr', 'last4' => '4521', 'currency' => null],
            ['code' => '1122', 'name_ar' => 'البنك الأهلي المصري', 'name_en' => 'National Bank of Egypt', 'kind' => 'bank', 'bank' => 'National Bank of Egypt', 'last4' => '7730', 'currency' => null],
            ['code' => '1123', 'name_ar' => 'البنك التجاري الدولي - دولار', 'name_en' => 'CIB - USD account', 'kind' => 'bank', 'bank' => 'CIB', 'last4' => '9012', 'currency' => 'USD'],
            ['code' => '1124', 'name_ar' => 'محفظة فودافون كاش', 'name_en' => 'Vodafone Cash wallet', 'kind' => 'bank', 'bank' => 'Vodafone Cash', 'last4' => '3388', 'currency' => null],
            ['code' => '1112', 'name_ar' => 'عهدة الرحلات الميدانية', 'name_en' => 'Field trips petty cash', 'kind' => 'cash', 'bank' => null, 'last4' => null, 'currency' => null],
        ];
        foreach ($accounts as $row) {
            $account = Account::query()->updateOrCreate(['code' => $row['code']], [
                'name_ar' => $row['name_ar'], 'name_en' => $row['name_en'], 'type' => 'asset',
                'classification' => Account::defaultClassification('asset', $row['code']),
                'is_postable' => true, 'is_active' => true, 'currency_code' => $row['currency'],
            ]);
            CashAccount::query()->firstOrCreate(['account_id' => $account->id], [
                'branch_id' => $this->branch->id, 'kind' => $row['kind'], 'name_ar' => $row['name_ar'], 'name_en' => $row['name_en'],
                'currency_code' => $row['currency'] ?? 'EGP', 'bank_name' => $row['bank'], 'account_last4' => $row['last4'], 'is_active' => true,
            ]);
        }
        // The two seeded accounts get bank details too.
        CashAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1120'))->update(['bank_name' => 'Banque du Caire', 'account_last4' => '1105']);

        $methods = [
            ['code' => 'CASH', 'name_ar' => 'نقداً', 'name_en' => 'Cash', 'type' => 'cash', 'account' => '1110'],
            ['code' => 'BANK-TRANSFER', 'name_ar' => 'تحويل بنكي', 'name_en' => 'Bank transfer', 'type' => 'bank_transfer', 'account' => '1120'],
            ['code' => 'CARD', 'name_ar' => 'بطاقة ائتمان', 'name_en' => 'Card', 'type' => 'card', 'account' => '1121'],
            ['code' => 'INSTAPAY', 'name_ar' => 'إنستا باي', 'name_en' => 'InstaPay', 'type' => 'bank_transfer', 'account' => '1122'],
            ['code' => 'WALLET', 'name_ar' => 'محفظة إلكترونية', 'name_en' => 'Mobile wallet', 'type' => 'wallet', 'account' => '1124'],
        ];
        foreach ($methods as $row) {
            PaymentMethod::query()->firstOrCreate(['code' => $row['code']], [
                'name_ar' => $row['name_ar'], 'name_en' => $row['name_en'], 'type' => $row['type'],
                'default_cash_account_id' => Account::query()->where('code', $row['account'])->value('id'), 'is_active' => true,
            ]);
        }
    }

    // ---- parties ------------------------------------------------------------------------------

    /** @return array<string, Supplier> keyed by a short handle */
    private function suppliers(): array
    {
        $rows = [
            'saudia' => ['category' => 'airline', 'name_ar' => 'الخطوط الجوية السعودية', 'name_en' => 'Saudia Airlines', 'phone' => '02000000101'],
            'egyptair' => ['category' => 'airline', 'name_ar' => 'مصر للطيران', 'name_en' => 'EgyptAir', 'phone' => '02000000102'],
            'makkah_hotel' => ['category' => 'hotel', 'name_ar' => 'فنادق أبراج مكة', 'name_en' => 'Makkah Towers Hotels', 'phone' => '02000000103'],
            'madinah_hotel' => ['category' => 'hotel', 'name_ar' => 'فندق دار الهجرة - المدينة', 'name_en' => 'Dar Al Hijra Hotel - Madinah', 'phone' => '02000000104'],
            'visa_ksa' => ['category' => 'visa', 'name_ar' => 'مكتب التأشيرات السعودية', 'name_en' => 'Saudi Visa Services', 'phone' => '02000000105'],
            'buses_ksa' => ['category' => 'transport', 'name_ar' => 'شركة الحرمين للنقل', 'name_en' => 'Haramain Transport', 'phone' => '02000000106'],
            'nile_cruise' => ['category' => 'cruise', 'name_ar' => 'بواخر النيل الذهبية', 'name_en' => 'Golden Nile Cruises', 'phone' => '02000000107'],
            'red_sea' => ['category' => 'hotel', 'name_ar' => 'منتجع ريف شرم الشيخ', 'name_en' => 'Reef Sharm Resort', 'phone' => '02000000108'],
            'turkey_dmc' => ['category' => 'ground_handling', 'name_ar' => 'وكيل إسطنبول السياحي', 'name_en' => 'Istanbul Ground Agent', 'phone' => '02000000109'],
            'dubai_dmc' => ['category' => 'ground_handling', 'name_ar' => 'وكيل دبي السياحي', 'name_en' => 'Dubai Destination Management', 'phone' => '02000000110'],
            'insurance' => ['category' => 'insurance', 'name_ar' => 'مصر للتأمين السياحي', 'name_en' => 'Misr Travel Insurance', 'phone' => '02000000111'],
        ];
        $result = [];
        foreach ($rows as $key => $row) {
            $result[$key] = Supplier::query()->firstOrCreate(['phone' => $row['phone']], $row + ['branch_id' => $this->branch->id, 'settlement_currency' => 'EGP', 'is_active' => true]);
        }

        return $result;
    }

    /** @return array<string, Customer> */
    private function customers(): array
    {
        $rows = [
            'family_hassan' => ['عائلة حسن عبد الرحمن', 'Hassan Abdelrahman Family', '01100000001', 'individual'],
            'mona' => ['منى سعيد', 'Mona Saeed', '01100000002', 'individual'],
            'yousef' => ['يوسف إبراهيم', 'Yousef Ibrahim', '01100000003', 'individual'],
            'noha' => ['نهى فتحي', 'Noha Fathy', '01100000004', 'individual'],
            'karim' => ['كريم عادل', 'Karim Adel', '01100000005', 'individual'],
            'amal_group' => ['مجموعة الأمل للحج والعمرة', 'Al Amal Pilgrimage Group', '01100000006', 'company'],
            'school' => ['مدارس النيل الدولية', 'Nile International Schools', '01100000007', 'company'],
            'pharma' => ['شركة الشفاء للأدوية', 'Al Shifa Pharma', '01100000008', 'company'],
            'tamer' => ['تامر عبد الله', 'Tamer Abdallah', '01100000009', 'individual'],
            'hoda' => ['هدى مصطفى', 'Hoda Mostafa', '01100000010', 'individual'],
            'sherif' => ['شريف رضا', 'Sherif Reda', '01100000011', 'individual'],
            'bank_staff' => ['نادي موظفي بنك القاهرة', 'Cairo Bank Staff Club', '01100000012', 'company'],
        ];
        $result = [];
        foreach ($rows as $key => [$ar, $en, $phone, $type]) {
            $result[$key] = Customer::query()->firstOrCreate(['phone' => $phone], [
                'name_ar' => $ar, 'name_en' => $en, 'email' => $key.'@example.com', 'type' => $type,
                'branch_id' => $this->branch->id, 'credit_limit_minor' => 0, 'currency_code' => 'EGP', 'is_active' => true,
            ]);
        }

        return $result;
    }

    // ---- packages and departures --------------------------------------------------------------

    /** @return array<string, TourDeparture> */
    private function packages(): array
    {
        $today = Carbon::today();
        $packages = [
            'umrah_eco' => ['UMRAH-ECO-10', 'عمرة اقتصادية - 10 أيام', 'umrah', 'fixed', 'مكة والمدينة', 5_500_000, 4_300_000, [[-45, 10, 45], [20, 10, 45], [50, 10, 45]]],
            'umrah_ramadan' => ['UMRAH-RMD-15', 'عمرة رمضان - 15 يوماً', 'umrah', 'fixed', 'مكة والمدينة', 9_500_000, 7_600_000, [[75, 15, 30]]],
            'hajj' => ['HAJJ-2027', 'حج السياحي 2027', 'hajj', 'fixed', 'مكة ومنى وعرفات', 38_500_000, 32_000_000, [[120, 21, 40]]],
            'nile' => ['DOM-NILE-5', 'رحلة نيلية أسوان والأقصر - 5 أيام', 'domestic', 'fixed', 'أسوان والأقصر', 1_450_000, 980_000, [[-20, 5, 24], [14, 5, 24], [35, 5, 24]]],
            'sharm' => ['DOM-SHARM-4', 'شرم الشيخ - 4 ليال', 'domestic', 'fixed', 'شرم الشيخ', 1_100_000, 760_000, [[-60, 4, 30], [9, 4, 30], [28, 4, 30]]],
            'istanbul' => ['INT-IST-7', 'إسطنبول - 7 أيام', 'international', 'fixed', 'إسطنبول', 3_800_000, 2_900_000, [[30, 7, 20], [65, 7, 20]]],
            'dubai' => ['INT-DXB-5', 'دبي - 5 أيام', 'international', 'fixed', 'دبي', 4_200_000, 3_300_000, [[-30, 5, 20], [40, 5, 20]]],
            'alex' => ['DAY-ALEX', 'رحلة يوم إلى الإسكندرية', 'day_trip', 'fixed', 'الإسكندرية', 120_000, 80_000, [[3, 1, 45], [17, 1, 45]]],
            'corporate' => ['CORP-HRG', 'برنامج الشركات - الغردقة', 'corporate', 'private', 'الغردقة', 2_600_000, 1_900_000, []],
        ];
        $departures = [];
        foreach ($packages as $key => [$code, $name, $category, $type, $destination, $price, $cost, $dates]) {
            $package = TourPackage::query()->create([
                'branch_id' => $this->branch->id, 'code' => $code, 'name' => $name, 'category' => $category, 'execution_type' => $type,
                'destination' => $destination, 'description' => $name.' - شامل الإقامة والانتقالات والإرشاد.',
                'currency_code' => 'EGP', 'base_price_minor' => $price, 'estimated_cost_minor' => $cost, 'created_by' => $this->owner?->id,
            ]);
            foreach ($dates as $i => [$offset, $length, $capacity]) {
                $start = $today->copy()->addDays($offset);
                $departures[$key.'#'.$i] = TourDeparture::query()->create([
                    'tour_package_id' => $package->id, 'branch_id' => $this->branch->id, 'starts_on' => $start->toDateString(),
                    'ends_on' => $start->copy()->addDays($length)->toDateString(), 'capacity' => $capacity, 'status' => 'open',
                ]);
            }
        }

        return $departures;
    }

    // ---- bookings -----------------------------------------------------------------------------

    /**
     * @param  array<string, Customer>  $customers
     * @param  array<string, Supplier>  $suppliers
     * @param  array<string, TourDeparture>  $departures
     * @return list<array{booking: Booking, customer: string, kind: string}>
     */
    private function bookings(array $customers, array $suppliers, array $departures): array
    {
        $service = app(BookingService::class);
        $umrah = fn (int $pax) => [
            $this->item('visa', $suppliers['visa_ksa'], 'تأشيرة عمرة', $pax, 120_000, 180_000),
            $this->item('flight', $suppliers['saudia'], 'تذاكر طيران القاهرة - جدة - القاهرة', $pax, 1_450_000, 1_750_000),
            $this->item('hotel', $suppliers['makkah_hotel'], 'إقامة مكة - 6 ليال', $pax * 6, 160_000, 215_000),
            $this->item('hotel', $suppliers['madinah_hotel'], 'إقامة المدينة - 4 ليال', $pax * 4, 120_000, 160_000),
            $this->item('transport', $suppliers['buses_ksa'], 'انتقالات داخلية بالحافلات', $pax, 90_000, 130_000),
        ];
        $plans = [
            // key, customer, departure, pax, items, lifecycle, kind
            ['u1', 'family_hassan', 'umrah_eco#0', 4, $umrah(4), ['confirmed', 'in_progress', 'completed', 'closed'], 'umrah'],
            ['u2', 'mona', 'umrah_eco#0', 2, $umrah(2), ['confirmed', 'in_progress', 'completed', 'closed'], 'umrah'],
            ['u3', 'yousef', 'umrah_eco#1', 3, $umrah(3), ['confirmed'], 'umrah'],
            ['u4', 'amal_group', 'umrah_eco#1', 12, $umrah(12), ['quoted', 'confirmed'], 'umrah'],
            ['u5', 'noha', 'umrah_eco#2', 2, $umrah(2), ['quoted'], 'umrah'],
            ['r1', 'karim', 'umrah_ramadan#0', 2, $umrah(2), [], 'umrah'],
            ['h1', 'tamer', 'hajj#0', 2, [
                $this->item('visa', $suppliers['visa_ksa'], 'تأشيرة حج', 2, 300_000, 400_000),
                $this->item('flight', $suppliers['saudia'], 'تذاكر طيران الحج', 2, 1_900_000, 2_300_000),
                $this->item('hotel', $suppliers['makkah_hotel'], 'إقامة مكة وفترة المشاعر', 2, 12_000_000, 14_500_000),
                $this->item('transport', $suppliers['buses_ksa'], 'نقل الحجاج بين المشاعر', 2, 600_000, 800_000),
            ], ['confirmed'], 'hajj'],
            ['n1', 'hoda', 'nile#0', 4, [
                $this->item('program', $suppliers['nile_cruise'], 'رحلة نيلية - 4 ليال', 4, 700_000, 1_000_000),
                $this->item('flight', $suppliers['egyptair'], 'طيران القاهرة - أسوان - القاهرة', 4, 250_000, 350_000),
            ], ['confirmed', 'in_progress', 'completed', 'closed'], 'domestic'],
            ['n2', 'school', 'nile#1', 20, [
                $this->item('program', $suppliers['nile_cruise'], 'رحلة نيلية لطلاب المدارس', 20, 650_000, 900_000),
            ], ['confirmed'], 'domestic'],
            ['s1', 'sherif', 'sharm#0', 2, [
                $this->item('hotel', $suppliers['red_sea'], 'إقامة شرم الشيخ - 4 ليال', 8, 90_000, 135_000),
                $this->item('transport', $suppliers['buses_ksa'], 'انتقال من وإلى المطار', 1, 50_000, 80_000),
            ], ['confirmed', 'in_progress', 'completed', 'closed'], 'domestic'],
            ['s2', 'pharma', 'sharm#1', 10, [
                $this->item('hotel', $suppliers['red_sea'], 'إقامة وفد الشركة', 40, 90_000, 135_000),
                $this->item('flight', $suppliers['egyptair'], 'تذاكر طيران داخلية', 10, 220_000, 310_000),
            ], ['confirmed', 'in_progress'], 'domestic'],
            ['i1', 'bank_staff', 'istanbul#0', 14, [
                $this->item('flight', $suppliers['egyptair'], 'طيران القاهرة - إسطنبول', 14, 1_100_000, 1_400_000),
                $this->item('hotel', $suppliers['turkey_dmc'], 'إقامة وبرنامج إسطنبول', 14, 1_300_000, 1_650_000),
                $this->item('visa', $suppliers['turkey_dmc'], 'تأشيرات تركيا', 14, 60_000, 90_000),
            ], ['quoted', 'confirmed'], 'international'],
            ['d1', 'mona', 'dubai#0', 3, [
                $this->item('flight', $suppliers['egyptair'], 'طيران القاهرة - دبي', 3, 1_300_000, 1_600_000),
                $this->item('hotel', $suppliers['dubai_dmc'], 'إقامة وبرنامج دبي', 3, 1_700_000, 2_100_000),
            ], ['confirmed', 'in_progress', 'completed', 'closed'], 'international'],
            ['a1', 'noha', 'alex#0', 6, [
                $this->item('program', $suppliers['buses_ksa'], 'رحلة يوم الإسكندرية', 6, 80_000, 120_000),
            ], ['confirmed'], 'day_trip'],
        ];

        $result = [];
        foreach ($plans as [$key, $customer, $departureKey, $pax, $items, $lifecycle, $kind]) {
            $departure = $departures[$departureKey];
            $booking = $service->create([
                'branch_id' => $this->branch->public_id,
                'customer_id' => $customers[$customer]->public_id,
                'owner_user_id' => $this->owner?->public_id,
                'tour_departure_id' => $departure->public_id,
                'start_date' => $departure->starts_on->toDateString(),
                'end_date' => $departure->ends_on?->toDateString(),
                'pax_adults' => $pax,
                'currency' => 'EGP',
                'fx_rate' => '1',
                'notes' => TourPackage::query()->find($departure->tour_package_id)?->name,
                'items' => $items,
            ], $this->owner?->id);
            foreach ($lifecycle as $status) {
                $booking = $service->transition($booking, $status, 'Demo lifecycle', $this->owner?->id);
            }
            $result[] = ['booking' => $booking->refresh(), 'customer' => $customer, 'kind' => $kind, 'key' => $key];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function item(string $type, Supplier $supplier, string $description, int $quantity, int $cost, int $sell): array
    {
        return [
            'service_type' => $type, 'supplier_id' => $supplier->public_id, 'description' => $description,
            'quantity' => max(1, $quantity), 'unit_cost_minor' => $cost, 'unit_sell_minor' => $sell,
        ];
    }

    // ---- invoices, receipts ------------------------------------------------------------------

    /** Confirmed-or-later bookings get a customer invoice; finished ones are issued, the rest stay in the approval flow. */
    private function invoices(array $bookings): void
    {
        $service = app(InvoiceService::class);
        foreach ($bookings as $row) {
            /** @var Booking $booking */
            $booking = $row['booking'];
            if (! in_array($booking->status, ['confirmed', 'in_progress', 'completed', 'closed'], true)) {
                continue;
            }
            $invoice = $service->createFromBooking($booking, $this->owner?->id);
            if (in_array($row['key'], ['u3', 'u4', 'n2', 'i1'], true)) {
                $service->submit($invoice, $this->owner?->id); // waits for approval

                continue;
            }
            if (in_array($row['key'], ['a1'], true)) {
                continue; // stays a draft
            }
            $service->submit($invoice, $this->owner?->id);
            $service->approve($invoice, $this->approver?->id);
            $service->issue($invoice, $this->owner?->id);
        }
    }

    private function receipts(array $bookings): void
    {
        $service = app(PaymentService::class);
        $today = Carbon::now();
        // [booking key, share of the sell total, method code, ledger account, days ago]
        $plan = [
            ['u1', 1.0, 'BANK-TRANSFER', '1120', 40], ['u2', 1.0, 'CARD', '1121', 38],
            ['u3', 0.4, 'INSTAPAY', '1122', 12], ['u4', 0.25, 'BANK-TRANSFER', '1120', 6],
            ['h1', 0.3, 'CARD', '1121', 20], ['n1', 1.0, 'CASH', '1110', 17],
            ['n2', 0.5, 'BANK-TRANSFER', '1120', 5], ['s1', 1.0, 'WALLET', '1124', 55],
            ['s2', 0.5, 'INSTAPAY', '1122', 3], ['i1', 0.2, 'CARD', '1121', 2],
            ['d1', 1.0, 'BANK-TRANSFER', '1120', 25],
        ];
        $by = collect($bookings)->keyBy('key');
        foreach ($plan as [$key, $share, $methodCode, $accountCode, $daysAgo]) {
            $row = $by[$key] ?? null;
            if ($row === null) {
                continue;
            }
            /** @var Booking $booking */
            $booking = $row['booking'];
            $method = PaymentMethod::query()->where('code', $methodCode)->first();
            $service->record([
                'branch_id' => $this->branch->public_id,
                'customer_id' => $booking->customer->public_id,
                'booking_id' => $booking->public_id,
                'cash_account_id' => Account::query()->where('code', $accountCode)->value('public_id'),
                'method' => $method?->type === 'wallet' ? 'wallet' : ($method?->type === 'bank_transfer' ? 'bank_transfer' : ($method?->type === 'card' ? 'card' : 'cash')),
                'payment_method_id' => $method?->public_id,
                'amount_minor' => (int) round($booking->total_sell_minor * $share),
                'currency' => 'EGP', 'fx_rate' => '1',
                'paid_at' => $this->ago($daysAgo)->toIso8601String(),
            ], $this->owner?->id);
        }
    }

    // ---- supplier side -----------------------------------------------------------------------

    private function supplierBills(array $suppliers): void
    {
        $service = app(InvoiceService::class);
        $bills = [
            ['saudia', 'فاتورة تذاكر طيران الخطوط السعودية', 2_900_000, 0, 28],
            ['makkah_hotel', 'فاتورة إقامة فنادق مكة', 3_600_000, 0, 24],
            ['nile_cruise', 'فاتورة رحلات نيلية', 1_100_000, 140, 15],
            ['red_sea', 'فاتورة إقامة منتجع شرم الشيخ', 900_000, 140, 9],
            ['egyptair', 'فاتورة تذاكر مصر للطيران', 1_650_000, 0, 5],
        ];
        foreach ($bills as $i => [$key, $memo, $amount, $taxPct, $daysAgo]) {
            $tax = (int) round($amount * $taxPct / 1000);
            $line = ['account_id' => '5100', 'description' => $memo, 'debit_minor' => $amount] + ($tax > 0 ? ['tax_minor' => $tax, 'tax_account_id' => '1160'] : []);
            $bill = $service->create([
                'type' => Invoice::TYPE_SUPPLIER_INVOICE, 'party_type' => 'supplier', 'party_id' => $suppliers[$key]->public_id,
                'issue_date' => $this->ago($daysAgo)->toDateString(), 'due_date' => today()->addDays(30 - $daysAgo)->toDateString(),
                'currency' => 'EGP', 'memo' => $memo, 'lines' => [$line],
            ], $this->branch, $this->owner?->id);
            $service->submit($bill, $this->owner?->id);
            if ($i === 4) {
                continue; // the newest bill still waits for approval
            }
            $service->approve($bill, $this->approver?->id);
            $service->issue($bill, $this->owner?->id);
        }
    }

    private function supplierPayments(array $suppliers): void
    {
        $service = app(SupplierSettlementService::class);
        $payments = [
            ['saudia', '1121', 1_500_000, 20], ['makkah_hotel', '1120', 3_600_000, 14],
            ['nile_cruise', '1122', 500_000, 8], ['buses_ksa', '1110', 250_000, 4],
        ];
        foreach ($payments as [$key, $accountCode, $amount, $daysAgo]) {
            $service->recordPayment([
                'branch_id' => $this->branch->public_id,
                'supplier_id' => $suppliers[$key]->public_id,
                'cash_account_id' => Account::query()->where('code', $accountCode)->value('public_id'),
                'amount_minor' => $amount, 'currency' => 'EGP', 'fx_rate' => '1',
                'paid_at' => $this->ago($daysAgo)->toIso8601String(),
            ], $this->owner?->id);
        }
    }

    // ---- expenses ----------------------------------------------------------------------------

    private function expenses(array $suppliers): void
    {
        $service = app(ExpenseService::class);
        $cash = fn (string $code) => CashAccount::query()->whereHas('account', fn ($q) => $q->where('code', $code))->firstOrFail();
        $category = fn (string $code) => ExpenseCategory::query()->where('code', $code)->firstOrFail();
        // [category, days ago, amount, description, cash account code, final state]
        $rows = [
            ['RENT', 75, 2_500_000, 'إيجار المقر - الشهر الماضي', '1120', 'approved'],
            ['RENT', 45, 2_500_000, 'إيجار المقر', '1120', 'approved'],
            ['RENT', 12, 2_500_000, 'إيجار المقر - الشهر الحالي', '1120', 'approved'],
            ['UTIL', 40, 185_000, 'فواتير الكهرباء والإنترنت', '1121', 'approved'],
            ['UTIL', 8, 192_000, 'فواتير الكهرباء والإنترنت', '1121', 'approved'],
            ['MKT', 30, 600_000, 'حملة إعلانات الحج والعمرة على فيسبوك', '1122', 'approved'],
            ['MKT', 6, 450_000, 'تصوير وإعلانات الرحلات النيلية', '1122', 'approved'],
            ['OFFICE', 22, 78_000, 'مستلزمات مكتبية وقرطاسية', '1110', 'approved'],
            ['BANK', 18, 12_500, 'عمولات بنكية', '1120', 'approved'],
            ['TRAVEL', 10, 64_000, 'انتقالات وزيارات ميدانية للفنادق', '1112', 'approved'],
            ['OFFICE', 4, 96_000, 'صيانة أجهزة الحاسب', '1110', 'submitted'],
            ['MKT', 2, 300_000, 'رعاية معرض السياحة', '1122', 'submitted'],
            ['TRAVEL', 1, 40_000, 'تذاكر مندوب للمطار', '1112', 'draft'],
            ['OFFICE', 14, 55_000, 'هدايا عملاء (مرفوضة)', '1110', 'rejected'],
        ];
        foreach ($rows as [$cat, $daysAgo, $amount, $description, $account, $state]) {
            $expense = $service->create([
                'category_id' => $category($cat)->public_id,
                'expense_date' => $this->ago($daysAgo)->toDateString(),
                'amount_minor' => $amount, 'currency' => 'EGP', 'fx_rate' => '1',
                'description' => $description, 'payee_type' => 'none',
                'cash_account_id' => $cash($account)->public_id,
            ], $this->branch, $this->owner?->id);
            if ($state === 'draft') {
                continue;
            }
            $service->submit($expense, $this->owner?->id);
            if ($state === 'approved') {
                $service->approve($expense, $this->approver?->id);
            } elseif ($state === 'rejected') {
                $service->reject($expense, 'خارج الميزانية المعتمدة', $this->approver?->id);
            }
        }
    }

    // ---- people ------------------------------------------------------------------------------

    private function employees(): void
    {
        foreach ([
            ['EMP-003', 'ياسر منصور', 'Yasser Mansour', 'staff', '01000000103'],
            ['EMP-004', 'دعاء كمال', 'Doaa Kamal', 'staff', '01000000104'],
            ['EMP-005', 'عصام الشريف', 'Essam El Sherif', 'staff', '01000000105'],
        ] as [$code, $ar, $en, $category, $phone]) {
            Employee::query()->firstOrCreate(['internal_code' => $code], [
                'name_ar' => $ar, 'name_en' => $en, 'category' => $category, 'phone' => $phone,
                'email' => Str::slug($en).'@example.com', 'branch_id' => $this->branch->id, 'is_active' => true,
            ]);
        }
    }

    private function fixedAssets(): void
    {
        $service = app(FixedAssetService::class);
        $categories = [
            'computers' => $service->createCategory(['code' => 'COMP', 'name_ar' => 'أجهزة حاسب', 'name_en' => 'Computers', 'default_useful_life_months' => 36], $this->owner?->id),
            'furniture' => $service->createCategory(['code' => 'FURN', 'name_ar' => 'أثاث ومعدات مكتبية', 'name_en' => 'Furniture & office equipment', 'default_useful_life_months' => 60], $this->owner?->id),
            'vehicles' => $service->createCategory(['code' => 'VEH', 'name_ar' => 'مركبات', 'name_en' => 'Vehicles', 'default_useful_life_months' => 60], $this->owner?->id),
        ];
        $assets = [
            ['computers', 'أجهزة حاسب لقسم الحجوزات (6 أجهزة)', 18_000_000, 150],
            ['furniture', 'تجهيز مكتب الفرع الرئيسي', 9_500_000, 200],
            ['vehicles', 'حافلة ميني باص للرحلات الداخلية', 85_000_000, 100],
        ];
        foreach ($assets as [$category, $description, $value, $daysAgo]) {
            $date = $this->ago($daysAgo);
            $asset = $service->createAsset([
                'category_id' => $categories[$category]->public_id, 'description' => $description,
                'capitalization_date' => $date->toDateString(), 'in_service_date' => $date->toDateString(),
                'acquisition_value_minor' => $value, 'residual_value_minor' => 0,
                'useful_life_months' => $categories[$category]->default_useful_life_months, 'currency' => 'EGP',
            ], $this->branch, $this->owner?->id);
            $service->capitalize($asset, $this->approver?->id);
        }
    }

    private function advances(): void
    {
        $service = app(EmployeeFinanceService::class);
        foreach ([['EMP-001', 300_000, 30, 'سلفة شهرية'], ['EMP-003', 150_000, 10, 'سلفة طارئة']] as [$code, $amount, $daysAgo, $memo]) {
            $employee = Employee::query()->where('internal_code', $code)->first();
            if ($employee === null) {
                continue;
            }
            $advance = $service->requestAdvance([
                'employee_id' => $employee->public_id, 'amount_minor' => $amount, 'memo' => $memo,
                'request_date' => $this->ago($daysAgo)->toDateString(),
            ], $this->branch, $this->owner?->id);
            if ($code === 'EMP-001') {
                $service->approveAdvance($advance, $this->approver?->id);
            }
        }
    }

    private function leads(): void
    {
        $service = app(LeadService::class);
        $rows = [
            ['أحمد فؤاد', '01200000001', 'facebook', 'يسأل عن عمرة رمضان لعائلة من 5 أفراد.'],
            ['سلمى ياسين', '01200000002', 'instagram', 'تريد برنامج حج 2027 لوالديها.'],
            ['Peter Adel', '01200000003', 'website', 'Interested in a Dubai package for 4 in December.'],
            ['مدرسة المستقبل', '01200000004', 'referral', 'رحلة نهاية العام لـ 60 طالباً إلى الأقصر وأسوان.'],
            ['ريم صلاح', '01200000005', 'whatsapp', 'تسأل عن أسعار شرم الشيخ في الإجازة.'],
            ['عمرو طارق', '01200000006', 'walk_in', 'زيارة للفرع: عمرة اقتصادية لشخصين.'],
            ['شركة المهندسين المتحدة', '01200000007', 'phone', 'برنامج شركات الغردقة لـ 25 موظفاً.'],
            ['Laila Hamdy', '01200000008', 'instagram', 'Asked for the Istanbul 7-day program.'],
        ];
        foreach ($rows as [$name, $phone, $source, $notes]) {
            $service->create([
                'name' => $name, 'phone' => $phone, 'source' => $source, 'notes' => $notes,
                'branch_id' => $this->branch->public_id, 'consent' => true,
            ], $this->owner?->id);
        }
    }
}
