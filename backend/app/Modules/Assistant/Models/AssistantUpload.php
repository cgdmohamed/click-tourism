<?php

namespace App\Modules\Assistant\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** A file one user attached to an assistant message (ADR-0028). Short-lived and private to that user. */
class AssistantUpload extends Model
{
    use HasPublicId;

    protected $fillable = ['user_id', 'name', 'mime', 'size_bytes', 'sha256', 'stored_path', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
