<?php

namespace Tests\Feature;

use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class MultiCurrencyPostingTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
    }

    private function request(array $lines): PostingRequest
    {
        return PostingRequest::make(now(), $this->branch, 'EGP', $lines, 'mixed', actorId: $this->user->id);
    }

    public function test_lines_in_different_currencies_balance_in_the_base_currency(): void
    {
        $entry = app(PostingService::class)->post($this->request([
            PostingLine::debit($this->account('1120'), 520000)->in('EGP', '1'),
            PostingLine::credit($this->account('1110'), 10000)->in('USD', '50'),
            PostingLine::credit($this->account('7900'), 20000),
        ]));

        $this->assertSame(['EGP', 'USD', 'EGP'], $entry->lines->pluck('currency_code')->all());
        $this->assertSame(520000, (int) $entry->lines->sum('base_debit_minor'));
        $this->assertSame(520000, (int) $entry->lines->sum('base_credit_minor'));
        $this->assertSame(520000, $entry->totalMinor(), 'a mixed entry is totalled in the base currency');
    }

    public function test_a_mixed_currency_entry_that_does_not_balance_in_base_is_rejected(): void
    {
        $this->expectException(DomainException::class);

        app(PostingService::class)->post($this->request([
            PostingLine::debit($this->account('1120'), 520000)->in('EGP', '1'),
            PostingLine::credit($this->account('1110'), 10000)->in('USD', '50'),
        ]));
    }
}
