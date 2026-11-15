<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\SettingService;
use App\Modules\Invoices\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

/**
 * Hostile inputs an accountant or owner might try. A test fails when the system
 * ACCEPTS something it should refuse, or answers with a 500 instead of a clean 4xx.
 */
class AdversarialAccountingTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'invoices.view', 'invoices.create', 'invoices.approve', 'journal.view', 'journal.create',
        'journal.reverse', 'payments.create', 'payments.view', 'payments.reverse',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->givePermissionTo(self::PERMISSIONS);
        app(SettingService::class)->set('invoices', 'segregation', 'false');
        Sanctum::actingAs($this->user);
    }

    private function h(): array
    {
        return ['X-Branch-Id' => $this->branch->public_id];
    }

    private function invoiceBody(array $line = [], array $over = []): array
    {
        return $over + [
            'type' => 'customer_invoice', 'party_type' => 'customer',
            'party_id' => $this->customer()->public_id,
            'issue_date' => now()->toDateString(), 'currency' => 'EGP',
            'lines' => [$line + [
                'account_id' => $this->account('4100')->public_id,
                'description' => 'Tour', 'credit_minor' => 100000,
            ]],
        ];
    }

    private function entry(array $lines, array $over = []): array
    {
        return $over + [
            'entry_date' => now()->toDateString(), 'currency' => 'EGP', 'lines' => $lines,
        ];
    }

    private function dr(string $code, int $n): array
    {
        return ['account_id' => $this->account($code)->public_id, 'debit_minor' => $n];
    }

    private function cr(string $code, int $n): array
    {
        return ['account_id' => $this->account($code)->public_id, 'credit_minor' => $n];
    }

    /** @return array<string,array{0:string,1:array}> */
    public static function badJournals(): array
    {
        return [];
    }

    public function test_journal_entries_that_must_be_refused(): void
    {
        $bad = [
            'unbalanced' => $this->entry([$this->dr('1110', 100), $this->cr('4100', 99)], ['post' => true]),
            'negative debit' => $this->entry([$this->dr('1110', -100), $this->cr('4100', -100)], ['post' => true]),
            'zero lines' => $this->entry([$this->dr('1110', 0), $this->cr('4100', 0)], ['post' => true]),
            'both sides' => $this->entry([
                ['account_id' => $this->account('1110')->public_id, 'debit_minor' => 5, 'credit_minor' => 5],
                $this->cr('4100', 0),
            ], ['post' => true]),
            'fx rate zero' => $this->entry([$this->dr('1110', 100), $this->cr('4100', 100)], ['currency' => 'USD', 'fx_rate' => '0', 'post' => true]),
            'fx rate negative' => $this->entry([$this->dr('1110', 100), $this->cr('4100', 100)], ['currency' => 'USD', 'fx_rate' => '-50', 'post' => true]),
            'fx rate text' => $this->entry([$this->dr('1110', 100), $this->cr('4100', 100)], ['currency' => 'USD', 'fx_rate' => 'abc', 'post' => true]),
            'unknown currency' => $this->entry([$this->dr('1110', 100), $this->cr('4100', 100)], ['currency' => 'ZZZ', 'post' => true]),
            'overflow amount' => $this->entry([$this->dr('1110', PHP_INT_MAX), $this->cr('4100', PHP_INT_MAX)], ['post' => true]),
            'unknown account' => $this->entry([['account_id' => 'nope', 'debit_minor' => 5], $this->cr('4100', 5)], ['post' => true]),
            'far future date' => $this->entry([$this->dr('1110', 5), $this->cr('4100', 5)], ['entry_date' => '2999-01-01', 'post' => true]),
            'ancient date' => $this->entry([$this->dr('1110', 5), $this->cr('4100', 5)], ['entry_date' => '1900-01-01', 'post' => true]),
            'float amount' => $this->entry([['account_id' => $this->account('1110')->public_id, 'debit_minor' => 10.5], $this->cr('4100', 10)], ['post' => true]),
        ];

        $before = JournalEntry::query()->count();
        $accepted = [];
        foreach ($bad as $name => $payload) {
            $r = $this->withHeaders($this->h())->postJson('/api/v1/journal-entries', $payload);
            if ($r->status() < 400 || $r->status() >= 500) {
                $accepted[] = "$name => HTTP {$r->status()}";
            }
        }
        $this->assertSame([], $accepted, 'Journal entries wrongly accepted or crashed: '.implode('; ', $accepted));
        $this->assertSame($before, JournalEntry::query()->count());
    }

    public function test_invoices_that_must_be_refused(): void
    {
        $bad = [
            'negative credit' => $this->invoiceBody(['credit_minor' => -500]),
            'zero total' => $this->invoiceBody(['credit_minor' => 0]),
            'both sides' => $this->invoiceBody(['credit_minor' => 500, 'debit_minor' => 500]),
            'negative tax' => $this->invoiceBody(['tax_minor' => -1000]),
            'tax bigger than amount' => $this->invoiceBody(['tax_minor' => 999999999]),
            'overflow' => $this->invoiceBody(['credit_minor' => PHP_INT_MAX]),
            'string amount' => $this->invoiceBody(['credit_minor' => 'abc']),
            'unknown currency' => $this->invoiceBody([], ['currency' => 'ZZZ']),
            'unknown party' => $this->invoiceBody([], ['party_id' => 'does-not-exist']),
            'no lines' => $this->invoiceBody([], ['lines' => []]),
            'unknown account' => $this->invoiceBody(['account_id' => 'nope']),
            'status injection' => $this->invoiceBody([], ['status' => 'issued', 'paid_minor' => 100000]),
        ];

        $accepted = [];
        foreach ($bad as $name => $payload) {
            $r = $this->withHeaders($this->h())->postJson('/api/v1/invoices', $payload);
            if ($r->status() >= 500) {
                $accepted[] = "$name => CRASH {$r->status()}";
            } elseif ($r->status() < 400) {
                // Accepting the payload is only a problem if the hostile field took effect.
                $inv = Invoice::query()->latest('id')->first();
                if ($name === 'status injection' && $inv && $inv->status !== 'draft') {
                    $accepted[] = "$name => created as {$inv->status}";
                } elseif ($name !== 'status injection') {
                    $accepted[] = "$name => HTTP {$r->status()}";
                }
            }
        }
        $this->assertSame([], $accepted, 'Invoices wrongly accepted or crashed: '.implode('; ', $accepted));
    }

    public function test_receipts_that_must_be_refused(): void
    {
        $cash = $this->account('1110')->public_id;
        $base = [
            'customer_id' => $this->customer()->public_id, 'branch_id' => $this->branch->public_id,
            'cash_account_id' => $cash, 'amount_minor' => 1000, 'currency' => 'EGP', 'method' => 'cash',
            'paid_at' => now()->toIso8601String(),
        ];
        $bad = [
            'negative' => ['amount_minor' => -5] + $base,
            'zero' => ['amount_minor' => 0] + $base,
            'overflow' => ['amount_minor' => PHP_INT_MAX] + $base,
            'fx zero' => ['currency' => 'USD', 'fx_rate' => '0'] + $base,
            'fx negative' => ['currency' => 'USD', 'fx_rate' => '-3'] + $base,
            'fx text' => ['currency' => 'USD', 'fx_rate' => 'x'] + $base,
            'unknown customer' => ['customer_id' => 'ghost'] + $base,
            'revenue account as cash' => ['cash_account_id' => $this->account('4100')->public_id] + $base,
            'unknown account' => ['cash_account_id' => 'ghost'] + $base,
            'future year' => ['paid_at' => '2999-01-01'] + $base,
        ];

        $accepted = [];
        foreach ($bad as $name => $payload) {
            try {
                $r = $this->withHeaders($this->h())->postJson('/api/v1/payments', $payload);
            } catch (\Throwable $e) {
                $accepted[] = "$name => EXCEPTION ".class_basename($e).': '.mb_substr($e->getMessage(), 0, 80);

                continue;
            }
            if ($r->status() < 400 || $r->status() >= 500) {
                $accepted[] = "$name => HTTP {$r->status()}";
            }
        }
        $this->assertSame([], $accepted, 'Receipts wrongly accepted or crashed: '.implode('; ', $accepted));
    }

    public function test_the_ledger_is_still_balanced_after_all_the_abuse(): void
    {
        $this->test_receipts_that_must_be_refused();
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor),0) d, COALESCE(SUM(jl.base_credit_minor),0) c')->first();
        $this->assertSame((int) $row->d, (int) $row->c);
    }

    public function test_actions_without_permission_are_forbidden(): void
    {
        $limited = User::factory()->create();
        $limited->branches()->syncWithoutDetaching([$this->branch->id]);
        Sanctum::actingAs($limited);
        $id = $this->customer()->public_id;

        $calls = [
            ['post', '/api/v1/invoices', $this->invoiceBody()],
            ['post', '/api/v1/journal-entries', $this->entry([$this->dr('1110', 1), $this->cr('4100', 1)])],
            ['post', '/api/v1/payments', ['customer_id' => $id]],
            ['get', '/api/v1/invoices', []],
            ['get', '/api/v1/journal-entries', []],
            ['get', '/api/v1/payments', []],
            ['post', '/api/v1/fiscal-periods', []],
            ['post', '/api/v1/opening-balances', []],
        ];
        $open = [];
        foreach ($calls as [$m, $url, $body]) {
            $r = $this->withHeaders($this->h())->json(strtoupper($m), $url, $body);
            if ($r->status() !== 403) {
                $open[] = "$m $url => {$r->status()}";
            }
        }
        $this->assertSame([], $open, 'Reachable without permission: '.implode('; ', $open));
    }
}
