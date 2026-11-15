<?php

namespace App\Support;

/**
 * Gives a customer or supplier a short unique code (C-0001 / S-0001) when it is created (ADR-0029).
 * The code is never mass-assigned; the unique index is the final authority.
 */
trait HasPartyCode
{
    public static function bootHasPartyCode(): void
    {
        static::creating(function ($model) {
            if (empty($model->code)) {
                $model->code = CodeGenerator::next($model->getTable(), 'code', $model->codePrefix(), 4);
            }
        });
    }

    abstract public function codePrefix(): string;
}
