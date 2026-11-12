<?php

namespace App\Modules\Integrations\Data;

/** A lead as returned by the Graph API, with form answers flattened to name => value. */
final class MetaLead
{
    /** @param array<string, string> $fields */
    public function __construct(
        public readonly string $leadgenId,
        public readonly ?string $formId,
        public readonly ?string $pageId,
        public readonly ?string $adId,
        public readonly array $fields,
    ) {}

    public function name(): ?string
    {
        $full = $this->fields['full_name'] ?? null;
        if ($full !== null && trim($full) !== '') {
            return trim($full);
        }
        $joined = trim(($this->fields['first_name'] ?? '').' '.($this->fields['last_name'] ?? ''));

        return $joined !== '' ? $joined : null;
    }

    public function phone(): ?string
    {
        $phone = $this->fields['phone_number'] ?? $this->fields['phone'] ?? null;

        return $phone !== null && trim($phone) !== '' ? trim($phone) : null;
    }

    public function email(): ?string
    {
        $email = $this->fields['email'] ?? null;

        return $email !== null && filter_var(trim($email), FILTER_VALIDATE_EMAIL) ? trim($email) : null;
    }

    /** Answers that are not part of the standard contact fields. */
    public function extraAnswers(): array
    {
        return array_diff_key($this->fields, array_flip(['full_name', 'first_name', 'last_name', 'phone_number', 'phone', 'email']));
    }
}
