<?php

namespace App\Modules\Commissions\Models;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Core\Models\Branch;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** A commission accrual for one booking and one beneficiary (base currency, integer minor units). */
class Commission extends Model
{
    use HasPublicId;

    protected $fillable = [
        'number', 'booking_id', 'branch_id', 'commission_rule_id', 'rule_code', 'rule_version',
        'beneficiary_type', 'beneficiary_id', 'basis', 'rate_bp', 'basis_amount_minor',
        'calculated_minor', 'override_minor', 'override_reason', 'override_by', 'amount_minor',
        'paid_minor', 'currency_code', 'status', 'active_key', 'accrued_on', 'created_by',
        'approved_by', 'approved_at', 'rejection_reason', 'journal_entry_id',
        'reversal_journal_entry_id', 'reversed_at', 'reversal_reason', 'needs_reversal',
    ];

    protected function casts(): array
    {
        return [
            'rate_bp' => 'integer',
            'basis_amount_minor' => 'integer',
            'calculated_minor' => 'integer',
            'override_minor' => 'integer',
            'amount_minor' => 'integer',
            'paid_minor' => 'integer',
            'beneficiary_id' => 'integer',
            'accrued_on' => 'date',
            'approved_at' => 'datetime',
            'reversed_at' => 'datetime',
            'needs_reversal' => 'boolean',
        ];
    }

    public static function activeKey(int $bookingId, string $type, int $beneficiaryId): string
    {
        return "{$bookingId}:{$type}:{$beneficiaryId}";
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function rule()
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function payments()
    {
        return $this->hasMany(CommissionPayment::class)->orderBy('id');
    }

    public function outstandingMinor(): int
    {
        return $this->status === 'posted' ? $this->amount_minor - $this->paid_minor : 0;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'booking_id' => $this->booking?->public_id,
            'booking_number' => $this->booking?->number,
            'branch_id' => $this->branch?->public_id,
            'rule_id' => $this->rule?->public_id,
            'rule_code' => $this->rule_code,
            'rule_version' => $this->rule_version,
            'beneficiary_type' => $this->beneficiary_type,
            'beneficiary' => CommissionRule::describeBeneficiary($this->beneficiary_type, $this->beneficiary_id),
            'basis' => $this->basis,
            'rate_bp' => $this->rate_bp,
            'basis_amount_minor' => $this->basis_amount_minor,
            'calculated_minor' => $this->calculated_minor,
            'override_minor' => $this->override_minor,
            'override_reason' => $this->override_reason,
            'amount_minor' => $this->amount_minor,
            'paid_minor' => $this->paid_minor,
            'outstanding_minor' => $this->outstandingMinor(),
            'currency' => $this->currency_code,
            'status' => $this->status,
            'accrued_on' => $this->accrued_on?->toDateString(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'journal_entry_id' => $this->journalEntry?->public_id,
            'reversal_journal_entry_id' => $this->reversalJournalEntry?->public_id,
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversal_reason' => $this->reversal_reason,
            'needs_reversal' => (bool) $this->needs_reversal,
        ];
    }
}
