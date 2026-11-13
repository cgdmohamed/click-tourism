<?php

namespace App\Modules\Attachments\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attachment extends Model
{
    use HasPublicId;
    use SoftDeletes;

    /** Categories whose content is personal data: only users with attachments.sensitive may open them. */
    public const SENSITIVE = ['passport', 'visa', 'travel_document'];

    public const CATEGORIES = [
        'passport', 'visa', 'booking_confirmation', 'supplier_invoice', 'payment_receipt',
        'bank_transfer', 'travel_document', 'contract', 'other',
    ];

    protected $fillable = [
        'attachable_type', 'attachable_id', 'branch_id', 'category', 'original_name', 'stored_path',
        'mime', 'size_bytes', 'sha256', 'notes', 'uploaded_by',
    ];

    protected $hidden = ['stored_path'];

    public function isSensitive(): bool
    {
        return in_array($this->category, self::SENSITIVE, true);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function extractions()
    {
        return $this->hasMany(AttachmentExtraction::class);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'attachable_type' => $this->attachable_type,
            'category' => $this->category,
            'sensitive' => $this->isSensitive(),
            'name' => $this->original_name,
            'mime' => $this->mime,
            'size_bytes' => (int) $this->size_bytes,
            'notes' => $this->notes,
            'uploaded_by' => $this->uploader?->name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
