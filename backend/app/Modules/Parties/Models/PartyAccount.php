<?php

namespace App\Modules\Parties\Models;

use App\Modules\Accounting\Models\Account;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a financial party (customer/supplier/employee) to one or more genuine
 * posting-capable child accounts in the chart of accounts (Prompt 27).
 *
 * A party keeps one primary account per role (receivable / payable / advance /
 * custody / payable_balance). Subaccounts are provisioned on party onboarding
 * and never deleted while they carry ledger activity.
 */
class PartyAccount extends Model
{
    use HasPublicId;

    protected $fillable = [
        'party_type',
        'party_id',
        'role',
        'account_id',
    ];

    public const ROLE_RECEIVABLE = 'receivable';

    public const ROLE_PAYABLE = 'payable';

    public const ROLE_ADVANCE = 'advance';

    public const ROLE_CUSTODY = 'custody';

    public const ROLE_PAYABLE_BALANCE = 'payable_balance';

    public static $roles = [
        self::ROLE_RECEIVABLE,
        self::ROLE_PAYABLE,
        self::ROLE_ADVANCE,
        self::ROLE_CUSTODY,
        self::ROLE_PAYABLE_BALANCE,
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'party_type' => $this->party_type,
            'party_id' => $this->party_id,
            'role' => $this->role,
            'account' => $this->account?->toApiArray(),
        ];
    }
}
