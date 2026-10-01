<?php

declare(strict_types=1);

namespace Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property string $slug
 * @property string $title
 * @property string|null $excerpt
 * @property string $body
 * @property string|null $cover_image_url
 * @property string|null $author_name
 * @property list<string> $tags
 * @property string $status
 * @property Carbon|null $published_at
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property Carbon $updated_at
 */
final class Article extends Model
{
    use HasUlid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tags' => 'array', 'published_at' => 'datetime'];
    }

    public function path(): string
    {
        return '/blog/'.$this->slug;
    }

    /**
     * @return array{id: string, slug: string, path: string, title: string, excerpt: string|null, cover_image_url: string|null, author_name: string|null, tags: list<string>, published_at: string|null}
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->ulid,
            'slug' => $this->slug,
            'path' => $this->path(),
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'cover_image_url' => $this->cover_image_url,
            'author_name' => $this->author_name,
            'tags' => array_values($this->tags),
            'published_at' => $this->published_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array{id: string, slug: string, path: string, title: string, excerpt: string|null, cover_image_url: string|null, author_name: string|null, tags: list<string>, published_at: string|null, body: string, meta: array{title: string, description: string|null}, updated_at: string}
     */
    public function toApi(): array
    {
        return $this->toSummary() + [
            'body' => $this->body,
            'meta' => ['title' => $this->meta_title ?? $this->title, 'description' => $this->meta_description ?? $this->excerpt],
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  iterable<self>  $articles
     * @return list<array{id: string, slug: string, path: string, title: string, excerpt: string|null, cover_image_url: string|null, author_name: string|null, tags: list<string>, published_at: string|null}>
     *
     * @scramble-return list<array{id: string, slug: string, path: string, title: string, excerpt: string|null, cover_image_url: string|null, author_name: string|null, tags: list<string>, published_at: string|null}>
     */
    public static function listToApi(iterable $articles): array
    {
        $list = [];

        foreach ($articles as $article) {
            $list[] = $article->toSummary();
        }

        return $list;
    }
}
