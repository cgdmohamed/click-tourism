<?php

namespace App\Modules\Attachments\Services;

use DomainException;

/**
 * What may be extracted from each kind of document, and how a value is checked. Only the fields
 * listed here are ever requested or kept: a passport yields five identity fields and nothing else.
 */
final class ExtractionSchema
{
    /** field => type (text | date | amount | currency) */
    private const FIELDS = [
        'supplier_invoice' => [
            'supplier_name' => 'text', 'invoice_number' => 'text', 'invoice_date' => 'date', 'due_date' => 'date',
            'currency' => 'currency', 'subtotal' => 'amount', 'tax' => 'amount', 'total' => 'amount',
            'booking_reference' => 'text', 'item_description' => 'text',
        ],
        'payment_receipt' => [
            'payer_name' => 'text', 'payee_name' => 'text', 'amount' => 'amount', 'currency' => 'currency',
            'payment_date' => 'date', 'reference' => 'text', 'bank_name' => 'text',
        ],
        'bank_transfer' => [
            'payer_name' => 'text', 'payee_name' => 'text', 'amount' => 'amount', 'currency' => 'currency',
            'payment_date' => 'date', 'reference' => 'text', 'bank_name' => 'text',
        ],
        'passport' => [
            'full_name' => 'text', 'passport_number' => 'text', 'nationality' => 'text',
            'date_of_birth' => 'date', 'expiry_date' => 'date',
        ],
        'visa' => [
            'full_name' => 'text', 'document_number' => 'text', 'valid_from' => 'date', 'valid_until' => 'date', 'destination' => 'text',
        ],
        'travel_document' => [
            'traveler_name' => 'text', 'booking_reference' => 'text', 'departure_date' => 'date', 'return_date' => 'date',
        ],
        'booking_confirmation' => [
            'traveler_name' => 'text', 'booking_reference' => 'text', 'supplier_name' => 'text',
            'departure_date' => 'date', 'return_date' => 'date', 'total' => 'amount', 'currency' => 'currency',
        ],
        'contract' => [
            'counterparty' => 'text', 'contract_date' => 'date', 'end_date' => 'date', 'total_amount' => 'amount', 'currency' => 'currency',
        ],
    ];

    public static function supports(string $category): bool
    {
        return isset(self::FIELDS[$category]);
    }

    /** @return array<string, string> */
    public static function fields(string $category): array
    {
        return self::FIELDS[$category] ?? [];
    }

    /**
     * Normalises one value of the given type. Returns [value, problem]: a value that cannot be read
     * as its type keeps its text but is reported, so it is flagged for the reviewer instead of guessed.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function normalise(string $type, mixed $raw): array
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return [null, 'missing'];
        }
        $value = trim(is_scalar($raw) ? (string) $raw : '');
        if ($value === '' || mb_strlen($value) > 255) {
            return [null, 'unclear'];
        }

        return match ($type) {
            'date' => ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value)) && $d->format('Y-m-d') === $value
                ? [$value, null] : [$value, 'unclear'],
            'amount' => preg_match('/^\d{1,13}(\.\d{1,3})?$/', $value) === 1 ? [$value, null] : [$value, 'unclear'],
            'currency' => preg_match('/^[A-Za-z]{3}$/', $value) === 1 ? [strtoupper($value), null] : [$value, 'unclear'],
            default => [$value, null],
        };
    }

    /**
     * Review step: the reviewer's values must be valid for their type (null is allowed: the field stays empty).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, ?string>
     */
    public static function validateReviewed(string $category, array $values): array
    {
        $fields = self::fields($category);
        $out = [];
        foreach ($values as $field => $raw) {
            if (! isset($fields[$field])) {
                throw new DomainException("Unknown field: {$field}.");
            }
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $out[$field] = null;

                continue;
            }
            [$value, $problem] = self::normalise($fields[$field], $raw);
            if ($problem !== null) {
                throw new DomainException("The value of {$field} is not valid.");
            }
            $out[$field] = $value;
        }

        return $out;
    }
}
