<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Core\Models\Branch;
use Illuminate\Support\Carbon;

/**
 * Intent to post a balanced journal entry (ADR-0004 §2).
 * Business modules build this DTO; only PostingService writes journals.
 */
final class PostingRequest
{
    /**
     * @param  PostingLine[]  $lines
     */
    public function __construct(
        public readonly string $entryDate,
        public readonly Branch $branch,
        public readonly string $currency,
        public readonly string $fxRate,
        public readonly string $fxRateDate,
        public readonly string $fxRateSource,
        public readonly array $lines,
        public readonly ?string $memo = null,
        public readonly ?string $sourceType = null,
        public readonly ?int $sourceId = null,
        public readonly ?string $event = null,
        public readonly ?int $actorId = null,
    ) {}

    /**
     * @param  PostingLine[]  $lines
     */
    public static function make(
        string|Carbon $entryDate,
        Branch $branch,
        string $currency,
        array $lines,
        ?string $memo = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $event = null,
        ?string $fxRate = null,
        string|Carbon|null $fxRateDate = null,
        string $fxRateSource = 'manual',
        ?int $actorId = null,
    ): self {
        $date = $entryDate instanceof Carbon ? $entryDate->toDateString() : $entryDate;

        return new self(
            entryDate: $date,
            branch: $branch,
            currency: strtoupper($currency),
            fxRate: $fxRate ?? '1',
            fxRateDate: $fxRateDate instanceof Carbon ? $fxRateDate->toDateString() : ($fxRateDate ?? $date),
            fxRateSource: $fxRateSource,
            lines: $lines,
            memo: $memo,
            sourceType: $sourceType,
            sourceId: $sourceId,
            event: $event,
            actorId: $actorId,
        );
    }
}
