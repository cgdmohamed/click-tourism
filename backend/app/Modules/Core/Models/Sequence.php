<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

class Sequence extends Model
{
    protected $fillable = [
        'document_type',
        'branch_id',
        'year',
        'prefix',
        'next_value',
        'gap_policy',
    ];
}
