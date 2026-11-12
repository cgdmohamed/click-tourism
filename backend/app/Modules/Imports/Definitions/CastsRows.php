<?php

namespace App\Modules\Imports\Definitions;

use App\Modules\Imports\Support\ImportContext;

/** Shared cell-to-typed-value conversion for definitions. */
trait CastsRows
{
    /**
     * @param  array<string, string>  $data  raw cells keyed by column key
     * @return array{0: array<string, mixed>, 1: list<array{field: string, code: string, params: array<string, mixed>}>}
     */
    protected function castRow(array $data, ImportContext $ctx): array
    {
        $values = [];
        $errors = [];
        $deferred = [];

        foreach ($this->columns() as $column) {
            if (! array_key_exists($column->key, $data)) {
                // Absent optional column; a missing required one is a file error
                // raised before any row is processed.
                $values[$column->key] = null;

                continue;
            }
            if ($column->type === 'money') {
                $deferred[] = $column;

                continue;
            }
            [$values[$column->key], $error] = $ctx->caster->cast($column, $data[$column->key]);
            if ($error) {
                $errors[] = ['field' => $column->key] + $error;
            }
        }

        // Money depends on the row's currency (exponent), so it is cast last.
        foreach ($deferred as $column) {
            $currencyKey = $column->options['currency_key'] ?? null;
            $currency = ($currencyKey ? ($values[$currencyKey] ?? null) : null) ?? $ctx->baseCurrency;
            [$values[$column->key], $error] = $ctx->caster->cast($column, $data[$column->key], $ctx->caster->exponent($currency));
            if ($error) {
                $errors[] = ['field' => $column->key] + $error;
            }
        }

        return [$values, $errors];
    }

    /** Human-readable message for a cast error code (English; the UI localises by code). */
    protected function messageFor(string $field, string $code, array $params): string
    {
        $label = $field;
        foreach ($this->columns() as $column) {
            if ($column->key === $field) {
                $label = $column->en;
            }
        }

        return match ($code) {
            'required' => "{$label} is required.",
            'too_long' => "{$label} is longer than {$params['max']} characters.",
            'invalid_email' => "{$label} is not a valid email address.",
            'invalid_phone' => "{$label} is not a valid phone number.",
            'invalid_choice' => "{$label} must be one of: {$params['allowed']}.",
            'invalid_boolean' => "{$label} must be true/false (yes/no).",
            'invalid_currency' => "{$label} is not a known currency code.",
            'invalid_amount' => "{$label} is not a valid amount.",
            'too_many_decimals' => "{$label} has more than {$params['max']} decimal places.",
            'invalid_integer' => "{$label} must be a whole number.",
            'too_small' => "{$label} must be at least {$params['min']}.",
            'invalid_decimal' => "{$label} must be a positive number.",
            'invalid_date' => "{$label} is not a valid date (use YYYY-MM-DD).",
            'invalid_characters' => "{$label} contains control characters.",
            default => "{$label} is invalid.",
        };
    }
}
