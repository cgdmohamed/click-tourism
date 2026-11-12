<?php

namespace Tests\Feature;

use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Services\PartyLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** GET /accounts exposes the owning party on ADR-0015 subledger accounts (additive, read-only). */
class AccountPartyFieldsTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        Permission::query()->firstOrCreate(['name' => 'accounts.view', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo('accounts.view');
        Sanctum::actingAs($this->user);
    }

    public function test_party_subaccounts_carry_party_type_and_names_and_ordinary_accounts_do_not(): void
    {
        $customer = $this->customer();
        $subaccount = app(PartyLedgerService::class)->receivable($customer->id);

        $rows = collect($this->withHeaders(['X-Branch-Id' => $this->branch->public_id])
            ->getJson('/api/v1/accounts')->assertOk()->json('data'));

        $party = $rows->firstWhere('id', $subaccount->public_id);
        $this->assertSame('customer', $party['party_type']);
        $this->assertSame('receivable', $party['party_role']);
        $this->assertSame($customer->name_ar, $party['party_name_ar']);
        $this->assertSame($customer->name_en, $party['party_name_en']);
        $this->assertNotNull($party['parent_id']);

        $ordinary = $rows->firstWhere('code', '4100');
        $this->assertNull($ordinary['party_type']);
        $this->assertNull($ordinary['party_name_en']);
    }

    public function test_listing_accounts_uses_a_constant_number_of_queries_regardless_of_party_count(): void
    {
        $ledger = app(PartyLedgerService::class);
        $count = function (string $query = ''): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])->getJson('/api/v1/accounts'.$query)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $ledger->receivable($this->customer()->id);
        $count(); // warm-up: settings/permission caches fill on the first request
        $few = $count();

        for ($i = 0; $i < 12; $i++) {
            $ledger->receivable($this->newCustomer($i)->id);
        }
        $many = $count();

        $this->assertSame($few, $many, 'GET /accounts must not run a query per party');
        // A filter that excludes every party account does not look up party names at all.
        $this->assertLessThanOrEqual($many, $count('?type=revenue'));
    }

    private function newCustomer(int $n): Customer
    {
        return Customer::query()->create([
            'type' => 'individual', 'name_ar' => 'عميل '.$n, 'name_en' => 'Customer '.$n, 'phone' => '0100000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'branch_id' => $this->branch->id, 'credit_limit_minor' => 0, 'currency_code' => 'EGP', 'is_active' => true,
        ]);
    }
}
