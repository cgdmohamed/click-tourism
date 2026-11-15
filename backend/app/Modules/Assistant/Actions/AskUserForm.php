<?php

namespace App\Modules\Assistant\Actions;

/**
 * The "ask the user" tool (ADR-0029). The model cannot guess which record, account or option the user
 * means, so instead of picking one it shows a small form in the chat: radio buttons, checkboxes and short
 * text fields. Nothing runs: the answer comes back as the user's next message. The server shapes and
 * limits the form; the model supplies only labels and ids.
 */
final class AskUserForm
{
    public const TOOL = 'erp_ask_user';

    private const MAX_FIELDS = 4;

    private const MAX_OPTIONS = 8;

    public static function definition(): array
    {
        $option = [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'label'],
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'The record id, code or number this option stands for.'],
                'label' => ['type' => 'string', 'description' => 'What the user reads, e.g. the name and code.'],
                'hint' => ['type' => 'string', 'description' => 'Optional short detail that tells options apart (branch, balance, number).'],
            ],
        ];

        return ['type' => 'function', 'function' => [
            'name' => self::TOOL,
            'description' => 'Show the user a short form to answer, when you need a choice or a missing detail: which of several matching records, which account, which option. Use it instead of guessing and instead of a long written list. radio = pick one, checkbox = pick several, text = a short free answer. After calling it, write at most one short sentence and wait; the answer arrives as the next user message.',
            'parameters' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['fields'],
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Short heading of the form.'],
                    'fields' => ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_FIELDS, 'items' => [
                        'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'label', 'type'],
                        'properties' => [
                            'id' => ['type' => 'string', 'description' => 'Short key for this question, e.g. customer.'],
                            'label' => ['type' => 'string', 'description' => 'The question.'],
                            'type' => ['type' => 'string', 'enum' => ['radio', 'checkbox', 'text']],
                            'options' => ['type' => 'array', 'maxItems' => self::MAX_OPTIONS, 'items' => $option, 'description' => 'Required for radio and checkbox (2 to 8).'],
                            'placeholder' => ['type' => 'string'],
                            'required' => ['type' => 'boolean', 'description' => 'Defaults to true.'],
                        ],
                    ]],
                ],
            ],
        ]];
    }

    /**
     * The form the screen renders, or null with a reason the model reads. All text is trimmed, stripped of
     * tags and limited in length; ids are kept short and unique within a field.
     *
     * @return array{form: ?array<string, mixed>, error: ?string}
     */
    public static function shape(mixed $args, callable $text): array
    {
        if (! is_array($args) || ! is_array($args['fields'] ?? null) || $args['fields'] === []) {
            return ['form' => null, 'error' => 'Give at least one field.'];
        }

        $fields = [];
        foreach (array_slice(array_values($args['fields']), 0, self::MAX_FIELDS) as $i => $raw) {
            $type = is_array($raw) ? ($raw['type'] ?? null) : null;
            $label = is_array($raw) ? self::clean($text, $raw['label'] ?? '', 160) : '';
            if (! in_array($type, ['radio', 'checkbox', 'text'], true) || $label === '') {
                return ['form' => null, 'error' => 'Each field needs a label and a type of radio, checkbox or text.'];
            }
            $field = [
                'id' => self::slug($raw['id'] ?? '', 'q'.($i + 1)),
                'label' => $label,
                'type' => $type,
                'required' => (bool) ($raw['required'] ?? true),
            ];
            if ($type === 'text') {
                $field['placeholder'] = self::clean($text, $raw['placeholder'] ?? '', 100);
            } else {
                $options = [];
                $seen = [];
                foreach (array_slice(is_array($raw['options'] ?? null) ? array_values($raw['options']) : [], 0, self::MAX_OPTIONS) as $option) {
                    $id = is_array($option) ? self::clean($text, $option['id'] ?? '', 64) : '';
                    $optionLabel = is_array($option) ? self::clean($text, $option['label'] ?? '', 120) : '';
                    if ($id === '' || $optionLabel === '' || isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $options[] = array_filter(['id' => $id, 'label' => $optionLabel, 'hint' => self::clean($text, $option['hint'] ?? '', 120)], fn ($v) => $v !== '');
                }
                if (count($options) < 2) {
                    return ['form' => null, 'error' => 'A radio or checkbox field needs at least two distinct options.'];
                }
                $field['options'] = $options;
            }
            $fields[] = $field;
        }

        return ['form' => array_filter(['title' => self::clean($text, $args['title'] ?? '', 100), 'fields' => $fields], fn ($v) => $v !== ''), 'error' => null];
    }

    private static function clean(callable $text, mixed $value, int $max): string
    {
        $value = $text(trim(strip_tags((string) $value)));

        return mb_substr(trim((string) $value), 0, $max);
    }

    private static function slug(mixed $value, string $fallback): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value) ?? '';

        return $slug === '' ? $fallback : substr($slug, 0, 30);
    }
}
