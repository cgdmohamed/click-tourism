<?php

namespace App\Modules\Attachments\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class AttachmentExtraction extends Model
{
    use HasPublicId;

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CONFIRMED = 'confirmed';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'attachment_id', 'status', 'category', 'model', 'proposed', 'confirmed', 'error_code',
        'requested_by', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['proposed' => 'encrypted:array', 'confirmed' => 'encrypted:array', 'reviewed_at' => 'datetime'];
    }

    public function attachment()
    {
        return $this->belongsTo(Attachment::class);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'attachment_id' => $this->attachment?->public_id,
            'status' => $this->status,
            'category' => $this->category,
            'proposed' => $this->proposed,
            'confirmed' => $this->confirmed,
            'error_code' => $this->error_code,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
