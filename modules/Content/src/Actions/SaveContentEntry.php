<?php

declare(strict_types=1);

namespace Modules\Content\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Content\Models\ContentEntry;
use Modules\Content\Models\ContentType;
use Modules\Content\Support\ContentFields;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

final class SaveContentEntry implements Action
{
    use ChecksPermission;

    /**
     * @param  array<string, mixed>  $values
     */
    public function handle(ContentType $type, ?ContentEntry $entry, string $title, ?string $slug, array $values, bool $published = false, int $position = 0, ?Authorizable $actor = null): ContentEntry
    {
        $this->authorizePermission($actor, 'content.manage');

        $slug = Str::slug($slug === null || trim($slug) === '' ? $title : $slug);

        if (trim($title) === '' || mb_strlen($title) > 255 || $slug === '' || strlen($slug) > 120) {
            throw ValidationException::withMessages(['title' => __('Give the entry a title of up to 255 characters.')]);
        }

        if (ContentEntry::query()->where('content_type_id', $type->id)->where('slug', $slug)->when($entry !== null, fn ($query) => $query->whereKeyNot($entry?->id))->exists()) {
            throw ValidationException::withMessages(['slug' => __('Another entry of this type already uses this address.')]);
        }

        $entry ??= new ContentEntry(['content_type_id' => $type->id]);
        $entry->fill([
            'title' => trim($title),
            'slug' => $slug,
            'values' => ContentFields::cleanValues($type->fields, $values),
            'status' => $published ? 'published' : 'draft',
            'position' => $position,
        ])->save();

        return $entry;
    }
}
