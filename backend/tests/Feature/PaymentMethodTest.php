<?php

namespace Tests\Feature;

use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class PaymentMethodTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();

        foreach (['payments.view', 'payments.create'] as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo(['payments.view', 'payments.create']);
        Sanctum::actingAs($this->user);
    }

    public function test_method_details_use_public_account_ids_and_receipt_resolves_method(): void
    {
        $cash = $this->account('1110');

        $payload = [
            'code' => 'BANK-TEST',
            'name_ar' => 'تحويل بنكي تجريبي',
            'name_en' => 'Test bank transfer',
            'type' => 'bank_transfer',
            'default_cash_account_id' => $cash->public_id,
            'provider_name' => 'Example Bank',
            'account_name' => 'Tourism Company',
            'account_reference' => '123456789',
            'iban' => 'EG12345678901234567890123456789',
            'instructions' => 'Include booking number.',
        ];

        $id = $this->postJson('/api/v1/payment-methods', $payload)
            ->assertCreated()
            ->assertJsonPath('data.default_cash_account_id', $cash->public_id)
            ->assertJsonPath('data.provider_name', 'Example Bank')
            ->json('data.id');

        $this->assertNotEmpty($id);
        $method = PaymentMethod::query()->where('public_id', $id)->firstOrFail();
        $this->assertSame($cash->id, $method->default_cash_account_id);

        $this->patchJson('/api/v1/payment-methods/'.$id, [
            'instructions' => 'Use the invoice number.',
        ])->assertOk()->assertJsonPath('data.instructions', 'Use the invoice number.');

        $payment = app(PaymentService::class)->record([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $this->customer()->public_id,
            'cash_account_id' => $cash->public_id,
            'payment_method_id' => $id,
            'method' => 'cash',
            'amount_minor' => 10000,
            'currency' => 'EGP',
            'fx_rate' => '1',
            'paid_at' => now()->toIso8601String(),
        ], $this->user->id);

        $this->assertSame($method->id, $payment->payment_method_id);
        $this->assertSame('bank_transfer', $payment->method);
    }

    public function test_payment_method_code_is_generated_automatically_and_is_immutable(): void
    {
        $payload = [
            'name_ar' => 'تحصيل نقدي',
            'name_en' => 'Cash collection',
            'type' => 'cash',
        ];
        $first = $this->postJson('/api/v1/payment-methods', $payload)->assertCreated()->json('data');
        $second = $this->postJson('/api/v1/payment-methods', $payload)->assertCreated()->json('data');
        $this->assertStringStartsWith('PM-', $first['code']);
        $this->assertNotSame($first['code'], $second['code']);

        $this->patchJson('/api/v1/payment-methods/'.$first['id'], [
            'code' => 'MANUAL-CODE',
            'name_ar' => 'نقدي',
        ])->assertOk()->assertJsonPath('data.code', $first['code']);
        $this->assertDatabaseMissing('payment_methods', ['code' => 'MANUAL-CODE']);
    }

    public function test_inactive_method_cannot_be_used_on_receipt(): void
    {
        $method = PaymentMethod::query()->create([
            'code' => 'OLD-WALLET',
            'name_ar' => 'قديمة',
            'name_en' => 'Old wallet',
            'type' => 'wallet',
            'is_active' => false,
        ]);

        $this->expectException(\DomainException::class);

        app(PaymentService::class)->record([
            'branch_id' => $this->branch->public_id,
            'customer_id' => $this->customer()->public_id,
            'cash_account_id' => $this->account('1110')->public_id,
            'payment_method_id' => $method->public_id,
            'amount_minor' => 10000,
            'currency' => 'EGP',
            'fx_rate' => '1',
            'paid_at' => now()->toIso8601String(),
        ], $this->user->id);
    }
}
