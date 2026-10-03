<?php

declare(strict_types=1);

namespace Modules\Settings\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\HtmlString;
use Modules\Settings\Contracts\OnboardingChecks;
use UnitEnum;

/**
 * What still has to happen before the store opens (docs/tenant-onboarding.md), computed from
 * the store's real state every time the page is opened.
 */
final class LaunchChecklistPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Launch checklist';

    protected static ?string $title = 'Launch checklist';

    protected static ?string $slug = 'settings/launch-checklist';

    protected static ?int $navigationSort = 0;

    public static function canAccess(): bool
    {
        $user = auth('staff')->user();

        return $user instanceof Authorizable && $user->can('settings.manage');
    }

    public function content(Schema $schema): Schema
    {
        $items = app(OnboardingChecks::class)->items();
        $done = count(array_filter($items, fn (array $item): bool => $item['done']));

        $html = '<ul class="space-y-3 text-sm">';
        foreach ($items as $item) {
            $html .= '<li><span aria-hidden="true">'.($item['done'] ? '✅' : '⬜').'</span> <span class="font-medium">'.e($item['label']).'</span>'
                .($item['done'] ? '<span class="sr-only"> (done)</span>' : '<br><span class="text-gray-500">'.e($item['hint']).'</span>').'</li>';
        }

        return $schema->components([
            Section::make($done.' of '.count($items).' done')->schema([Text::make(new HtmlString($html.'</ul>'))]),
        ]);
    }
}
