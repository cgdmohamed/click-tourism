<?php

namespace App\Modules\Integrations\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'external_event_id',
        'payload',
        'status',
        'attempts',
        'processed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
