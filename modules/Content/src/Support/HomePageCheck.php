<?php

declare(strict_types=1);

namespace Modules\Content\Support;

use Modules\Content\Models\Page;
use Modules\Settings\Contracts\OnboardingCheck;

final class HomePageCheck implements OnboardingCheck
{
    public function label(): string
    {
        return 'Home page published';
    }

    public function hint(): string
    {
        return 'Content → Pages: publish the home page.';
    }

    public function passes(): bool
    {
        return Page::query()->where('slug', Page::HOME)->where('status', 'published')->exists();
    }
}
