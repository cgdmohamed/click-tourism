<?php

namespace App\Modules\Core\Models;

use App\Modules\Parties\Models\Employee;
use App\Support\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasPublicId;
    use HasRoles;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
        'is_active',
        'is_available_for_leads',
        'primary_branch_id',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_available_for_leads' => 'boolean',
        ];
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'user_branch')->withTimestamps();
    }

    public function primaryBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'primary_branch_id');
    }

    /** Optional linked employee record; display only, several may share a user_id. */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class, 'user_id');
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function branchIds(): array
    {
        return $this->branches()->pluck('branches.id')->all();
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'locale' => $this->locale,
            'roles' => $this->getRoleNames()->values()->all(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values()->all(),
            'branches' => $this->branches->map->toApiArray()->values()->all(),
            'primary_branch_id' => $this->primaryBranch?->public_id,
        ];
    }
}
