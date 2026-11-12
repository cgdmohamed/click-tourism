<?php

namespace Tests\Feature;

use App\Modules\Parties\Services\PartyLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
