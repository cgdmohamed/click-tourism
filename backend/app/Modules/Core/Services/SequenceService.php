<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Sequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SequenceService
{
    public function __construct(private readonly SettingService $settings) {}

    /**
     * Allocate the next document number for a branch/year. Runs inside the
     * caller's transaction and locks the sequence row to avoid duplicates.
     */
    public function next(string $documentType, Branch $branch, ?Carbon $date = null): string
    {
        $date ??= Carbon::now();
        $year = (int) $date->year;

        $sequence = Sequence::query()
            ->where('document_type', $documentType)
            ->where('branch_id', $branch->id)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            $prefix = $this->prefixFor($documentType);
            $sequence = Sequence::query()->create([
                'document_type' => $documentType,
                'branch_id' => $branch->id,
                'year' => $year,
                'prefix' => $prefix,
                'next_value' => 1,
                'gap_policy' => 'allow',
            ]);
            $sequence = Sequence::query()->lockForUpdate()->find($sequence->id);
        }

        $value = (int) $sequence->next_value;
        $sequence->next_value = $value + 1;
        $sequence->save();

        return sprintf('%s-%d-%06d', $sequence->prefix ?: strtoupper(substr($documentType, 0, 2)), $year, $value);
    }

    private function prefixFor(string $documentType): string
    {
        $key = match ($documentType) {
            'journal' => 'journal_prefix',
            'booking' => 'booking_prefix',
            'payment' => 'payment_prefix',
            'refund' => 'refund_prefix',
            'supplier_payment' => 'supplier_payment_prefix',
            'transfer' => 'transfer_prefix',
            'expense' => 'expense_prefix',
            'schedule' => 'schedule_prefix',
            default => null,
        };

        return $key ? (string) $this->settings->get('documents', $key, strtoupper(substr($documentType, 0, 2))) : strtoupper(substr($documentType, 0, 2));
    }

    public function ensure(string $documentType, Branch $branch, int $year, string $prefix): Sequence
    {
        return DB::transaction(fn () => Sequence::query()->firstOrCreate(
            ['document_type' => $documentType, 'branch_id' => $branch->id, 'year' => $year],
            ['prefix' => $prefix, 'next_value' => 1, 'gap_policy' => 'allow'],
        ));
    }
}
