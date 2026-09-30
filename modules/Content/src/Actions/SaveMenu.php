<?php

declare(strict_types=1);

namespace Modules\Content\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Validation\ValidationException;
use Modules\Content\Models\Menu;
use Modules\Content\Support\Sections;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

final class SaveMenu implements Action
{
    use ChecksPermission;

    /**
     * @param  array<int, mixed>  $items
     */
    public function handle(?Menu $menu, string $key, string $name, array $items, ?Authorizable $actor = null): Menu
    {
        $this->authorizePermission($actor, 'content.manage');

        if (preg_match('/^[a-z][a-z0-9_-]{1,49}$/', $key) !== 1) {
            throw ValidationException::withMessages(['key' => __('Use lowercase letters, digits, - or _ (e.g. main, footer).')]);
        }

        if (Menu::query()->where('key', $key)->when($menu !== null, fn ($query) => $query->whereKeyNot($menu?->id))->exists()) {
            throw ValidationException::withMessages(['key' => __('Another menu already uses this key.')]);
        }

        $menu ??= new Menu;
        $menu->fill(['key' => $key, 'name' => trim($name), 'items' => Sections::cleanMenu($items)])->save();

        return $menu;
    }
}
