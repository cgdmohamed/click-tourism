<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class LeadActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['lead_id', 'type', 'body', 'actor_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
