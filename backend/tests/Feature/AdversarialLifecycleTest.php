<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/** Tries to break the document lifecycle: edit after issue, double actions, over-collection, closed periods. */
class AdversarialLifecycleTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMS = ['invoices.view', 'invoices.create', 'invoices.approve', 'journal.view', 'journal.create',
        'journal.reverse', 'payments.create', 'payments.view', 'payments.reverse'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        foreach (self::PERMS as $p) {
            Permission::query()->firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(self::PERMS);
        app(SettingService::class)->set('invoices', 'segregation', 'false');
        Sanctum::actingAs($this->user);
    }

    private function h(array $x = []): array
    {
        return ['X-Branch-Id' => $this->branch->public_id] + $x;
    }

    private function issued(int $amount = 100000): Invoice
    {
        $s = app(InvoiceService::class);
        $i = $s->create([
            'type' => 'customer_invoice', 'party_type' => 'customer', 'party_id' => $this->customer()->public_id,
            'issue_date' => now()->toDateString(), 'currency' => 'EGP',
            'lines' => [['account_id' => $this->account('4100')->public_id, 'credit_minor' => $amount]],
        ], $this->branch, $this->user->id);
        $s->submit($i, $this->user->id);
        $s->approve($i, $this->user->id);

        return $s->issue($i, $this->user->id);
    }

    private function collect(Invoice $i, int $amount)
    {
        $cash = $this->account('1110')->public_id;
        $method = PaymentMethod::query()->firstOrCreate(['code' => 'ADV'], [
            'name_ar' => 'نقد', 'name_en' => 'Cash', 'type' => 'cash', 'is_active' => true,
        ]);

        return $this->withHeaders($this->h())->postJson("/api/v1/invoices/{$i->public_id}/collections", ['receipts' => [[
            'amount_minor' => $amount, 'cash_account_id' => $cash,
            'payment_method_id' => $method->public_id, 'paid_at' => now()->toDateString(),
        ]]]);
    }

    private function ledgerBalanced(): void
    {
        $r = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')->selectRaw('COALESCE(SUM(jl.base_debit_minor),0) d, COALESCE(SUM(jl.base_credit_minor),0) c')->first();
        $this->assertSame((int) $r->d, (int) $r->c, 'ledger out of balance');
    }

    public function test_issued_invoice_cannot_be_edited_reissued_or_voided_twice(): void
    {
        $i = $this->issued();
        $res = [];
        $res['edit amount after issue'] = $this->withHeaders($this->h())->patchJson("/api/v1/invoices/{$i->public_id}", [
            'lines' => [['account_id' => $this->account('4100')->public_id, 'credit_minor' => 1]],
        ])->status();
        $res['issue again'] = $this->withHeaders($this->h())->postJson("/api/v1/invoices/{$i->public_id}/issue")->status();
        $res['submit again'] = $this->withHeaders($this->h())->postJson("/api/v1/invoices/{$i->public_id}/submit")->status();
        $bad = array_filter($res, fn ($s) => $s < 400 || $s >= 500);
        $this->assertSame([], $bad, 'accepted/crashed: '.json_encode($bad));
        $this->assertSame(100000, (int) $i->fresh()->total_minor);
        $this->ledgerBalanced();
    }

    public function test_void_after_payment_and_double_void_are_refused(): void
    {
        $i = $this->issued();
        $this->collect($i, 40000)->assertCreated();
        $first = $this->withHeaders($this->h())->postJson("/api/v1/invoices/{$i->public_id}/void");
        $this->assertGreaterThanOrEqual(400, $first->status(), 'void of a part-paid invoice was accepted');
        $this->assertSame('issued', $i->fresh()->status);

        $j = $this->issued(5000);
        $this->withHeaders($this->h())->postJson("/api/v1/invoices/{$j->public_id}/void");
        $second = $this->withHeaders($this->h())->postJson("/api/v1/invoices/{$j->public_id}/void");
        $this->assertTrue($second->status() >= 400 && $second->status() < 500, 'double void => '.$second->status());
        $this->ledgerBalanced();
    }

    public function test_over_collection_is_refused_and_exact_amount_settles(): void
    {
        $i = $this->issued(100000);
        $over = $this->collect($i, 100001);
        $this->assertTrue($over->status() >= 400 && $over->status() < 500, 'over-collection => '.$over->status());
        $this->collect($i, 60000)->assertCreated();
        $again = $this->collect($i, 60000);
        $this->assertTrue($again->status() >= 400 && $again->status() < 500, 'second collection beyond outstanding => '.$again->status());
        $this->collect($i, 40000)->assertCreated();
        $this->assertSame(0, (int) $i->fresh()->outstanding_minor);
        $paid = $this->collect($i, 1);
        $this->assertTrue($paid->status() >= 400 && $paid->status() < 500, 'collection on settled invoice => '.$paid->status());
        $this->ledgerBalanced();
    }

    public function test_credit_note_larger_than_invoice_or_twice_is_refused(): void
    {
        $i = $this->issued(100000);
        $r = [];
        foreach (['bigger than invoice' => -100001, 'much bigger' => 9000000000] as $name => $total) {
            $r[$name] = $this->withHeaders($this->h())->postJson("/api/v1/invoices/{$i->public_id}/credit-note", ['total_minor' => $total])->status();
        }
        $bad = array_filter($r, fn ($s) => $s < 400 || $s >= 500);
        $this->assertSame([], $bad, 'credit note accepted/crashed: '.json_encode($bad));
        $ok = $this->withHeaders($this->h())->postJson("/api/v1/invoices/{$i->public_id}/credit-note", ['total_minor' => -100000]);
        $again = $this->withHeaders($this->h())->postJson("/api/v1/invoices/{$i->public_id}/credit-note", ['total_minor' => -100000]);
        $this->assertTrue($ok->status() < 300);
        $this->assertTrue($again->status() >= 400 && $again->status() < 500, 'second full credit note => '.$again->status());
        $this->ledgerBalanced();
    }

    public function test_journal_entry_cannot_be_posted_deleted_or_reversed_twice(): void
    {
        $body = ['entry_date' => now()->toDateString(), 'currency' => 'EGP', 'post' => true, 'lines' => [
            ['account_id' => $this->account('1110')->public_id, 'debit_minor' => 700],
            ['account_id' => $this->account('4100')->public_id, 'credit_minor' => 700],
        ]];
        $id = $this->withHeaders($this->h())->postJson('/api/v1/journal-entries', $body)->assertCreated()->json('data.id');
        $r = [];
        $r['post again'] = $this->withHeaders($this->h())->postJson("/api/v1/journal-entries/{$id}/post")->status();
        $r['delete posted'] = $this->withHeaders($this->h())->deleteJson("/api/v1/journal-entries/{$id}")->status();
        $this->withHeaders($this->h())->postJson("/api/v1/journal-entries/{$id}/reverse", ['reason' => 'x'])->status();
        $r['reverse twice'] = $this->withHeaders($this->h())->postJson("/api/v1/journal-entries/{$id}/reverse", ['reason' => 'x'])->status();
        $bad = array_filter($r, fn ($s) => $s < 400 || $s >= 500);
        $this->assertSame([], $bad, 'accepted/crashed: '.json_encode($bad));
        $this->ledgerBalanced();
    }

    public function test_closed_period_blocks_new_entries_and_payments(): void
    {
        $i = $this->issued();
        FiscalPeriod::query()->update(['status' => 'closed']);
        $je = $this->withHeaders($this->h())->postJson('/api/v1/journal-entries', ['entry_date' => now()->toDateString(), 'currency' => 'EGP', 'post' => true, 'lines' => [
            ['account_id' => $this->account('1110')->public_id, 'debit_minor' => 5],
            ['account_id' => $this->account('4100')->public_id, 'credit_minor' => 5],
        ]]);
        $this->assertTrue($je->status() >= 400 && $je->status() < 500, 'journal in closed period => '.$je->status());
        $pay = $this->collect($i, 100);
        $this->assertTrue($pay->status() >= 400 && $pay->status() < 500, 'payment in closed period => '.$pay->status());
        $this->assertSame(1, JournalEntry::query()->where('status', 'posted')->count());
    }

    public function test_replayed_idempotency_key_creates_one_record_and_a_changed_body_conflicts(): void
    {
        $cash = $this->account('1110')->public_id;
        $body = ['customer_id' => $this->customer()->public_id, 'branch_id' => $this->branch->public_id,
            'cash_account_id' => $cash, 'amount_minor' => 2500, 'currency' => 'EGP', 'method' => 'cash'];
        $a = $this->withHeaders($this->h(['Idempotency-Key' => 'adv-1']))->postJson('/api/v1/payments', $body);
        $b = $this->withHeaders($this->h(['Idempotency-Key' => 'adv-1']))->postJson('/api/v1/payments', $body);
        $c = $this->withHeaders($this->h(['Idempotency-Key' => 'adv-1']))->postJson('/api/v1/payments', ['amount_minor' => 9999] + $body);
        $this->assertSame(1, Payment::query()->count(), 'duplicate payment created by replay');
        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertTrue($c->status() >= 400 && $c->status() < 500, 'changed body under same key => '.$c->status());
        $this->ledgerBalanced();
    }
}
