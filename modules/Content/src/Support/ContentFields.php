<?php

declare(strict_types=1);

namespace Modules\Content\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The field kinds a custom content type may declare, and how an entry's values are checked.
 * Like page sections: plain text only, links site-relative or https, nothing unknown kept.
 */
final class ContentFields
{
    /** @var array<string, array{0: string, 1: list<string>}> kind => [label, rules] */
    public const KINDS = [
        'text' => ['Short text', ['string', 'max:300']],
        'textarea' => ['Long text', ['string', 'max:5000']],
        'number' => ['Whole number', ['integer', 'between:-1000000000,1000000000']],
        'boolean' => ['Yes / no', ['boolean']],
        'url' => ['Link', ['string', 'max:2048', Sections::LINK]],
        'image_url' => ['Image address', ['string', 'max:2048', Sections::LINK]],
        'date' => ['Date', ['date_format:Y-m-d']],
    ];

    public const MAX_FIELDS = 20;

    /**
     * @param  array<int, mixed>  $fields
     * @return list<array{key: string, label: string, kind: string, required: bool}>
     *
     * @throws ValidationException
     */
    public static function cleanDefinition(array $fields): array
    {
        Validator::make(['fields' => $fields], [
            'fields' => ['array', 'max:'.self::MAX_FIELDS],
            'fields.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:80'],
            'fields.*.kind' => ['required', 'string', 'in:'.implode(',', array_keys(self::KINDS))],
            'fields.*.required' => ['nullable', 'boolean'],
        ])->validate();

        return array_map(fn (array $field): array => [
            'key' => (string) $field['key'],
            'label' => trim((string) $field['label']),
            'kind' => (string) $field['kind'],
            'required' => (bool) ($field['required'] ?? false),
        ], array_values($fields));
    }

    /**
     * @param  list<array{key: string, label: string, kind: string, required: bool}>  $definition
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> only declared fields, empty optional ones left out
     *
     * @throws ValidationException
     */
    public static function cleanValues(array $definition, array $values): array
    {
        $rules = [];
        foreach ($definition as $field) {
            $rules[$field['key']] = [$field['required'] ? 'required' : 'nullable', ...self::KINDS[$field['kind']][1]];
        }

        $unknown = array_diff(array_keys($values), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['values' => __('Unknown field(s): :fields.', ['fields' => implode(', ', $unknown)])]);
        }

        $validated = Validator::make($values, $rules)->validate();
        $clean = [];

        foreach ($definition as $field) {
            $value = $validated[$field['key']] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $clean[$field['key']] = match ($field['kind']) {
                'number' => (int) $value,
                'boolean' => (bool) $value,
                default => trim((string) $value),
            };
        }

        return $clean;
    }
}
