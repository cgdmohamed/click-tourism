<?php

namespace App\Modules\Parties\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Employee master record — distinct from login users. An employee may optionally
 * be linked to a system user but must not require one. Used for advances,
 * custody/petty cash, commissions and payroll-adjacent postings.
 */
class Employee extends Model
{
    use HasPublicId;

    protected $fillable = [
        'internal_code',
        'name_ar',
        'name_en',
        'category',
        'branch_id',
        'user_id',
        'phone',
        'email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public const CATEGORY_STAFF = 'staff';

    public const CATEGORY_EXECUTIVE = 'executive';

    public const CATEGORY_DRIVER = 'driver';

    public const CATEGORY_OTHER = 'other';

    public static $categories = [self::CATEGORY_STAFF, self::CATEGORY_EXECUTIVE, self::CATEGORY_DRIVER, self::CATEGORY_OTHER];

    /** @return array<string, string> */
    public static function categories(): array
    {
        return self::$categories;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(EmployeeTransaction::class)->orderBy('transaction_date', 'desc')->orderBy('id', 'desc');
    }

    public function toApiArray(bool $withBalance = false): array
    {
        $payload = [
            'id' => $this->public_id,
            'internal_code' => $this->internal_code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'category' => $this->category,
            'branch_id' => $this->branch?->public_id,
            'user_id' => $this->user_id,
            'phone' => $this->phone,
            'email' => $this->email,
            'is_active' => (bool) $this->is_active,
        ];

        if ($withBalance) {
            $payload['balance_minor'] = $this->outstandingBalance();
        }

        return $payload;
    }

    /**
     * Running employee balance: positive = employee owes company (advance/custody outstanding),
     * negative = company owes employee.
     */
    public function outstandingBalance(): int
    {
        return (int) $this->transactions()->sum('amount_minor');
    }
}
