<?php

namespace App\Modules\Parties\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Parties\Models\Customer;
use App\Modules\Parties\Models\PartyAccount;
use App\Modules\Parties\Models\Supplier;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * One posting-capable ledger subaccount per customer and per supplier
 * (decision: party subledger accounts under the control accounts).
 *
 * Every receivable and payable movement is posted to the party's own
 * subaccount, so the general ledger and the party statement agree by
 * construction. Subaccounts are currency-agnostic: transaction currency is
 * carried by each journal line, not by the account.
 */
class PartyLedgerService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly AuditService $audit,
    ) {}

    /** Receivable subaccount of a customer (created on first use). */
    public function receivable(int $customerId): Account
    {
        return $this->account('customer', $customerId, PartyAccount::ROLE_RECEIVABLE);
    }

    /** Payable subaccount of a supplier (created on first use). */
    public function payable(int $supplierId): Account
    {
        return $this->account('supplier', $supplierId, PartyAccount::ROLE_PAYABLE);
    }

    /**
     * Provision (or return) the posting-capable subaccount for a party/role.
     *
     * @param  'customer'|'supplier'|'employee'  $partyType
     */
    public function account(string $partyType, int $partyId, string $role): Account
    {
        return DB::transaction(function () use ($partyType, $partyId, $role) {
            $existing = PartyAccount::query()->where('party_type', $partyType)
                ->where('party_id', $partyId)
                ->where('role', $role)
                ->with('account')
                ->first();

            if ($existing) {
                return $existing->account;
            }

            $party = $this->party($partyType, $partyId);

            $controlCode = match ($role) {
                PartyAccount::ROLE_RECEIVABLE => (string) $this->settings->get('accounting', 'ar_account_code', '1100'),
                PartyAccount::ROLE_PAYABLE, PartyAccount::ROLE_PAYABLE_BALANCE => (string) $this->settings->get('accounting', 'ap_account_code', '2100'),
                PartyAccount::ROLE_ADVANCE => (string) $this->settings->get('accounting', 'advance_account_code', '2300'),
                default => throw new DomainException("Unsupported party account role: {$role}"),
            };

            $control = Account::query()->where('code', $controlCode)->first();
            if (! $control || ! $control->is_active || ! $control->is_postable) {
                throw new DomainException("Control account {$controlCode} is missing or not postable.");
            }

            $isReceivable = $role === PartyAccount::ROLE_RECEIVABLE;
            $account = Account::query()->create([
                'code' => $this->nextChildCode($control),
                'name_ar' => ($isReceivable ? 'مدينون - ' : 'دائنون - ').$party->name_ar,
                'name_en' => ($isReceivable ? 'Receivable - ' : 'Payable - ').$party->name_en,
                'type' => $isReceivable ? 'asset' : 'liability',
                'parent_id' => $control->id,
                'is_postable' => true,
                'is_active' => true,
                'currency_code' => null,
            ]);

            $partyAccount = PartyAccount::query()->create([
                'party_type' => $partyType,
                'party_id' => $partyId,
                'role' => $role,
                'account_id' => $account->id,
            ]);

            $this->audit->log('party_account.provisioned', $partyAccount, null, [
                'party_type' => $partyType,
                'party_id' => $partyId,
                'role' => $role,
                'account_code' => $account->code,
            ], null);

            return $account;
        });
    }

    private function nextChildCode(Account $control): string
    {
        $max = Account::query()->where('parent_id', $control->id)
            ->where('code', 'like', $control->code.'%')
            ->max('code');

        $suffix = $max !== null ? (int) substr($max, strlen($control->code)) : 0;

        return $control->code.str_pad((string) ($suffix + 1), 4, '0', STR_PAD_LEFT);
    }

    private function party(string $partyType, int $partyId): Customer|Supplier
    {
        $party = match ($partyType) {
            'customer' => Customer::query()->find($partyId),
            'supplier' => Supplier::query()->find($partyId),
            default => throw new DomainException("Unsupported party type: {$partyType}"),
        };

        if (! $party) {
            throw new DomainException("The selected {$partyType} does not exist.");
        }

        return $party;
    }
}
