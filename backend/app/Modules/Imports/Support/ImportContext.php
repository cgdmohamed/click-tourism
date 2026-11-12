<?php

namespace App\Modules\Imports\Support;

use App\Modules\Core\Models\Branch;

/** Immutable inputs for one processing pass. */
final class ImportContext
{
    /**
     * @param  list<int>  $allowedBranchIds  branches the importing user may touch
     * @param  array<string, mixed>  $options  entity-specific options (e.g. as_of_date)
     */
    public function __construct(
        public readonly ?Branch $branch,
        public readonly ?int $userId,
        public readonly array $allowedBranchIds,
        public readonly array $options,
        public readonly string $policy,
        public readonly bool $apply,
        public readonly string $jobPublicId,
        public readonly string $baseCurrency,
        public readonly Caster $caster,
    ) {}
}
