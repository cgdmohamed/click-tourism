<?php

namespace App\Modules\Treasury\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\PostingLine;
use App\Modules\Accounting\Services\PostingRequest;
use App\Modules\Accounting\Services\PostingService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SequenceService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Money\Services\FxService;
use App\Modules\Treasury\Models\CashAccount;
use App\Modules\Treasury\Models\Transfer;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cash/bank account master data and transfers between them.
 *
 * Every GL effect goes through PostingService. Cross-currency transfers are
 * posted in the base currency (the engine has one transaction currency per
 * entry); the foreign amounts are kept on the transfer record (DEC-031).
 */
class TreasuryService
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly SettingService $settings,
        private readonly FxService $fx,
        private readonly SequenceService $sequences,
        private readonly AuditService $audit,
    ) {}

    /**
     * Register a cash/bank account against an existing postable asset account,
     * or create the GL account when `account_code` does not exist yet.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(array $data, ?int $actorId = null): CashAccount
    {
        return DB::transaction(function () use ($data, $actorId) {
            $currency = strtoupper((string) $data['currency']);

            if (! empty($data['account_id'])) {
                $account = Account::query()->where('public_id', $data['account_id'])->firstOrFail();
            } else {
                $code = (string) ($data['account_code'] ?? '');
                $account = Account::query()->where('code', $code)->first()
                    ?? Account::query()->create([
                        'code' => $code,
                        'name_ar' => $data['name_ar'],
                        'name_en' => $data['name_en'],
                        'type' => 'asset',
                        'is_postable' => true,
                        'is_active' => true,
                        'currency_code' => null,
                    ]);
            }

            if ($account->type !== 'asset' || ! $account->is_postable || ! $account->is_active) {
                throw new DomainException("Account {$account->code} must be an active, postable asset account.");
            }

            if ($account->currency_code !== null && strtoupper($account->currency_code) !== $currency) {
                throw new DomainException("Account {$account->code} is restricted to {$account->currency_code}.");
            }

            if (CashAccount::query()->where('account_id', $account->id)->exists()) {
                throw new DomainException("Account {$account->code} is already registered as a cash or bank account.");
            }

            $branch = ! empty($data['branch_id'])
                ? Branch::query()->where('public_id', $data['branch_id'])->firstOrFail()
                : null;

            $cashAccount = CashAccount::query()->create([
                'account_id' => $account->id,
                'branch_id' => $branch?->id,
                'kind' => $data['kind'],
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'],
                'currency_code' => $currency,
                'bank_name' => $data['bank_name'] ?? null,
                'account_last4' => $data['account_last4'] ?? null,
                'is_active' => true,
            ]);

            $this->audit->log('cash_account.created', $cashAccount, null, [
                'account' => $account->code,
                'kind' => $cashAccount->kind,
                'currency' => $currency,
            ], $actorId);

            return $cashAccount->load('account', 'branch');
        });
    }

    /** Posted base-currency balance of the GL account (debit minus credit), optionally for one branch. */
    public function balance(CashAccount $cashAccount, ?Branch $branch = null): int
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jl.account_id', $cashAccount->account_id)
            ->when($branch, fn ($query) => $query->where('jl.branch_id', $branch->id))
            ->selectRaw('COALESCE(SUM(jl.base_debit_minor), 0) as debit, COALESCE(SUM(jl.base_credit_minor), 0) as credit')
            ->first();

        return (int) $row->debit - (int) $row->credit;
    }

    /**
     * Balance in the account's own currency (debit minus credit of the lines
     * posted in that currency), for display next to the account.
     */
    public function nativeBalance(CashAccount $cashAccount, ?Branch $branch = null): int
    {
        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jl.account_id', $cashAccount->account_id)
            ->where('jl.currency_code', strtoupper($cashAccount->currency_code))
            ->when($branch, fn ($query) => $query->where('jl.branch_id', $branch->id))
            ->selectRaw('COALESCE(SUM(jl.debit_minor), 0) as debit, COALESCE(SUM(jl.credit_minor), 0) as credit')
            ->first();

        return (int) $row->debit - (int) $row->credit;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function transfer(array $data, Branch $branch, ?int $actorId = null): Transfer
    {
        $from = CashAccount::query()->with('account')->where('public_id', $data['from_cash_account_id'])->firstOrFail();
        $to = CashAccount::query()->with('account')->where('public_id', $data['to_cash_account_id'])->firstOrFail();

        if ($from->id === $to->id) {
            throw new DomainException('Source and destination must be different accounts.');
        }

        foreach ([$from, $to] as $cashAccount) {
            if (! $cashAccount->is_active) {
                throw new DomainException("Cash account {$cashAccount->name_en} is inactive.");
            }
            if ($cashAccount->branch_id !== null && $cashAccount->branch_id !== $branch->id) {
                throw new DomainException("Cash account {$cashAccount->name_en} belongs to another branch.");
            }
        }

        $amount = (int) $data['amount_minor'];
        $fee = (int) ($data['fee_minor'] ?? 0);

        if ($amount <= 0) {
            throw new DomainException('The transfer amount must be greater than zero.');
        }
        if ($fee < 0) {
            throw new DomainException('The fee cannot be negative.');
        }

        $date = (string) $data['transfer_date'];
        $base = $this->settings->baseCurrency();
        $fromCurrency = strtoupper($from->currency_code);
        $toCurrency = strtoupper($to->currency_code);
        $sameCurrency = $fromCurrency === $toCurrency;

        $fromRate = $this->rate($fromCurrency, $base, $date, $data['from_fx_rate'] ?? null);
        $toRate = $this->rate($toCurrency, $base, $date, $data['to_fx_rate'] ?? null);

        $toAmount = isset($data['to_amount_minor']) ? (int) $data['to_amount_minor'] : null;
        if ($sameCurrency) {
            $toAmount ??= $amount;
            if ($toAmount !== $amount) {
                throw new DomainException('Same-currency transfers must move the same amount; use a fee for bank charges.');
            }
        } elseif ($toAmount === null || $toAmount <= 0) {
            throw new DomainException('A cross-currency transfer needs the amount received (to_amount_minor).');
        }

        return DB::transaction(function () use (
            $branch, $actorId, $data, $from, $to, $amount, $fee, $date, $base, $fromCurrency, $toCurrency,
            $sameCurrency, $fromRate, $toRate, $toAmount,
        ) {
            $feeAccount = $fee > 0 ? $this->posting->account((string) $this->settings->get('accounting', 'bank_charges_account_code', '6500')) : null;

            if ($sameCurrency) {
                $entryCurrency = $fromCurrency;
                $entryRate = $fromRate;
                $outgoingBase = $this->toBase($amount + $fee, $fromCurrency, $base, $fromRate);
                $lines = [
                    PostingLine::debit($to->account, $amount, null, null, 'Transfer in'),
                    PostingLine::credit($from->account, $amount + $fee, null, null, 'Transfer out'),
                ];
                if ($feeAccount) {
                    $lines[] = PostingLine::debit($feeAccount, $fee, null, null, 'Transfer fee');
                }
            } else {
                // Each side keeps its own currency, so the USD account is debited or
                // credited in USD and its per-currency balance stays correct. The
                // entry balances in the base currency; the difference is FX gain/loss.
                $entryCurrency = $base;
                $entryRate = '1';
                $baseFrom = $this->toBase($amount, $fromCurrency, $base, $fromRate);
                $baseTo = $this->toBase($toAmount, $toCurrency, $base, $toRate);
                $baseFee = $this->toBase($fee, $fromCurrency, $base, $fromRate);
                $outgoingBase = $baseFrom + $baseFee;
                $lines = [
                    PostingLine::debit($to->account, $toAmount, null, null, "Transfer in {$toAmount} {$toCurrency}")->in($toCurrency, $toRate),
                    PostingLine::credit($from->account, $amount + $fee, null, null, "Transfer out {$amount} {$fromCurrency}")->in($fromCurrency, $fromRate),
                ];
                if ($feeAccount && $fee > 0) {
                    $lines[] = PostingLine::debit($feeAccount, $fee, null, null, 'Transfer fee')->in($fromCurrency, $fromRate);
                }
                $difference = $baseTo - $baseFrom;
                if ($difference > 0) {
                    $lines[] = PostingLine::credit($this->posting->account((string) $this->settings->get('accounting', 'fx_gain_account_code', '7900')), $difference, null, null, 'FX gain on transfer');
                } elseif ($difference < 0) {
                    $lines[] = PostingLine::debit($this->posting->account((string) $this->settings->get('accounting', 'fx_loss_account_code', '6900')), abs($difference), null, null, 'FX loss on transfer');
                }
            }

            if ($this->settings->bool('treasury', 'block_negative_cash', false)) {
                $balance = $this->balance($from);
                if ($balance - $outgoingBase < 0) {
                    throw new DomainException("Insufficient balance in {$from->name_en}: the transfer would overdraw the account.");
                }
            }

            $transfer = Transfer::query()->create([
                'number' => $this->sequences->next('transfer', $branch, Carbon::parse($date)),
                'branch_id' => $branch->id,
                'from_cash_account_id' => $from->id,
                'to_cash_account_id' => $to->id,
                'transfer_date' => $date,
                'from_currency' => $fromCurrency,
                'from_amount_minor' => $amount,
                'from_fx_rate' => $fromRate,
                'to_currency' => $toCurrency,
                'to_amount_minor' => $toAmount,
                'to_fx_rate' => $toRate,
                'fee_minor' => $fee,
                'memo' => $data['memo'] ?? null,
                'status' => 'posted',
                'created_by' => $actorId,
            ]);

            $entry = $this->posting->post(PostingRequest::make(
                entryDate: $date,
                branch: $branch,
                currency: $entryCurrency,
                lines: $lines,
                memo: 'Transfer '.$transfer->number.($transfer->memo ? ' - '.$transfer->memo : ''),
                sourceType: 'transfer',
                sourceId: $transfer->id,
                event: 'posted',
                fxRate: $entryRate,
                fxRateDate: $date,
                actorId: $actorId,
            ));

            $transfer->update(['journal_entry_id' => $entry->id]);

            $this->audit->log('transfer.created', $transfer, null, [
                'number' => $transfer->number,
                'from' => $from->account->code,
                'to' => $to->account->code,
                'amount_minor' => $amount,
                'currency' => $fromCurrency,
            ], $actorId);

            return $transfer->load('from.account', 'to.account', 'journalEntry');
        });
    }

    public function reverse(Transfer $transfer, string $reason, ?int $actorId = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $reason, $actorId) {
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($transfer->status !== 'posted') {
                throw new DomainException('Only posted transfers can be reversed.');
            }

            $reversal = $this->posting->reverse($transfer->journalEntry, $reason, $actorId);

            $transfer->update(['status' => 'reversed', 'reversal_journal_entry_id' => $reversal->id]);

            $this->audit->log('transfer.reversed', $transfer, ['status' => 'posted'], ['status' => 'reversed', 'reason' => $reason], $actorId);

            return $transfer->load('from.account', 'to.account', 'journalEntry');
        });
    }

    private function rate(string $currency, string $base, string $date, ?string $provided): string
    {
        if ($currency === $base) {
            return '1';
        }

        return $provided !== null && $provided !== '' ? $provided : $this->fx->rateFor($currency, $date);
    }

    private function toBase(int $minor, string $currency, string $base, string $rate): int
    {
        return $currency === $base ? $minor : (new Money($minor, $currency))->convertTo($base, $rate)->amountMinor;
    }
}
