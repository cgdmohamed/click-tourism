<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;

/**
 * One side of a journal entry. Exactly one of debit/credit is non-zero,
 * except for the synthetic FX-rounding line where transaction amounts are
 * zero and only the base amounts carry the residual (ADR-0003 §6).
 */
final class PostingLine
{
    public function __construct(
        public readonly Account $account,
        public readonly int $debitMinor = 0,
        public readonly int $creditMinor = 0,
        public readonly ?string $partyType = null,
        public readonly ?int $partyId = null,
        public readonly ?string $description = null,
    ) {}

    public static function debit(Account $account, int $amountMinor, ?string $partyType = null, ?int $partyId = null, ?string $description = null): self
    {
        return new self($account, $amountMinor, 0, $partyType, $partyId, $description);
    }

    public static function credit(Account $account, int $amountMinor, ?string $partyType = null, ?int $partyId = null, ?string $description = null): self
    {
        return new self($account, 0, $amountMinor, $partyType, $partyId, $description);
    }
}
