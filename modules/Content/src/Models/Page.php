<?php

declare(strict_types=1);

namespace Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Support\Concerns\HasUlid;
use Modules\Support\ValueObjects\JsonObject;

/**
 * @property int $id
 * @property string $ulid
 * @property string $slug
 * @property string $title
 * @property string $status
 * @property list<array{type: string, data: array<string, mixed>}> $sections
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property Carbon|null $published_at
 * @property Carbon $updated_at
 */
final class Page extends Model
{
    use HasUlid;

    public const HOME = 'home';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sections' => 'array', 'published_at' => 'datetime'];
    }

    public function path(): string
    {
        return $this->slug === self::HOME ? '/' : '/pages/'.$this->slug;
    }

    /**
     * @return array{id: string, slug: string, path: string, title: string, sections: list<array{type: string, data: JsonObject}>, meta: array{title: string, description: string|null}, updated_at: string}
     *
     * @scramble-return array{id: string, slug: string, path: string, title: string, sections: list<array{type: string, data: \Modules\Support\ValueObjects\JsonObject}>, meta: array{title: string, description: string|null}, updated_at: string}
     */
    public function toApi(): array
    {
        return [
            'id' => $this->ulid,
            'slug' => $this->slug,
            'path' => $this->path(),
            'title' => $this->title,
            'sections' => array_map(fn (array $section): array => ['type' => $section['type'], 'data' => new JsonObject($section['data'])], $this->sections),
            'meta' => [
                'title' => $this->meta_title ?? $this->title,
                'description' => $this->meta_description,
            ],
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
