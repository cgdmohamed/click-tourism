<?php

namespace App\Console\Commands;

use App\Modules\Bookings\Services\CostAccrualService;
use Illuminate\Console\Command;

class ReverseCostAccruals extends Command
{
    protected $signature = 'accounting:reverse-cost-accruals';

    protected $description = 'Reverse supplier-cost accruals whose next period has begun (DEC-002)';

    public function handle(CostAccrualService $accruals): int
    {
        $this->info('Reversals posted: '.$accruals->postDueReversals());

        return self::SUCCESS;
    }
}
