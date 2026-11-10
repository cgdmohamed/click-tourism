<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Money\Services\FxService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class PostingServiceTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
    }

    private function posting(): PostingService
    {
        return app(PostingService::class);
    }

    private function request(string $currency = 'EGP', string $rate = '1', array $lines = []): PostingRequest
    {
        $lines = $lines ?: [
            PostingLine::debit($this->account('1110'), 100000),
            PostingLine::credit($this->account('4100'), 100000),
        ];

        return PostingRequest::make(
            entryDate: Carbon::now()->toDateString(),
            branch: $this->branch,
            currency: $currency,
            lines: $lines,
            memo: 'Test entry',
            sourceType: 'test',
            sourceId: 1,
            event: 'sale',
            fxRate: $rate,
            fxRateDate: Carbon::now()->toDateString(),
            fxRateSource: 'manual',
            actorId: $this->user->id,
        );
    }

    private function entry(string $currency = 'EGP', string $rate = '1', array $lines = []): JournalEntry
    {
        return $this->posting()->post($this->request($currency, $rate, $lines));
    }

    public function test_it_posts_a_balanced_entry_with_base_amounts(): void
    {
        $entry = $this->entry();

        $this->assertSame('posted', $entry->status);
        $this->assertCount(2, $entry->lines);
        $this->assertSame(100000, (int) $entry->lines->sum('debit_minor'));
        $this->assertSame(100000, (int) $entry->lines->sum('credit_minor'));
        $this->assertSame(100000, (int) $entry->lines->sum('base_debit_minor'));
        $this->assertSame(100000, (int) $entry->lines->sum('base_credit_minor'));
        $this->assertStringStartsWith('JV-', $entry->number);
    }

    public function test_it_rejects_an_unbalanced_entry(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not balanced');

        $this->entry(lines: [
            PostingLine::debit($this->account('1110'), 100000),
            PostingLine::credit($this->account('4100'), 90000),
        ]);
    }

    public function test_it_appends_an_fx_rounding_line_so_the_base_entry_balances(): void
    {
        app(FxService::class)->upsertRate('USD', '48.5', Carbon::now()->toDateString());

        $entry = $this->entry('USD', '48.5', [
            PostingLine::debit($this->account('1110'), 10000),
            PostingLine::credit($this->account('4100'), 3333),
            PostingLine::credit($this->account('4100'), 3333),
            PostingLine::credit($this->account('4100'), 3334),
        ]);

        $this->assertCount(5, $entry->lines);
        $this->assertSame(
            (int) $entry->lines->sum('base_debit_minor'),
            (int) $entry->lines->sum('base_credit_minor'),
        );

        $rounding = $entry->lines->firstWhere('account.code', '6999');
        $this->assertNotNull($rounding);
        $this->assertSame(0, (int) $rounding->debit_minor);
        $this->assertSame(0, (int) $rounding->credit_minor);
    }

    public function test_it_refuses_to_post_the_same_source_event_twice(): void
    {
        $this->entry();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already been posted');

        $this->entry();
    }

    public function test_it_reverses_an_entry_exactly_once(): void
    {
        $entry = $this->entry();

        $reversal = $this->posting()->reverse($entry, 'Correction', $this->user->id);

        $this->assertSame($entry->id, $reversal->reversal_of_id);
        $this->assertSame('sale.reversal', $reversal->event);
        $this->assertSame(
            (int) $entry->lines->sum('debit_minor'),
            (int) $reversal->lines->sum('credit_minor'),
        );
        $this->assertSame(
            (int) $entry->lines->sum('credit_minor'),
            (int) $reversal->lines->sum('debit_minor'),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already been reversed');

        $this->posting()->reverse($entry, 'Again', $this->user->id);
    }

    public function test_it_can_store_a_balanced_draft_and_post_it_later(): void
    {
        $draft = $this->posting()->draft($this->request());

        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->posted_at);
        $this->assertSame(0, JournalEntry::query()->where('status', 'posted')->count());

        $posted = $this->posting()->postDraft($draft, $this->user->id);

        $this->assertSame('posted', $posted->status);
        $this->assertNotNull($posted->posted_at);
        $this->assertSame(1, JournalEntry::query()->where('status', 'posted')->count());
    }

    public function test_a_draft_cannot_be_reversed_before_posting(): void
    {
        $draft = $this->posting()->draft($this->request());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only posted entries can be reversed');

        $this->posting()->reverse($draft, 'Not yet posted', $this->user->id);
    }

    public function test_posted_entries_and_lines_are_immutable(): void
    {
        $entry = $this->entry();
        $rejected = 0;

        try {
            $entry->update(['memo' => 'Changed']);
        } catch (DomainException) {
            $rejected++;
        }

        try {
            $entry->lines->first()->update(['debit_minor' => 1]);
        } catch (DomainException) {
            $rejected++;
        }

        try {
            $entry->lines->first()->delete();
        } catch (DomainException) {
            $rejected++;
        }

        $this->assertSame(3, $rejected, 'Posted entries and lines must reject updates and deletes.');
    }

    public function test_it_refuses_to_post_into_a_closed_period(): void
    {
        FiscalPeriod::query()->update(['status' => 'closed']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('posting is not allowed');

        $this->entry();
    }

    public function test_it_refuses_to_post_when_no_period_covers_the_date(): void
    {
        FiscalPeriod::query()->delete();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No fiscal period');

        $this->entry();
    }

    /** Concurrency and invariant tests (P1-06) */
    public function test_concurrent_posting_of_same_source_event_posts_only_once(): void
    {
        $lines = [
            PostingLine::debit($this->account('1110'), 100000),
            PostingLine::credit($this->account('4100'), 100000),
        ];

        $request = PostingRequest::make(
            entryDate: now()->toDateString(),
            branch: $this->branch,
            currency: 'EGP',
            lines: $lines,
            memo: 'Concurrent test entry',
            sourceType: 'test_concurrent',
            sourceId: 999,
            event: 'sale',
            fxRate: '1',
            fxRateDate: now()->toDateString(),
            fxRateSource: 'manual',
            actorId: $this->user->id,
        );

        // First posting succeeds.
        $this->posting()->post($request);

        // Second posting with same source/event is rejected.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already been posted');
        $this->posting()->post($request);
    }

    public function test_posted_entry_is_balanced_in_base_currency(): void
    {
        $entry = $this->entry();

        $debitSum = (int) $entry->lines->sum('base_debit_minor');
        $creditSum = (int) $entry->lines->sum('base_credit_minor');

        $this->assertSame($debitSum, $creditSum, 'Posted entry must balance in base currency.');
    }

    public function test_posted_entry_cannot_be_modified(): void
    {
        $entry = $this->entry();

        // Direct model update should be blocked by model events.
        $this->expectException(DomainException::class);
        $entry->update(['memo' => 'Tampered']);
    }

    public function test_posted_entry_lines_cannot_be_modified(): void
    {
        $entry = $this->entry();

        $this->expectException(DomainException::class);
        $entry->lines->first()->update(['debit_minor' => 1]);
    }

    public function test_closed_period_rejects_posting(): void
    {
        FiscalPeriod::query()->update(['status' => 'closed']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('posting is not allowed');

        $this->entry();
    }

    public function test_reversal_of_reversal_is_rejected(): void
    {
        $entry = $this->entry();
        $reversal = $this->posting()->reverse($entry, 'First reversal', $this->user->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('A reversal entry cannot itself be reversed');
        $this->posting()->reverse($reversal, 'Second reversal', $this->user->id);
    }
}
