<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Opens the current calendar year with monthly periods. Posting requires an
 * open period covering the entry date (ADR-0004), so a fresh install must
 * have at least the current month open.
 */
class FiscalYearSeeder extends Seeder
{
    public function run(): void
    {
        $year = (int) Carbon::now()->year;
        $startsOn = Carbon::create($year, 1, 1)->toDateString();
        $endsOn = Carbon::create($year, 12, 31)->toDateString();

        $fiscalYear = FiscalYear::query()->firstOrCreate(
            ['starts_on' => $startsOn],
            ['name' => (string) $year, 'ends_on' => $endsOn],
        );

        $cursor = Carbon::create($year, 1, 1)->startOfMonth();

        while ($cursor->year === $year) {
            FiscalPeriod::query()->firstOrCreate(
                ['fiscal_year_id' => $fiscalYear->id, 'starts_on' => $cursor->toDateString()],
                [
                    'name' => $cursor->format('Y-m'),
                    'ends_on' => $cursor->copy()->endOfMonth()->toDateString(),
                    'status' => 'open',
                ],
            );

            $cursor = $cursor->copy()->addMonthNoOverflow()->startOfMonth();
        }
    }
}
