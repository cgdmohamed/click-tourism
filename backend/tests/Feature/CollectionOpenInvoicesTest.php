<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Invoices\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** W3 (owner decision 2026-11-14): the cashier finds a customer's open invoices without the invoice list permission. */
class CollectionOpenInvoicesTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        Permission::query()->firstOrCreate(['name' => 'payments.create', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        /** @var User $user */
        $user = $this->user;
        $user->givePermissionTo('payments.create');
        Sanctum::actingAs($user);
    }

    private function invoice($customer, string $status, int $outstanding, string $currency = 'EGP'): Invoice
    {
        return Invoice::query()->create([
            'type' => 'customer_invoice', 'series' => 'INV', 'number' => 'INV-C-'.++$this->seq, 'party_type' => 'customer', 'party_id' => $customer->id,
            'branch_id' => $this->branch->id, 'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(10)->toDateString(),
            'currency_code' => $currency, 'fx_rate' => '1', 'fx_rate_date' => now()->toDateString(), 'status' => $status,
            'subtotal_minor' => 1000, 'tax_minor' => 0, 'total_minor' => 1000, 'outstanding_minor' => $outstanding,
        ]);
    }

    private function fetch(string $url)
    {
        return $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])->getJson($url);
    }

    public function test_the_cashier_gets_only_the_open_invoices_of_that_customer(): void
    {
        $customer = $this->customer();
        $other = $this->customer($this->branch);
        $open = $this->invoice($customer, 'issued', 700);
        $this->invoice($customer, 'issued', 0);
        $this->invoice($customer, 'draft', 1000);
        $this->invoice($customer, 'issued', 500, 'USD');
        $this->invoice($other, 'issued', 900);

        // Same user has no right to list invoices.
        $this->fetch('/api/v1/invoices')->assertForbidden();

        $rows = $this->fetch('/api/v1/collections/open-invoices?customer_id='.$customer->public_id)->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['EGP', 'USD'], collect($rows)->pluck('currency')->all());
        $this->assertCount(2, $rows);
        $this->assertSame(['id', 'number', 'issue_date', 'due_date', 'currency', 'total_minor', 'outstanding_minor'], array_keys($rows[0]));

        $egp = $this->fetch('/api/v1/collections/open-invoices?customer_id='.$customer->public_id.'&currency=egp')->assertOk()->json('data');
        $this->assertSame([$open->public_id], collect($egp)->pluck('id')->all());
        $this->assertCount(1, $this->fetch('/api/v1/collections/open-invoices?customer_id='.$customer->public_id.'&search='.$open->number)->json('data'));
    }

    public function test_it_needs_payments_create_and_a_customer(): void
    {
        $this->fetch('/api/v1/collections/open-invoices')->assertStatus(422);

        $nobody = User::factory()->create();
        Sanctum::actingAs($nobody);
        $this->fetch('/api/v1/collections/open-invoices?customer_id='.$this->customer()->public_id)->assertForbidden();
    }

    public function test_a_user_without_a_branch_assignment_gets_nothing_from_any_branch(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, 'issued', 700);

        $unassigned = User::factory()->create();
        $unassigned->givePermissionTo('payments.create');
        Sanctum::actingAs($unassigned);

        // With and without a branch header, an unassigned user is refused rather than shown every branch.
        $this->getJson('/api/v1/collections/open-invoices?customer_id='.$customer->public_id)->assertForbidden();
        $this->withHeaders(['X-Branch-Id' => $this->branch->public_id])
            ->getJson('/api/v1/collections/open-invoices?customer_id='.$customer->public_id)->assertForbidden();
    }
}
