<?php

namespace App\Modules\Integrations\Services;

/** Strips personal data from provider payloads before they are stored or logged. */
final class Redactor
{
    private const SENSITIVE_KEYS = [
        'body', 'from', 'wa_id', 'phone', 'phone_number', 'recipient_id', 'name', 'email',
        'full_name', 'first_name', 'last_name', 'field_data', 'caption', 'address', 'url',
    ];

    public static function payload(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::SENSITIVE_KEYS, true)) {
                $result[$key] = '[redacted]';

                continue;
            }
            $result[$key] = self::payload($item);
        }

        return $result;
    }
}
