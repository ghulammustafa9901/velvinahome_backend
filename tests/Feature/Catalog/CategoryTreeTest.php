<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Catalog\Actions\SaveCategory;
use Modules\Catalog\Models\Category;

/*
| ADR-0006: unlimited depth through a closure table, maintained on create and move.
*/

beforeEach(function (): void {
    $this->save = app(SaveCategory::class);
    $this->tag = Str::random(5);
});

function ancestorNames(Category $category): array
{
    return $category->fresh()?->ancestors()->pluck('name')->all() ?? [];
}

it('records every ancestor of a deep category', function (): void {
    $home = $this->save->handle(null, 'Home '.$this->tag);
    $living = $this->save->handle(null, 'Living '.$this->tag, $home->ulid);
    $cushions = $this->save->handle(null, 'Cushions '.$this->tag, $living->ulid);

    expect(ancestorNames($cushions))->toBe(['Home '.$this->tag, 'Living '.$this->tag])
        ->and($home->descendantsAndSelf()->count())->toBe(3);
});

it('moves a whole subtree and re-links it to its new ancestors', function (): void {
    $home = $this->save->handle(null, 'Home '.$this->tag);
    $garden = $this->save->handle(null, 'Garden '.$this->tag);
    $living = $this->save->handle(null, 'Living '.$this->tag, $home->ulid);
    $cushions = $this->save->handle(null, 'Cushions '.$this->tag, $living->ulid);

    $this->save->handle($living, $living->name, $garden->ulid, $living->slug);

    expect(ancestorNames($cushions))->toBe(['Garden '.$this->tag, 'Living '.$this->tag])
        ->and($home->descendantsAndSelf()->count())->toBe(1)
        ->and($garden->descendantsAndSelf()->count())->toBe(3);
});

it('refuses to move a category under its own descendant', function (): void {
    $home = $this->save->handle(null, 'Home '.$this->tag);
    $living = $this->save->handle(null, 'Living '.$this->tag, $home->ulid);

    $this->save->handle($home, $home->name, $living->ulid, $home->slug);
})->throws(ValidationException::class);
