<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\Supplier;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** Additive list filters requested by the list redesign (docs/design/api-filters-wishlist-2026-11-14.md). */
class ListFiltersTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $permissions = ['invoices.view', 'payments.view', 'customers.view', 'suppliers.view'];
        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        /** @var User $user */
        $user = $this->user;
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);
    }

    private function rows(string $url): array
    {
        return $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])->getJson($url)->assertOk()->json('data');
    }

    private function invoice(string $status, int $outstanding, ?string $due): Invoice
    {
        $customer = $this->customer();

        return Invoice::query()->create([
            'type' => 'customer_invoice',
            'series' => 'INV',
            'number' => 'INV-F-'.++$this->seq,
            'party_type' => 'customer',
            'party_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'issue_date' => now()->subDays(40)->toDateString(),
            'due_date' => $due,
            'currency_code' => 'EGP',
            'fx_rate' => '1',
            'fx_rate_date' => now()->toDateString(),
            'status' => $status,
            'subtotal_minor' => 1000,
            'tax_minor' => 0,
            'total_minor' => 1000,
            'outstanding_minor' => $outstanding,
        ]);
    }

    public function test_invoices_filter_by_due_date_and_overdue(): void
    {
        $late = $this->invoice('issued', 1000, now()->subDays(10)->toDateString());
        $this->invoice('issued', 1000, now()->addDays(10)->toDateString());
        $this->invoice('issued', 0, now()->subDays(10)->toDateString());
        $this->invoice('draft', 1000, now()->subDays(10)->toDateString());

        $overdue = collect($this->rows('/api/v1/invoices?overdue=1'))->pluck('number')->all();
        $this->assertSame([$late->number], $overdue);

        $window = $this->rows('/api/v1/invoices?due_from='.now()->addDays(5)->toDateString().'&due_to='.now()->addDays(15)->toDateString());
        $this->assertCount(1, $window);
        $this->assertCount(4, $this->rows('/api/v1/invoices'));
    }

    public function test_payments_filter_by_status_method_and_unallocated(): void
    {
        $method = PaymentMethod::query()->create(['code' => 'CARD', 'name_ar' => 'بطاقة', 'name_en' => 'Card', 'type' => 'card', 'is_active' => true]);
        $make = fn (string $status, int $unallocated, ?PaymentMethod $with = null) => Payment::query()->create([
            'number' => 'RC-F-'.++$this->seq,
            'customer_id' => $this->customer()->id,
            'branch_id' => $this->branch->id,
            'method' => 'cash',
            'payment_method_id' => $with?->id,
            'cash_account_id' => $this->account('1110')->id,
            'amount_minor' => 1000,
            'currency_code' => 'EGP',
            'fx_rate' => '1',
            'fx_rate_date' => now()->toDateString(),
            'unallocated_minor' => $unallocated,
            'status' => $status,
            'paid_at' => now(),
        ]);
        $advance = $make('posted', 1000, $method);
        $make('posted', 0);
        $make('cancelled', 1000);

        $this->assertCount(3, $this->rows('/api/v1/payments'));
        $this->assertCount(1, $this->rows('/api/v1/payments?status=cancelled'));
        $this->assertSame([$advance->number], collect($this->rows('/api/v1/payments?payment_method_id='.$method->public_id))->pluck('number')->all());
        $this->assertCount(2, $this->rows('/api/v1/payments?unallocated=1'));
        $this->assertCount(1, $this->rows('/api/v1/payments?unallocated=1&status=posted&payment_method_id='.$method->public_id));
    }

    public function test_customers_filter_by_active_and_type(): void
    {
        $this->customer();
        Customer::query()->create(['type' => 'company', 'name_ar' => 'شركة', 'name_en' => 'Acme Co', 'branch_id' => $this->branch->id, 'credit_limit_minor' => 0, 'is_active' => false]);

        $this->assertCount(2, $this->rows('/api/v1/customers'));
        $this->assertCount(1, $this->rows('/api/v1/customers?is_active=0'));
        $this->assertCount(1, $this->rows('/api/v1/customers?is_active=1'));
        $this->assertSame('Acme Co', $this->rows('/api/v1/customers?type=company')[0]['name_en']);
    }

    public function test_suppliers_filter_by_active_category_and_settlement_currency(): void
    {
        $this->supplier();
        Supplier::query()->create(['category' => 'airline', 'name_ar' => 'طيران', 'name_en' => 'Air Co', 'branch_id' => $this->branch->id, 'settlement_currency' => 'USD', 'is_active' => false]);

        $this->assertCount(2, $this->rows('/api/v1/suppliers'));
        $this->assertCount(1, $this->rows('/api/v1/suppliers?is_active=0'));
        $this->assertSame('Air Co', $this->rows('/api/v1/suppliers?category=airline')[0]['name_en']);
        $this->assertCount(1, $this->rows('/api/v1/suppliers?settlement_currency=usd'));
        $this->assertCount(0, $this->rows('/api/v1/suppliers?category=airline&settlement_currency=EGP'));
    }
}
