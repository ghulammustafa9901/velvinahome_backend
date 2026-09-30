<?php

declare(strict_types=1);

namespace Modules\Content\Filament\Resources\Menus;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Content\Actions\SaveMenu;
use Modules\Content\Models\Menu;

final class ListMenusPage extends ListRecords
{
    protected static string $resource = MenuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->schema(MenuResource::fields())
                ->using(fn (array $data): Menu => app(SaveMenu::class)->handle(
                    null, (string) $data['key'], (string) $data['name'], array_values((array) ($data['items'] ?? [])), MenuResource::actor(),
                )),
        ];
    }
}
