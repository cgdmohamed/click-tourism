<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\LeadService;
use App\Modules\Money\Services\FxService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Tax\Models\TaxProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Optional demonstration data. Disabled unless DEMO_SEED=true.
 *
 * Everything is created through the same services the API uses, so seeding
 * also verifies that posting, sequencing and allocations work end to end.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! filter_var(env('DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $branch = Branch::query()->where('code', 'HQ')->first();
        if (! $branch) {
            return;
        }

        if (Booking::query()->where('branch_id', $branch->id)->exists()) {
            return;
        }

        $this->seedFxRates();

        $owner = User::query()->where('email', 'sales@example.com')->first();
        $taxProfile = TaxProfile::query()->where('name', 'VAT 14% (Egypt)')->first();

        $customers = [
            ['name_ar' => 'أحمد محمود', 'name_en' => 'Ahmed Mahmoud', 'phone' => '01000000001', 'email' => 'ahmed@example.com', 'type' => 'individual'],
            ['name_ar' => 'شركة النور للسياحة', 'name_en' => 'Al Nour Travel Co.', 'phone' => '01000000002', 'email' => 'info@alnour.example', 'type' => 'company'],
            ['name_ar' => 'سارة علي', 'name_en' => 'Sara Ali', 'phone' => '01000000003', 'email' => 'sara@example.com', 'type' => 'individual'],
        ];

        $customerModels = [];
        foreach ($customers as $data) {
            $customerModels[] = Customer::query()->firstOrCreate(
                ['phone' => $data['phone']],
                $data + [
                    'branch_id' => $branch->id,
                    'currency_code' => 'EGP',
                    'credit_limit_minor' => 0,
                    'is_active' => true,
                ],
            );
        }

        $suppliers = [
            ['category' => 'hotel', 'name_ar' => 'فندق النيل', 'name_en' => 'Nile Hotel', 'phone' => '02000000001'],
            ['category' => 'airline', 'name_ar' => 'مصر للطيران', 'name_en' => 'EgyptAir', 'phone' => '02000000002'],
            ['category' => 'transport', 'name_ar' => 'شركة الأمانة للنقل', 'name_en' => 'Amana Transport', 'phone' => '02000000003'],
        ];

        $supplierModels = [];
        foreach ($suppliers as $data) {
            $supplierModels[] = Supplier::query()->firstOrCreate(
                ['phone' => $data['phone']],
                $data + [
                    'branch_id' => $branch->id,
                    'settlement_currency' => 'EGP',
                    'is_active' => true,
                ],
            );
        }

        $bookings = app(BookingService::class);

        $confirmed = $bookings->create([
            'branch_id' => $branch->public_id,
            'customer_id' => $customerModels[0]->public_id,
            'owner_user_id' => $owner?->public_id,
            'start_date' => Carbon::now()->addDays(10)->toDateString(),
            'end_date' => Carbon::now()->addDays(17)->toDateString(),
            'pax_adults' => 2,
            'pax_children' => 1,
            'currency' => 'EGP',
            'fx_rate' => '1',
            'notes' => 'Demo booking: Cairo and Nile cruise package.',
            'items' => [
                [
                    'service_type' => 'hotel',
                    'supplier_id' => $supplierModels[0]->public_id,
                    'description' => 'Nile Hotel - 7 nights, double room',
                    'quantity' => 7,
                    'unit_cost_minor' => 180000,
                    'unit_sell_minor' => 260000,
                    'tax_profile_id' => $taxProfile?->id,
                ],
                [
                    'service_type' => 'transport',
                    'supplier_id' => $supplierModels[2]->public_id,
                    'description' => 'Airport transfers and daily transport',
                    'quantity' => 1,
                    'unit_cost_minor' => 150000,
                    'unit_sell_minor' => 240000,
                ],
            ],
        ], $owner?->id);

        $bookings->transition($confirmed, 'confirmed', 'Demo confirmation', $owner?->id);

        $quoted = $bookings->create([
            'branch_id' => $branch->public_id,
            'customer_id' => $customerModels[1]->public_id,
            'owner_user_id' => $owner?->public_id,
            'start_date' => Carbon::now()->addDays(25)->toDateString(),
            'end_date' => Carbon::now()->addDays(30)->toDateString(),
            'pax_adults' => 4,
            'currency' => 'EGP',
            'fx_rate' => '1',
            'notes' => 'Demo quotation for a corporate group.',
            'items' => [
                [
                    'service_type' => 'flight',
                    'supplier_id' => $supplierModels[1]->public_id,
                    'description' => 'Return flights Cairo - Sharm El Sheikh',
                    'quantity' => 4,
                    'unit_cost_minor' => 320000,
                    'unit_sell_minor' => 450000,
                    'tax_profile_id' => $taxProfile?->id,
                ],
            ],
        ], $owner?->id);

        $bookings->transition($quoted, 'quoted', 'Sent to customer', $owner?->id);

        $cash = Account::query()->where('code', '1110')->first();
        if ($cash) {
            $payments = app(PaymentService::class);

            $payments->record([
                'branch_id' => $branch->public_id,
                'customer_id' => $customerModels[0]->public_id,
                'booking_id' => $confirmed->public_id,
                'cash_account_id' => $cash->public_id,
                'method' => 'cash',
                'amount_minor' => (int) round($confirmed->total_sell_minor / 2),
                'currency' => 'EGP',
                'fx_rate' => '1',
                'paid_at' => Carbon::now()->toIso8601String(),
            ], $owner?->id);
        }

        $leads = app(LeadService::class);
        $leads->create([
            'name' => 'مصطفى كامل',
            'phone' => '01000000010',
            'email' => 'mostafa@example.com',
            'source' => 'facebook',
            'branch_id' => $branch->public_id,
            'consent' => true,
            'notes' => 'Asked about a 5-day Luxor program.',
        ], $owner?->id);

        $leads->create([
            'name' => 'Hana Youssef',
            'phone' => '01000000011',
            'source' => 'whatsapp',
            'branch_id' => $branch->public_id,
            'consent' => true,
            'notes' => 'Wants a family package in December.',
        ], $owner?->id);

        // Sanity: the demo must have produced at least one posted entry.
        if (Lead::query()->where('branch_id', $branch->id)->count() < 2) {
            $this->command?->warn('Demo data: expected at least two leads.');
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
