<?php

namespace App\Modules\Assistant\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** One saved chat of one user (ADR-0022). Title and messages are encrypted at rest. */
class AssistantConversation extends Model
{
    use HasPublicId;

    protected $fillable = ['user_id', 'branch_id', 'title', 'last_message_at'];

    protected function casts(): array
    {
        return ['title' => 'encrypted', 'last_message_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function messages()
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id')->orderBy('id');
    }
}
