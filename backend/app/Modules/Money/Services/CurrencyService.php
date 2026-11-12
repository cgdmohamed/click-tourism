<?php

namespace App\Modules\Money\Services;

use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\SettingService;
use App\Modules\Money\Models\Currency;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Currency master data (create / edit / activate / delete).
 *
 * Rules (Odoo/Zoho style, no accounting decisions): the code is immutable,
 * the exponent is frozen once the currency is referenced anywhere, the base
 * currency can never be deactivated or deleted, and a referenced currency can
 * only be deactivated, never deleted.
 */
class CurrencyService
{
    /** @var list<array{table: string, column: string}>|null */
    private ?array $references = null;

    public function __construct(
        private readonly SettingService $settings,
        private readonly AuditService $audit,
    ) {}

    public function isBase(string $code): bool
    {
        return strtoupper($code) === $this->settings->baseCurrency();
    }

    /**
     * Whether any stored record references the currency: fx rates, journal
     * entries and lines, documents, accounts, cash accounts, bookings,
     * invoices, payments, parties, ... Every column named `currency`,
     * `currency_code` or `*_currency` of every table is checked, so tables
     * added later are covered without maintaining a list. The base currency
     * setting counts as a reference too.
     */
    public function isUsed(string $code): bool
    {
        return $this->usedBy($code, 1) !== [];
    }

    /**
     * Places (table.column) that reference the currency; stops after $limit hits.
     *
     * @return list<string>
     */
    public function usedBy(string $code, int $limit = 50): array
    {
        $code = strtoupper($code);
        $hits = [];

        if ($this->isBase($code)) {
            $hits[] = 'settings.general.base_currency';
        }

        foreach ($this->referenceColumns() as $ref) {
            if (count($hits) >= $limit) {
                break;
            }
            if (DB::table($ref['table'])->where($ref['column'], $code)->exists()) {
                $hits[] = $ref['table'].'.'.$ref['column'];
            }
        }

        return $hits;
    }

    /** @return list<array{table: string, column: string}> */
    private function referenceColumns(): array
    {
        if ($this->references !== null) {
            return $this->references;
        }

        $found = [];
        foreach (Schema::getTables() as $table) {
            $name = (string) $table['name'];
            if ($name === 'currencies') {
                continue;
            }
            foreach (Schema::getColumns($name) as $column) {
                $col = (string) $column['name'];
                if ($col === 'currency' || $col === 'currency_code' || str_ends_with($col, '_currency')) {
                    $found[] = ['table' => $name, 'column' => $col];
                }
            }
        }

        return $this->references = $found;
    }

    /**
     * Built-in currencies (EGP, KWD, ...) have a fixed number of decimals shared by the money
     * layer, the web formatter, imports and exports; any other value would make the same amount
     * mean different minor units in different places.
     */
    private function assertBuiltinExponent(string $code, int $exponent): void
    {
        $builtin = Money::builtinExponent($code);
        if ($builtin !== null && $builtin !== $exponent) {
            throw ValidationException::withMessages([
                'exponent' => strtoupper($code)." is a built-in currency and always uses {$builtin} decimals.",
            ]);
        }
    }

    /** @param array{code: string, name: string, symbol?: ?string, exponent: int} $data */
    public function create(array $data, ?int $actorId): Currency
    {
        $this->assertBuiltinExponent((string) $data['code'], (int) $data['exponent']);

        $currency = DB::transaction(fn () => Currency::query()->create([
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'symbol' => $data['symbol'] ?? null,
            'exponent' => (int) $data['exponent'],
            'is_active' => true,
        ]));
        Money::flushExponentCache();

        $this->audit->log('currency.created', null, null, $this->snapshot($currency), $actorId);

        return $currency;
    }

    /** @param array{name?: string, symbol?: ?string, exponent?: int, is_active?: bool} $data */
    public function update(Currency $currency, array $data, ?int $actorId): Currency
    {
        $before = $this->snapshot($currency);

        if (array_key_exists('exponent', $data)) {
            $this->assertBuiltinExponent($currency->code, (int) $data['exponent']);
        }

        if (array_key_exists('exponent', $data) && (int) $data['exponent'] !== (int) $currency->exponent && $this->isUsed($currency->code)) {
            throw ValidationException::withMessages([
                'exponent' => "The decimals of {$currency->code} cannot be changed because the currency is already in use.",
            ]);
        }

        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $currency->is_active && $this->isBase($currency->code)) {
            throw ValidationException::withMessages([
                'is_active' => "The base currency {$currency->code} cannot be deactivated.",
            ]);
        }

        $currency->fill(array_intersect_key($data, array_flip(['name', 'symbol', 'exponent', 'is_active'])));
        $changed = $currency->isDirty();
        $currency->save();
        Money::flushExponentCache();

        if ($changed) {
            $after = $this->snapshot($currency);
            $diff = array_keys(array_diff_assoc($after, $before));
            $action = $diff === ['is_active']
                ? ($after['is_active'] ? 'currency.activated' : 'currency.deactivated')
                : 'currency.updated';
            $this->audit->log($action, null, $before, $after, $actorId);
        }

        return $currency;
    }

    public function delete(Currency $currency, ?int $actorId): void
    {
        if ($this->isBase($currency->code)) {
            throw ValidationException::withMessages([
                'currency' => "The base currency {$currency->code} cannot be deleted.",
            ]);
        }

        if ($this->isUsed($currency->code)) {
            throw ValidationException::withMessages([
                'currency' => "Currency {$currency->code} is referenced by existing records and cannot be deleted; deactivate it instead.",
            ]);
        }

        $before = $this->snapshot($currency);
        DB::transaction(fn () => $currency->delete());
        Money::flushExponentCache();

        $this->audit->log('currency.deleted', null, $before, null, $actorId);
    }

    /** @return array{code: string, name: string, symbol: ?string, exponent: int, is_active: bool} */
    public function snapshot(Currency $currency): array
    {
        return [
            'code' => $currency->code,
            'name' => $currency->name,
            'symbol' => $currency->symbol,
            'exponent' => (int) $currency->exponent,
            'is_active' => (bool) $currency->is_active,
        ];
    }
}
