<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingDocument;
use App\Modules\Accounting\Models\AccountingSchedule;
use App\Modules\Bookings\Models\TourPackage;
use App\Modules\Core\Models\User;
use App\Modules\Parties\Models\Employee;
use App\Modules\Payments\Models\SupplierPayment;
use App\Modules\Treasury\Models\BankStatement;
use App\Modules\Treasury\Models\CashAccount;
use App\Modules\Treasury\Models\Transfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** The second batch of additive list filters (docs/design/api-filters-wishlist-2026-11-14.md). */
class ListFiltersMoreTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        $permissions = ['accounts.view', 'settings.view', 'transfers.view', 'employees.view', 'documents.view', 'reconciliation.view',
            'schedules.view', 'supplier_payments.view', 'bookings.view'];
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

    private function cash(string $kind, string $currency, string $name): CashAccount
    {
        // One ledger account per cash/bank account (unique), so each fixture gets its own.
        $ledger = Account::query()->create([
            'code' => '19'.str_pad((string) ++$this->seq, 2, '0', STR_PAD_LEFT), 'name_ar' => $name, 'name_en' => $name,
            'type' => 'asset', 'is_postable' => true, 'is_active' => true,
        ]);

        return CashAccount::query()->create([
            'account_id' => $ledger->id, 'branch_id' => $this->branch->id, 'kind' => $kind,
            'name_ar' => $name, 'name_en' => $name, 'currency_code' => $currency, 'is_active' => true,
        ]);
    }

    public function test_tour_packages_filter(): void
    {
        $make = fn (string $code, string $name, string $category, string $type, bool $active) => TourPackage::query()->create([
            'branch_id' => $this->branch->id, 'code' => $code, 'name' => $name, 'destination' => 'Dahab', 'category' => $category,
            'execution_type' => $type, 'currency_code' => 'EGP', 'base_price_minor' => 1000, 'estimated_cost_minor' => 500, 'is_active' => $active,
        ]);
        $make('P-1', 'Winter tour', 'domestic', 'fixed', true);
        $make('P-2', 'Umrah', 'religious', 'generic', false);

        $this->assertCount(2, $this->rows('/api/v1/tour-packages'));
        $this->assertSame('P-2', $this->rows('/api/v1/tour-packages?category=religious')[0]['code']);
        $this->assertSame('P-1', $this->rows('/api/v1/tour-packages?execution_type=fixed')[0]['code']);
        $this->assertSame('P-2', $this->rows('/api/v1/tour-packages?status=inactive')[0]['code']);
        $this->assertCount(1, $this->rows('/api/v1/tour-packages?status=active'));
        $this->assertSame('P-1', $this->rows('/api/v1/tour-packages?search=winter')[0]['code']);
        $this->assertCount(2, $this->rows('/api/v1/tour-packages?search=dahab'));
    }

    public function test_cash_accounts_filter_by_kind_currency_and_search(): void
    {
        $this->cash('bank', 'USD', 'Alpha Bank USD');
        $kinds = collect($this->rows('/api/v1/cash-accounts'))->pluck('kind')->unique()->all();
        $this->assertContains('bank', $kinds);

        $this->assertSame(['bank'], collect($this->rows('/api/v1/cash-accounts?kind=bank'))->pluck('kind')->unique()->all());
        $this->assertNotContains('bank', collect($this->rows('/api/v1/cash-accounts?kind=cash'))->pluck('kind')->all());
        $this->assertCount(1, $this->rows('/api/v1/cash-accounts?currency=usd'));
        $this->assertCount(1, $this->rows('/api/v1/cash-accounts?search=alpha'));
        $this->assertCount(0, $this->rows('/api/v1/cash-accounts?search=zzzz'));
    }

    public function test_transfers_filter_by_status_and_either_account(): void
    {
        $a = $this->cash('cash', 'EGP', 'Till A');
        $b = $this->cash('bank', 'EGP', 'Bank B');
        $c = $this->cash('bank', 'EGP', 'Bank C');
        $make = fn (CashAccount $from, CashAccount $to, string $status) => Transfer::query()->create([
            'number' => 'TR-F-'.++$this->seq, 'branch_id' => $this->branch->id, 'from_cash_account_id' => $from->id, 'to_cash_account_id' => $to->id,
            'transfer_date' => now()->toDateString(), 'from_currency' => 'EGP', 'from_amount_minor' => 1000, 'from_fx_rate' => '1',
            'to_currency' => 'EGP', 'to_amount_minor' => 1000, 'to_fx_rate' => '1', 'fee_minor' => 0, 'status' => $status,
        ]);
        $make($a, $b, 'posted');
        $make($b, $c, 'reversed');

        $this->assertCount(2, $this->rows('/api/v1/transfers'));
        $this->assertCount(1, $this->rows('/api/v1/transfers?status=reversed'));
        $this->assertCount(2, $this->rows('/api/v1/transfers?cash_account_id='.$b->public_id));
        $this->assertCount(1, $this->rows('/api/v1/transfers?cash_account_id='.$a->public_id));
        $this->assertCount(1, $this->rows('/api/v1/transfers?cash_account_id='.$c->public_id.'&status=reversed'));
    }

    public function test_bank_statements_filter_by_period(): void
    {
        $bank = $this->cash('bank', 'EGP', 'Statement bank');
        $make = fn (string $from, string $to) => BankStatement::query()->create([
            'cash_account_id' => $bank->id, 'branch_id' => $this->branch->id, 'filename' => 's.csv', 'file_hash' => hash('sha256', $from.$to),
            'format' => 'csv', 'period_from' => $from, 'period_to' => $to, 'opening_balance_minor' => 0, 'closing_balance_minor' => 0,
            'currency_code' => 'EGP', 'line_count' => 0,
        ]);
        $make('2026-01-01', '2026-01-31');
        $make('2026-01-20', '2026-02-10');
        $make('2026-03-01', '2026-03-31');

        $this->assertCount(3, $this->rows('/api/v1/bank-statements'));
        $this->assertCount(1, $this->rows('/api/v1/bank-statements?period=2026-03'));
        $this->assertCount(2, $this->rows('/api/v1/bank-statements?period=2026-01'));
        $this->assertCount(1, $this->rows('/api/v1/bank-statements?period=2026-02'));
        $this->assertCount(3, $this->rows('/api/v1/bank-statements?period=garbage'));
    }

    public function test_accounts_filter_by_active_postable_and_classification(): void
    {
        $all = $this->rows('/api/v1/accounts');
        $first = Account::query()->where('code', '1110')->firstOrFail();
        $first->update(['is_active' => false]);

        $this->assertCount(count($all), $this->rows('/api/v1/accounts'));
        $this->assertCount(1, $this->rows('/api/v1/accounts?is_active=0'));
        $this->assertCount(count($all) - 1, $this->rows('/api/v1/accounts?is_active=1'));
        $postable = collect($this->rows('/api/v1/accounts?is_postable=1'));
        $this->assertTrue($postable->every(fn ($row) => $row['is_postable'] === true));
        $this->assertTrue(collect($this->rows('/api/v1/accounts?is_postable=0'))->every(fn ($row) => $row['is_postable'] === false));

        Account::query()->where('code', '1100')->update(['classification' => 'current']);
        $this->assertSame(['1100'], collect($this->rows('/api/v1/accounts?classification=current'))->pluck('code')->all());
    }

    public function test_employees_filter_by_category(): void
    {
        $make = fn (string $code, string $category) => Employee::query()->create([
            'internal_code' => $code, 'name_ar' => 'موظف '.$code, 'name_en' => 'Emp '.$code, 'category' => $category,
            'branch_id' => $this->branch->id, 'is_active' => true,
        ]);
        $make('E-1', 'guide');
        $make('E-2', 'sales');

        $this->assertCount(2, $this->rows('/api/v1/employees'));
        $this->assertSame('E-2', $this->rows('/api/v1/employees?category=sales')[0]['internal_code']);
    }

    public function test_fx_rates_filter_by_date_range(): void
    {
        foreach (['2026-01-05', '2026-02-05', '2026-03-05'] as $date) {
            DB::table('fx_rates')->insert(['currency_code' => 'USD', 'rate' => '50', 'rate_date' => $date, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertCount(3, $this->rows('/api/v1/fx-rates?currency=USD'));
        $this->assertCount(2, $this->rows('/api/v1/fx-rates?currency=USD&from=2026-02-01'));
        $this->assertCount(1, $this->rows('/api/v1/fx-rates?currency=USD&from=2026-02-01&to=2026-02-28'));
    }

    public function test_supplier_payments_filter_by_status(): void
    {
        $supplier = $this->supplier();
        $cash = $this->account('1110');
        $make = fn (string $status) => SupplierPayment::query()->create([
            'supplier_id' => $supplier->id, 'branch_id' => $this->branch->id, 'amount_minor' => 1000, 'currency_code' => 'EGP', 'fx_rate' => '1',
            'fx_rate_date' => now()->toDateString(), 'cash_account_id' => $cash->id, 'status' => $status, 'paid_at' => now(),
        ]);
        $make('posted');
        $make('cancelled');

        $this->assertCount(2, $this->rows('/api/v1/supplier-payments'));
        $this->assertCount(1, $this->rows('/api/v1/supplier-payments?status=cancelled'));
    }

    public function test_schedules_search(): void
    {
        $make = fn (string $description, string $reference) => AccountingSchedule::query()->create([
            'number' => 'SCH-F-'.++$this->seq, 'branch_id' => $this->branch->id, 'kind' => 'prepaid', 'status' => 'draft', 'description' => $description,
            'reference' => $reference, 'currency_code' => 'EGP', 'fx_rate' => '1', 'fx_rate_date' => now()->toDateString(), 'total_minor' => 1200,
            'periods' => 12, 'frequency' => 'monthly', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addYear()->toDateString(),
            'source_account_id' => $this->account('1110')->id, 'target_account_id' => $this->account('5100')->id, 'recognized_minor' => 0,
        ]);
        $make('Office insurance', 'POL-77');
        $make('Software licence', 'LIC-1');

        $this->assertCount(2, $this->rows('/api/v1/accounting-schedules'));
        $this->assertSame('Office insurance', $this->rows('/api/v1/accounting-schedules?search=insurance')[0]['description']);
        $this->assertSame('Software licence', $this->rows('/api/v1/accounting-schedules?search=LIC-1')[0]['description']);
        $this->assertCount(1, $this->rows('/api/v1/accounting-schedules?search='.AccountingSchedule::query()->first()->number));
    }

    public function test_documents_filter_by_party_public_id(): void
    {
        $customer = $this->customer();
        $supplier = $this->supplier();
        $make = fn ($party, string $type) => AccountingDocument::query()->create([
            'type' => 'receipt_voucher', 'series' => 'RV', 'number' => 'RV-F-'.++$this->seq, 'party_type' => $type, 'party_id' => $party->id,
            'branch_id' => $this->branch->id, 'currency_code' => 'EGP', 'fx_rate' => '1', 'fx_rate_date' => now()->toDateString(),
            'status' => 'draft', 'subtotal_minor' => 1000, 'tax_minor' => 0, 'total_minor' => 1000,
        ]);
        $make($customer, 'customer');
        $make($supplier, 'supplier');

        $this->assertCount(2, $this->rows('/api/v1/accounting-documents'));
        $this->assertCount(1, $this->rows('/api/v1/accounting-documents?party_type=customer&party_id='.$customer->public_id));
        $this->assertCount(1, $this->rows('/api/v1/accounting-documents?party_type=supplier&party_id='.$supplier->public_id));
        // The internal id keeps working.
        $this->assertCount(1, $this->rows('/api/v1/accounting-documents?party_type=customer&party_id='.$customer->id));
    }
}
