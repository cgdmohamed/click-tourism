<?php

namespace Tests\Feature;

use App\Modules\Money\Models\FxRate;
use App\Modules\Money\Services\FxService;
use App\Support\Money;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class FxServiceTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccounting();
    }

    private function fx(): FxService
    {
        return app(FxService::class);
    }

    private function assertDecimalEquals(string $expected, mixed $actual): void
    {
        $this->assertSame(0, bccomp($expected, (string) $actual, 10), "Expected {$expected}, got {$actual}.");
    }

    public function test_the_base_currency_always_resolves_to_one(): void
    {
        $this->assertSame('1', $this->fx()->rateFor('EGP', Carbon::now()->toDateString()));
        $this->assertSame(
            ['rate' => '1', 'date' => Carbon::now()->toDateString(), 'source' => 'base'],
            $this->fx()->snapshot('EGP'),
        );
    }

    public function test_it_resolves_the_latest_rate_on_or_before_the_date(): void
    {
        $fx = $this->fx();
        $fx->upsertRate('USD', '48.0', Carbon::now()->subDays(10)->toDateString());
        $fx->upsertRate('USD', '48.5', Carbon::now()->subDays(2)->toDateString());
        $fx->upsertRate('USD', '50.0', Carbon::now()->addDays(5)->toDateString());

        $this->assertDecimalEquals('48.5', $fx->rateFor('USD', Carbon::now()->toDateString()));
        $this->assertDecimalEquals('48', $fx->rateFor('USD', Carbon::now()->subDays(5)->toDateString()));
    }

    public function test_the_snapshot_records_the_rate_date_and_source(): void
    {
        $date = Carbon::now()->subDay()->toDateString();
        $this->fx()->upsertRate('USD', '48.5', $date, 'manual');

        $snapshot = $this->fx()->snapshot('USD', Carbon::now()->toDateString());

        $this->assertDecimalEquals('48.5', $snapshot['rate']);
        $this->assertSame($date, $snapshot['date']);
        $this->assertSame('manual', $snapshot['source']);
    }

    public function test_a_missing_rate_is_rejected_instead_of_guessed(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No FX rate for USD');

        $this->fx()->rateFor('USD', Carbon::now()->toDateString());
    }

    public function test_upserting_the_same_currency_date_and_source_updates_one_row(): void
    {
        $date = Carbon::now()->toDateString();
        $first = $this->fx()->upsertRate('USD', '48.5', $date);
        $second = $this->fx()->upsertRate('USD', '49.0', $date);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, FxRate::query()->where('currency_code', 'USD')->count());
        $this->assertDecimalEquals('49', $second->refresh()->rate);
    }

    public function test_the_base_currency_cannot_be_stored_as_an_fx_rate(): void
    {
        $this->expectException(DomainException::class);

        $this->fx()->upsertRate('EGP', '1', Carbon::now()->toDateString());
    }

    public function test_a_non_positive_rate_is_rejected(): void
    {
        $this->expectException(DomainException::class);

        $this->fx()->upsertRate('USD', '0', Carbon::now()->toDateString());
    }

    public function test_conversion_uses_the_resolved_rate(): void
    {
        $converted = $this->fx()->convert(new Money(10000, 'USD'), 'EGP', '48.5');

        $this->assertSame(485000, $converted->amountMinor);
        $this->assertSame('EGP', $converted->currency);
    }
}
