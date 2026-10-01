<?php

declare(strict_types=1);

namespace Modules\Content\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Content\Models\Article;
use Modules\Content\Support\Sections;
use Modules\Seo\Contracts\Seo;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Create or edit a blog post. Publishing stamps the date once; a changed address of a
 * published post leaves a 301 behind (spec §6.13).
 */
final class SaveArticle implements Action
{
    use ChecksPermission;

    public function __construct(private readonly Seo $seo) {}

    /**
     * @param  list<string>  $tags
     */
    public function handle(
        ?Article $article,
        string $title,
        ?string $slug,
        string $body,
        ?string $excerpt = null,
        ?string $coverImageUrl = null,
        ?string $authorName = null,
        array $tags = [],
        bool $published = false,
        ?string $metaTitle = null,
        ?string $metaDescription = null,
        ?Authorizable $actor = null,
    ): Article {
        $this->authorizePermission($actor, 'content.manage');

        $slug = Str::slug($slug === null || trim($slug) === '' ? $title : $slug);
        $blank = fn (?string $value): ?string => $value === null || trim($value) === '' ? null : trim($value);

        Validator::make(
            ['title' => $title, 'slug' => $slug, 'body' => $body, 'excerpt' => $excerpt, 'cover_image_url' => $blank($coverImageUrl), 'author_name' => $authorName, 'tags' => $tags],
            [
                'title' => ['required', 'string', 'max:255'],
                'slug' => ['required', 'string', 'max:120'],
                'body' => ['required', 'string', 'max:100000'],
                'excerpt' => ['nullable', 'string', 'max:500'],
                'cover_image_url' => ['nullable', 'string', 'max:2048', Sections::LINK],
                'author_name' => ['nullable', 'string', 'max:120'],
                'tags' => ['array', 'max:10'],
                'tags.*' => ['string', 'max:40'],
            ],
        )->validate();

        if (Article::query()->where('slug', $slug)->when($article !== null, fn ($query) => $query->whereKeyNot($article?->id))->exists()) {
            throw ValidationException::withMessages(['slug' => __('Another post already uses this address.')]);
        }

        $oldPath = $article !== null && $article->status === 'published' ? $article->path() : null;

        $article ??= new Article;
        $article->fill([
            'title' => trim($title),
            'slug' => $slug,
            'body' => trim($body),
            'excerpt' => $blank($excerpt),
            'cover_image_url' => $blank($coverImageUrl),
            'author_name' => $blank($authorName),
            'tags' => array_values(array_unique(array_filter(array_map(fn (string $tag): string => mb_strtolower(trim($tag)), $tags)))),
            'status' => $published ? 'published' : 'draft',
            'published_at' => $published ? ($article->published_at ?? Carbon::now()) : $article->published_at,
            'meta_title' => $blank($metaTitle) === null ? null : mb_substr((string) $blank($metaTitle), 0, 255),
            'meta_description' => $blank($metaDescription) === null ? null : mb_substr((string) $blank($metaDescription), 0, 320),
        ])->save();

        if ($oldPath !== null && $oldPath !== $article->path()) {
            $this->seo->recordMove($oldPath, $article->path());
        }

        return $article;
    }
}
