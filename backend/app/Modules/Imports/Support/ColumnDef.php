<?php

namespace App\Modules\Imports\Support;

/**
 * One column of a versioned import template. `key` is the stable machine name;
 * the English and Arabic labels are the downloadable headers. Upload headers
 * may use the key, either label, or any alias (case/space-insensitive).
 */
final class ColumnDef
{
    /**
     * @param  string  $type  string|email|phone|enum|bool|currency|money|int|decimal|date
     * @param  array<string, mixed>  $options  max, values, map, currency_key, min
     * @param  list<string>  $aliases
     */
    public function __construct(
        public readonly string $key,
        public readonly string $en,
        public readonly string $ar,
        public readonly string $type = 'string',
        public readonly bool $required = false,
        public readonly array $options = [],
        public readonly string $example = '',
        public readonly string $descEn = '',
        public readonly string $descAr = '',
        public readonly array $aliases = [],
    ) {}

    public function label(string $locale): string
    {
        return $locale === 'ar' ? $this->ar : $this->en;
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'key' => $this->key,
            'label_en' => $this->en,
            'label_ar' => $this->ar,
            'type' => $this->type,
            'required' => $this->required,
            'allowed_values' => $this->options['values'] ?? null,
            'max_length' => $this->options['max'] ?? null,
            'example' => $this->example,
            'description_en' => $this->descEn,
            'description_ar' => $this->descAr,
        ];
    }
}
