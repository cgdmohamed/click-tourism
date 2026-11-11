<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

class FxRevaluationRun extends Model
{
    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSAL_PENDING = 'reversal_pending';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'fiscal_period_id',
        'branch_id',
        'revaluation_entry_id',
        'reversal_entry_id',
        'attempt',
        'status',
        'run_by',
    ];

    public function period()
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function revaluationEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'revaluation_entry_id');
    }

    public function reversalEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }
}
