<?php

declare(strict_types=1);

namespace Modules\Content\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Content\Models\Page;
use Modules\Content\Support\Sections;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

final class SavePage implements Action
{
    use ChecksPermission;

    /**
     * @param  array<int, mixed>  $sections
     */
    public function handle(?Page $page, string $title, ?string $slug, array $sections, ?string $metaTitle = null, ?string $metaDescription = null, ?Authorizable $actor = null): Page
    {
        $this->authorizePermission($actor, 'content.manage');

        $slug = Str::slug($slug === null || trim($slug) === '' ? $title : $slug);

        if ($slug === '' || strlen($slug) > 120) {
            throw ValidationException::withMessages(['slug' => __('Give the page a short web address.')]);
        }

        if (Page::query()->where('slug', $slug)->when($page !== null, fn ($query) => $query->whereKeyNot($page?->id))->exists()) {
            throw ValidationException::withMessages(['slug' => __('Another page already uses this address.')]);
        }

        if ($page?->slug === Page::HOME && $slug !== Page::HOME) {
            throw ValidationException::withMessages(['slug' => __('The home page keeps the address "home".')]);
        }

        $page ??= new Page;
        $page->fill([
            'title' => trim($title),
            'slug' => $slug,
            'sections' => Sections::clean($sections),
            'meta_title' => $metaTitle === null || trim($metaTitle) === '' ? null : mb_substr(trim($metaTitle), 0, 255),
            'meta_description' => $metaDescription === null || trim($metaDescription) === '' ? null : mb_substr(trim($metaDescription), 0, 320),
        ])->save();

        return $page;
    }
}
