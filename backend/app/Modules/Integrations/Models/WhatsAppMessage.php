<?php

namespace App\Modules\Integrations\Models;

use App\Modules\CRM\Models\Lead;
use App\Modules\Parties\Models\Customer;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    use HasPublicId;

    protected $table = 'whatsapp_messages';

    /** Delivery progress; a status never moves backwards (webhooks arrive out of order). */
    public const STATUS_RANK = ['queued' => 0, 'received' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

    protected $fillable = [
        'direction',
        'wa_message_id',
        'idempotency_key',
        'phone',
        'lead_id',
        'customer_id',
        'type',
        'body',
        'template_name',
        'template_language',
        'template_components',
        'status',
        'error_code',
        'error_message',
        'attempts',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'status_updated_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'template_components' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'status_updated_at' => 'datetime',
        ];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'direction' => $this->direction,
            'phone' => $this->phone,
            'lead_id' => $this->lead?->public_id,
            'customer_id' => $this->customer?->public_id,
            'type' => $this->type,
            'body' => $this->body,
            'template_name' => $this->template_name,
            'status' => $this->status,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'attempts' => $this->attempts,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
