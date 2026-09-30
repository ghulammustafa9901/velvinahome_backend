<?php

declare(strict_types=1);

namespace Modules\Content\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Validation\ValidationException;
use Modules\Content\Models\Page;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

final class ChangePageStatus implements Action
{
    use ChecksPermission;

    /**
     * @param  'draft'|'published'  $status
     */
    public function handle(Page $page, string $status, ?Authorizable $actor = null): Page
    {
        $this->authorizePermission($actor, 'content.manage');

        if ($status === 'draft' && $page->slug === Page::HOME) {
            throw ValidationException::withMessages(['status' => __('The home page cannot be unpublished.')]);
        }

        $page->forceFill([
            'status' => $status,
            'published_at' => $status === 'published' ? ($page->published_at ?? now()) : null,
        ])->save();

        return $page;
    }
}
