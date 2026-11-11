<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Core\Services\SettingService;
use Illuminate\Support\Carbon;

/**
 * Suggests the next fiscal year from `accounting.fiscal_year_start` (MM-DD).
 */
class FiscalYearSuggestionService
{
    public function __construct(private readonly SettingService $settings) {}

    /** Configured start as MM-DD; falls back to 01-01 when unset or invalid. */
    public function configuredStart(): string
    {
        $value = (string) $this->settings->get('accounting', 'fiscal_year_start', '01-01');

        return preg_match('/^(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[1], (int) $m[2], 2001)
            ? $value
            : '01-01';
    }

    /** @return array{name: string, starts_on: string, ends_on: string, fiscal_year_start: string} */
    public function suggest(): array
    {
        $monthDay = $this->configuredStart();
        $latestEnd = FiscalYear::query()->max('ends_on');

        if ($latestEnd === null) {
            $start = Carbon::parse(now()->year.'-'.$monthDay)->startOfDay();
        } else {
            // First configured start strictly after the latest existing year's end.
            $end = Carbon::parse($latestEnd)->startOfDay();
            $start = Carbon::parse($end->year.'-'.$monthDay)->startOfDay();
            if ($start->lessThanOrEqualTo($end)) {
                $start = $start->addYear();
            }
        }

        $startsOn = $start->toDateString();
        $endsOn = $this->endFor($startsOn);

        return [
            'name' => $this->nameFor($startsOn, $endsOn),
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'fiscal_year_start' => $monthDay,
        ];
    }

    /** The day before the same date one year later. */
    public function endFor(string $startsOn): string
    {
        return Carbon::parse($startsOn)->startOfDay()->addYear()->subDay()->toDateString();
    }

    public function nameFor(string $startsOn, string $endsOn): string
    {
        $startYear = Carbon::parse($startsOn)->year;
        $endYear = Carbon::parse($endsOn)->year;

        return $startYear === $endYear ? (string) $startYear : $startYear.'/'.$endYear;
    }
}
