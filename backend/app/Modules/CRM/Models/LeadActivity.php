<?php

namespace App\Modules\CRM\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

class LeadActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['lead_id', 'type', 'body', 'actor_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => (int) $this->id,
            'type' => $this->type,
            'body' => $this->body,
            'actor' => $this->actor ? [
                'id' => $this->actor->public_id,
                'name' => $this->actor->name,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
