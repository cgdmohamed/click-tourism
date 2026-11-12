<?php

namespace App\Modules\Integrations\Models;

use Illuminate\Database\Eloquent\Model;

class MessagingConsent extends Model
{
    protected $fillable = [
        'channel',
        'phone',
        'status',
        'source',
        'note',
        'lead_id',
        'customer_id',
        'recorded_by',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
