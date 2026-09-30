<?php

declare(strict_types=1);

namespace Modules\Content\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The allow-list of page-builder section types and their fields. Anything not listed here is
 * refused on save, so a page can never carry markup, scripts or fields the storefront does not
 * know how to render. Text is plain text (the storefront escapes it); links are site-relative
 * paths or https URLs.
 */
final class Sections
{
    public const LINK = 'regex:#^(/(?!/)[^\s]*|https://[^\s]+)$#';

    public const MAX_SECTIONS = 40;

    /**
     * @return array<string, array<string, list<string>>> type => field rules
     */
    public static function types(): array
    {
        return [
            'hero' => [
                'heading' => ['required', 'string', 'max:120'],
                'subheading' => ['nullable', 'string', 'max:300'],
                'image_url' => ['nullable', 'string', 'max:2048', self::LINK],
                'cta_label' => ['nullable', 'string', 'max:40'],
                'cta_url' => ['nullable', 'required_with:cta_label', 'string', 'max:2048', self::LINK],
            ],
            'rich_text' => [
                'heading' => ['nullable', 'string', 'max:120'],
                'body' => ['required', 'string', 'max:20000'],
            ],
            'product_grid' => [
                'title' => ['nullable', 'string', 'max:120'],
                'source' => ['required', 'string', 'in:newest,category,collection,brand'],
                'value' => ['nullable', 'required_unless:source,newest', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
                'limit' => ['required', 'integer', 'min:1', 'max:24'],
            ],
            'category_grid' => [
                'title' => ['nullable', 'string', 'max:120'],
                'category_slugs' => ['required', 'array', 'min:1', 'max:12'],
                'category_slugs.*' => ['string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            ],
            'banner' => [
                'image_url' => ['required', 'string', 'max:2048', self::LINK],
                'alt' => ['required', 'string', 'max:200'],
                'link_url' => ['nullable', 'string', 'max:2048', self::LINK],
            ],
            'faq' => [
                'title' => ['nullable', 'string', 'max:120'],
                'items' => ['required', 'array', 'min:1', 'max:30'],
                'items.*.question' => ['required', 'string', 'max:300'],
                'items.*.answer' => ['required', 'string', 'max:3000'],
            ],
            'newsletter' => [
                'heading' => ['required', 'string', 'max:120'],
                'text' => ['nullable', 'string', 'max:300'],
            ],
        ];
    }

    /**
     * Validate and normalise: unknown section types and unknown fields are refused, known
     * fields keep only what their rules allow.
     *
     * @param  array<int, mixed>  $sections
     * @return list<array{type: string, data: array<string, mixed>}>
     *
     * @throws ValidationException
     */
    public static function clean(array $sections): array
    {
        if (count($sections) > self::MAX_SECTIONS) {
            throw ValidationException::withMessages(['sections' => __('A page has at most :max sections.', ['max' => self::MAX_SECTIONS])]);
        }

        $types = self::types();
        $clean = [];

        foreach (array_values($sections) as $index => $section) {
            $type = is_array($section) ? ($section['type'] ?? null) : null;
            $data = is_array($section) && is_array($section['data'] ?? null) ? $section['data'] : [];

            if (! is_string($type) || ! isset($types[$type])) {
                throw ValidationException::withMessages(["sections.$index.type" => __('Unknown section type.')]);
            }

            $allowed = array_unique(array_map(fn (string $key): string => explode('.', $key)[0], array_keys($types[$type])));
            $unknown = array_diff(array_keys($data), $allowed);

            if ($unknown !== []) {
                throw ValidationException::withMessages(["sections.$index.data" => __('Unknown field(s): :fields.', ['fields' => implode(', ', $unknown)])]);
            }

            $validator = Validator::make($data, $types[$type]);

            if ($validator->fails()) {
                $errors = [];

                foreach ($validator->errors()->toArray() as $field => $messages) {
                    $errors["sections.$index.data.$field"] = $messages;
                }

                throw ValidationException::withMessages($errors);
            }

            /** @var array<string, mixed> $validated */
            $validated = $validator->validated();
            $clean[] = ['type' => $type, 'data' => $validated];
        }

        return $clean;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array{label: string, url: string, children: list<array{label: string, url: string}>}>
     *
     * @throws ValidationException
     */
    public static function cleanMenu(array $items): array
    {
        $validator = Validator::make(['items' => $items], [
            'items' => ['array', 'max:50'],
            'items.*.label' => ['required', 'string', 'max:60'],
            'items.*.url' => ['required', 'string', 'max:2048', self::LINK],
            'items.*.children' => ['nullable', 'array', 'max:20'],
            'items.*.children.*.label' => ['required', 'string', 'max:60'],
            'items.*.children.*.url' => ['required', 'string', 'max:2048', self::LINK],
        ]);

        $validator->validate();

        return array_map(fn (array $item): array => [
            'label' => (string) $item['label'],
            'url' => (string) $item['url'],
            'children' => array_map(fn (array $child): array => ['label' => (string) $child['label'], 'url' => (string) $child['url']], array_values($item['children'] ?? [])),
        ], array_values($items));
    }
}
