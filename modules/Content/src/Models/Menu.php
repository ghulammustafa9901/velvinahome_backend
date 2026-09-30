<?php

declare(strict_types=1);

namespace Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property list<array{label: string, url: string, children?: list<array{label: string, url: string}>}> $items
 */
final class Menu extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }

    /**
     * @return array{key: string, name: string, items: list<array{label: string, url: string, children: list<array{label: string, url: string}>}>}
     *
     * @scramble-return array{key: string, name: string, items: list<array{label: string, url: string, children: list<array{label: string, url: string}>}>}
     */
    public function toApi(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'items' => array_map(fn (array $item): array => [
                'label' => $item['label'],
                'url' => $item['url'],
                'children' => array_map(fn (array $child): array => ['label' => $child['label'], 'url' => $child['url']], $item['children'] ?? []),
            ], $this->items),
        ];
    }
}
