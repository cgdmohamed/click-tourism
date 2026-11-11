<?php

namespace App\Modules\Core\Services;

use App\Modules\Accounting\Models\FiscalYear;
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
        $year = $this->fiscalYearNumber($date);

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
        } elseif (str_starts_with($documentType, 'invoice.')) {
            // Older invoice sequences fell through to the generic `IN`
            // prefix. Preserve their next value but heal the prefix so future
            // documents use their configured series (INV, BIL, CN, ...).
            $expectedPrefix = $this->prefixFor($documentType);
            if ($sequence->prefix === 'IN' && $expectedPrefix !== 'IN') {
                $sequence->prefix = $expectedPrefix;
                $sequence->save();
            }
        }

        $value = (int) $sequence->next_value;
        $sequence->next_value = $value + 1;
        $sequence->save();

        // Format: PREFIX-BRANCHCODE-YEAR-000001. The branch code keeps numbers
        // unique across branches (sequences are per branch). Numbers issued
        // before the branch code was added are never rewritten.
        return sprintf('%s-%s-%d-%06d', $sequence->prefix ?: $this->fallbackPrefix($documentType), $this->branchCode($branch), $year, $value);
    }

    private function prefixFor(string $documentType): string
    {
        if (str_starts_with($documentType, 'invoice.')) {
            $series = strtoupper(trim(substr($documentType, strlen('invoice.'))));

            return $series !== '' ? $series : 'INV';
        }

        $key = match ($documentType) {
            'journal' => 'journal_prefix',
            'booking' => 'booking_prefix',
            'payment' => 'payment_prefix',
            'refund' => 'refund_prefix',
            'supplier_payment' => 'supplier_payment_prefix',
            'transfer' => 'transfer_prefix',
            'expense' => 'expense_prefix',
            'schedule' => 'schedule_prefix',
            'asset' => 'asset_prefix',
            'depreciation_run' => 'depreciation_run_prefix',
            default => null,
        };

        return $key ? (string) $this->settings->get('documents', $key, $this->fallbackPrefix($documentType)) : $this->fallbackPrefix($documentType);
    }

    /**
     * Voucher series (RCV, PAY, TV, ADJ, RF, DOC) are passed in as the document
     * type and already are the configured prefix: use them in full. Other
     * unknown types keep the legacy two-letter abbreviation.
     */
    private function fallbackPrefix(string $documentType): string
    {
        if (preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $documentType) === 1) {
            return $documentType;
        }

        return strtoupper(substr($documentType, 0, 2));
    }

    private function branchCode(Branch $branch): string
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $branch->code));

        return $code !== '' ? $code : 'B'.$branch->id;
    }

    private function fiscalYearNumber(Carbon $date): int
    {
        $fiscalYear = FiscalYear::query()
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->orderByDesc('starts_on')
            ->first();

        return (int) ($fiscalYear?->starts_on?->year ?? $date->year);
    }

    public function ensure(string $documentType, Branch $branch, int $year, string $prefix): Sequence
    {
        return DB::transaction(fn () => Sequence::query()->firstOrCreate(
            ['document_type' => $documentType, 'branch_id' => $branch->id, 'year' => $year],
            ['prefix' => $prefix, 'next_value' => 1, 'gap_policy' => 'allow'],
        ));
    }
}
