<?php

namespace App\Modules\Assistant\Support;

/**
 * Validates tool arguments against the small JSON Schema subset the gateway catalogue uses:
 * object (properties, required, additionalProperties:false), string (minLength, maxLength,
 * pattern, format:date), integer (minimum, maximum), boolean and enum. Anything else in a schema
 * is ignored, so extend this when the catalogue grows (the parity test notices new keywords).
 */
final class SchemaValidator
{
    public const SUPPORTED_KEYWORDS = [
        'type', 'properties', 'required', 'additionalProperties', 'enum', 'minLength', 'maxLength',
        'pattern', 'format', 'minimum', 'maximum', 'default',
    ];

    /** @return list<string> Human-readable problems; empty when valid. */
    public function errors(array $schema, mixed $value, string $path = '(root)'): array
    {
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return ["{$path} must be one of: ".implode(', ', $schema['enum'])];
        }

        return match ($schema['type'] ?? null) {
            'object' => $this->object($schema, $value, $path),
            'string' => $this->string($schema, $value, $path),
            'integer' => $this->integer($schema, $value, $path),
            'boolean' => is_bool($value) ? [] : ["{$path} must be a boolean"],
            default => [],
        };
    }

    private function object(array $schema, mixed $value, string $path): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return ["{$path} must be an object"];
        }

        $errors = [];
        $properties = $schema['properties'] ?? [];
        foreach ($schema['required'] ?? [] as $name) {
            if (! array_key_exists($name, $value)) {
                $errors[] = "{$path} requires {$name}";
            }
        }
        foreach ($value as $name => $item) {
            if (! array_key_exists($name, $properties)) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path} must not have the property {$name}";
                }

                continue;
            }
            array_push($errors, ...$this->errors($properties[$name], $item, (string) $name));
        }

        return $errors;
    }

    private function string(array $schema, mixed $value, string $path): array
    {
        if (! is_string($value)) {
            return ["{$path} must be a string"];
        }

        $errors = [];
        $length = mb_strlen($value);
        if (isset($schema['minLength']) && $length < $schema['minLength']) {
            $errors[] = "{$path} must have at least {$schema['minLength']} characters";
        }
        if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
            $errors[] = "{$path} must have at most {$schema['maxLength']} characters";
        }
        if (isset($schema['pattern']) && preg_match('#'.str_replace('#', '\\#', $schema['pattern']).'#u', $value) !== 1) {
            $errors[] = "{$path} has an invalid format";
        }
        if (($schema['format'] ?? null) === 'date' && ! $this->isDate($value)) {
            $errors[] = "{$path} must be a date (YYYY-MM-DD)";
        }

        return $errors;
    }

    private function integer(array $schema, mixed $value, string $path): array
    {
        if (! is_int($value)) {
            return ["{$path} must be an integer"];
        }

        $errors = [];
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = "{$path} must be at least {$schema['minimum']}";
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $errors[] = "{$path} must be at most {$schema['maximum']}";
        }

        return $errors;
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
