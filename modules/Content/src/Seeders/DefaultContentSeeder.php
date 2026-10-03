<?php

declare(strict_types=1);

namespace Modules\Content\Seeders;

use Illuminate\Database\Seeder;
use Modules\Content\Models\Menu;
use Modules\Content\Models\Page;
use Modules\Settings\Contracts\StoreSettings;

/**
 * A working home page and menus for a new brand (spec §4.6), so the default theme renders a
 * complete store on day one. Idempotent: never touches a page or menu the store already has.
 */
final class DefaultContentSeeder extends Seeder
{
    public function run(StoreSettings $settings): void
    {
        $store = $settings->string('store.name', 'our store');

        if (! Page::query()->where('slug', Page::HOME)->exists()) {
            Page::query()->create([
                'slug' => Page::HOME,
                'title' => $store,
                'status' => 'published',
                'published_at' => now(),
                'sections' => [
                    ['type' => 'hero', 'data' => ['heading' => 'Welcome to '.$store, 'subheading' => 'New arrivals every week.', 'cta_label' => 'Shop now', 'cta_url' => '/products']],
                    ['type' => 'product_grid', 'data' => ['title' => 'New arrivals', 'source' => 'newest', 'limit' => 8]],
                    ['type' => 'newsletter', 'data' => ['heading' => 'Stay in the loop', 'text' => 'New collections and offers, straight to your inbox.']],
                ],
            ]);
        }

        Menu::query()->firstOrCreate(['key' => 'main'], ['name' => 'Main menu', 'items' => [
            ['label' => 'Home', 'url' => '/', 'children' => []],
            ['label' => 'Shop all', 'url' => '/products', 'children' => []],
        ]]);

        Menu::query()->firstOrCreate(['key' => 'footer'], ['name' => 'Footer', 'items' => [
            ['label' => 'Shop all', 'url' => '/products', 'children' => []],
            ['label' => 'My account', 'url' => '/account', 'children' => []],
        ]]);
    }
}
