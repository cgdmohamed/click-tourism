<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    protected $fillable = ['name', 'starts_on', 'ends_on', 'status', 'closed_at', 'closed_by', 'close_count'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'closed_at' => 'datetime',
            'close_count' => 'integer',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function periods()
    {
        return $this->hasMany(FiscalPeriod::class);
    }
}
