<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Exposes a ULID `public_id` as the route key so URLs never leak internal ids.
 */
trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
