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
        public readonly ?string $currency = null,
        public readonly ?string $fxRate = null,
        public readonly int $baseDebitMinor = 0,
        public readonly int $baseCreditMinor = 0,
    ) {}

    /**
     * Base-only adjustment (period-end revaluation): the transaction amount is
     * zero and only the base-currency amount moves. The line keeps the
     * account's foreign currency and the closing rate for traceability, so
     * native balances never change.
     */
    public static function baseOnly(
        Account $account,
        int $baseDebitMinor,
        int $baseCreditMinor,
        string $currency,
        string $fxRate,
        ?string $partyType = null,
        ?int $partyId = null,
        ?string $description = null,
    ): self {
        return new self(
            $account, 0, 0, $partyType, $partyId, $description,
            strtoupper($currency), $fxRate, $baseDebitMinor, $baseCreditMinor,
        );
    }

    public function isBaseOnly(): bool
    {
        return $this->debitMinor === 0 && $this->creditMinor === 0
            && ($this->baseDebitMinor !== 0 || $this->baseCreditMinor !== 0);
    }

    /** The same line expressed in its own currency (multi-currency entries). */
    public function in(string $currency, string $fxRate): self
    {
        return new self(
            $this->account, $this->debitMinor, $this->creditMinor, $this->partyType,
            $this->partyId, $this->description, strtoupper($currency), $fxRate,
            $this->baseDebitMinor, $this->baseCreditMinor,
        );
    }

    public static function debit(Account $account, int $amountMinor, ?string $partyType = null, ?int $partyId = null, ?string $description = null): self
    {
        return new self($account, $amountMinor, 0, $partyType, $partyId, $description);
    }

    public static function credit(Account $account, int $amountMinor, ?string $partyType = null, ?int $partyId = null, ?string $description = null): self
    {
        return new self($account, 0, $amountMinor, $partyType, $partyId, $description);
    }
}
