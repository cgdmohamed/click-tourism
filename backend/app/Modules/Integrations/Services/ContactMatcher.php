<?php

namespace App\Modules\Integrations\Services;

use App\Modules\CRM\Models\Lead;
use App\Modules\Parties\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds existing CRM records for a phone/email without altering shared
 * schemas: stored phones are normalised in SQL and compared on the
 * match key (see PhoneNumber).
 */
class ContactMatcher
{
    public function leadByPhone(?string $phone): ?Lead
    {
        $key = PhoneNumber::matchKey($phone);
        if ($key === '') {
            return null;
        }

        return $this->byPhone(Lead::query(), $key)
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->first();
    }

    public function leadByEmail(?string $email): ?Lead
    {
        $email = $email !== null ? mb_strtolower(trim($email)) : '';
        if ($email === '') {
            return null;
        }

        return Lead::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->first();
    }

    public function customerByPhone(?string $phone): ?Customer
    {
        $key = PhoneNumber::matchKey($phone);
        if ($key === '') {
            return null;
        }

        return $this->byPhone(Customer::query(), $key)->orderByDesc('id')->first();
    }

    private function byPhone(Builder $query, string $key): Builder
    {
        // $key is digits only (PhoneNumber::matchKey), so no LIKE escaping is needed.
        return $query->whereNotNull('phone')
            ->whereRaw(PhoneNumber::sqlDigits('phone').' LIKE ?', ['%'.$key]);
    }
}
