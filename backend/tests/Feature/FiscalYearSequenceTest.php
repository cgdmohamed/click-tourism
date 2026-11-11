<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Core\Services\SequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsAccounting;
use Tests\TestCase;

class FiscalYearSequenceTest extends TestCase
{
    use BuildsAccounting;
    use RefreshDatabase;

    public function test_document_number_uses_the_containing_fiscal_year_start(): void
    {
        $this->buildAccounting();
        FiscalYear::query()->delete();
        FiscalYear::query()->create([
            'name' => 'FY 2026/27',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'status' => 'open',
        ]);

        $number = app(SequenceService::class)->next('booking', $this->branch, Carbon::parse('2027-02-15'));

        $this->assertStringStartsWith('BK-HQ-2026-', $number);
        $this->assertDatabaseHas('sequences', [
            'document_type' => 'booking', 'branch_id' => $this->branch->id, 'year' => 2026,
        ]);
    }
}
