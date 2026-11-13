<?php

namespace App\Modules\Assistant\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['conversation_id', 'role', 'content', 'citations', 'truncated', 'actions'];

    protected function casts(): array
    {
        return ['content' => 'encrypted', 'citations' => 'encrypted:array', 'actions' => 'encrypted:array', 'truncated' => 'boolean'];
    }
}
