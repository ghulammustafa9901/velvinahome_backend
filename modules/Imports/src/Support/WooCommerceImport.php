<?php

declare(strict_types=1);

namespace Modules\Imports\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Catalog\Contracts\CatalogImport;
use Modules\Catalog\Data\ProductData;
use Modules\Customers\Contracts\AddressImport;
use Modules\Customers\Data\AddressData;
use Modules\Identity\Contracts\CustomerImport;
use Modules\Imports\Models\ImportedOrder;
use Modules\Imports\Models\ImportRun;
use Modules\Inventory\Contracts\StockImport;
use Modules\Seo\Contracts\Seo;
use Modules\Settings\Contracts\TenantSettings;
use Modules\Support\ValueObjects\Money;
use RuntimeException;
use Throwable;

/**
 * Moves a WooCommerce store into a brand: categories, products (simple and variable, with
 * stock and images), customers with their addresses, historical orders, and a 301 from
 * every old product and category URL to its new one.
 *
 * Every record is written through the owning module's import contract, one at a time: a bad
 * record becomes an issue in the report and the run goes on. In a dry run nothing is written;
 * the report shows what would happen.
 */
final class WooCommerceImport
{
    public const ENTITIES = ['categories', 'products', 'customers', 'orders'];

    private const MAX_IMAGES = 10;

    private const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    private Mappings $map;

    private string $source;

    private ImportLog $log;

    private bool $dryRun;

    private int $digits;

    /** @var array<int, string> WooCommerce category id => slug path ("parent/child") */
    private array $categoryPaths = [];

    public function __construct(
        private readonly CatalogImport $catalog,
        private readonly StockImport $stock,
        private readonly CustomerImport $customers,
        private readonly AddressImport $addresses,
        private readonly Seo $seo,
        private readonly TenantSettings $settings,
    ) {}

    public static function sourceKey(string $storeUrl): string
    {
        return 'woocommerce:'.mb_strtolower((string) parse_url($storeUrl, PHP_URL_HOST));
    }

    public function run(ImportRun $run, WooCommerceClient $client, ImportLog $log): void
    {
        $this->log = $log;
        $this->dryRun = $run->dry_run;
        $this->source = self::sourceKey((string) ($run->options['store_url'] ?? ''));
        $this->map = new Mappings($this->source);
        /** @var list<string> $entities */
        $entities = $run->options['entities'] ?? self::ENTITIES;

        $currency = $this->settings->string('store.currency', 'PKR');
        $this->digits = Money::zero($currency)->currency->minorUnitDigits();

        $log->step('checking');
        $theirs = strtoupper((string) ($client->get('data/currencies/current')['code'] ?? ''));

        if ($theirs !== $currency) {
            throw new RuntimeException("The store sells in {$theirs}, this brand in {$currency}. Prices cannot be imported across currencies.");
        }

        if (in_array('categories', $entities, true) || in_array('products', $entities, true)) {
            $this->categories($client);
        }

        if (in_array('products', $entities, true)) {
            $this->catalog->inBulk(fn () => $this->products($client));
        }

        if (in_array('customers', $entities, true)) {
            $this->customers($client);
        }

        if (in_array('orders', $entities, true)) {
            $this->orders($client, $currency);
        }
    }

    private function categories(WooCommerceClient $client): void
    {
        $this->log->step('categories');
        $pending = iterator_to_array($client->each('products/categories', ['orderby' => 'id']), false);

        // Parents before children: keep passing over the list until nothing more can be placed.
        while ($pending !== []) {
            $placed = 0;

            foreach ($pending as $index => $category) {
                $id = (int) ($category['id'] ?? 0);
                $parent = (int) ($category['parent'] ?? 0);

                if ($parent !== 0 && ! isset($this->categoryPaths[$parent])) {
                    continue;
                }

                unset($pending[$index]);
                $placed++;
                $slug = $this->slug($category['slug'] ?? null, (string) ($category['name'] ?? ''));
                $this->categoryPaths[$id] = ($parent === 0 ? '' : $this->categoryPaths[$parent].'/').rawurldecode((string) ($category['slug'] ?? $slug));
                $this->category($id, $parent, (string) ($category['name'] ?? ''), $slug, $category['description'] ?? null);
            }

            if ($placed === 0) {
                foreach ($pending as $category) {
                    $this->log->issue('error', 'category', (string) ($category['id'] ?? ''), 'Its parent category is missing.');
                    $this->log->count('categories', 'failed');
                }

                return;
            }
        }
    }

    private function category(int $id, int $parent, string $name, ?string $slug, mixed $description): void
    {
        $existing = $this->map->get('category', $id);

        if ($this->dryRun) {
            $this->log->count('categories', $existing === null ? 'would_create' : 'would_update');

            return;
        }

        try {
            $ulid = $this->catalog->saveCategory(
                $existing,
                mb_substr(trim(html_entity_decode($name)), 0, 255),
                $parent === 0 ? null : $this->map->get('category', $parent),
                $existing === null ? $slug : null,
                Text::plain(is_string($description) ? $description : null, 5000),
            );
            $this->map->put('category', $id, $ulid);
            $this->log->count('categories', $existing === null ? 'created' : 'updated');
            $this->redirect('/product-category/'.$this->categoryPaths[$id].'/', 'category', $ulid);
        } catch (Throwable $e) {
            $this->failed('categories', 'category', (string) $id, $e);
        }
    }

    private function products(WooCommerceClient $client): void
    {
        $this->log->step('products');

        foreach ($client->each('products', ['status' => 'any', 'orderby' => 'id', 'order' => 'asc']) as $product) {
            $id = (string) ($product['id'] ?? '');
            $type = (string) ($product['type'] ?? 'simple');

            if (! in_array($type, ['simple', 'variable'], true)) {
                $this->log->issue('warning', 'product', $id, "Skipped: {$type} products are not supported.");
                $this->log->count('products', 'skipped');

                continue;
            }

            try {
                $variations = $type === 'variable' ? iterator_to_array($client->each("products/{$id}/variations"), false) : [];
                [$data, $stock] = $this->productData($product, $variations);
                $this->product($client, $product, $data, $stock);
            } catch (Throwable $e) {
                $this->failed('products', 'product', $id, $e);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  list<array<string, mixed>>  $variations
     * @return array{0: ProductData, 1: array<string, int>} the product, and managed stock by SKU
     */
    private function productData(array $product, array $variations): array
    {
        $id = (string) $product['id'];
        $variants = [];
        $stock = [];
        $options = [];

        if ($variations === []) {
            $sku = $this->sku($product['sku'] ?? null, "WC-{$id}");
            $variants[] = $this->variant($sku, $product, []);
            $this->stockOf($product, $sku, $stock);
        } else {
            $names = [];
            foreach ((array) ($product['attributes'] ?? []) as $attribute) {
                if (is_array($attribute) && ($attribute['variation'] ?? false)) {
                    $names[] = mb_substr(trim((string) $attribute['name']), 0, 100);
                }
            }

            $values = array_fill_keys($names, []);

            foreach ($variations as $variation) {
                $chosen = [];
                foreach ((array) ($variation['attributes'] ?? []) as $attribute) {
                    if (is_array($attribute)) {
                        $chosen[mb_substr(trim((string) ($attribute['name'] ?? '')), 0, 100)] = mb_substr(trim((string) ($attribute['option'] ?? '')), 0, 100);
                    }
                }

                $chosen = array_intersect_key($chosen, $values);

                if (count($chosen) !== count($names) || in_array('', $chosen, true)) {
                    $this->log->issue('warning', 'variation', (string) ($variation['id'] ?? ''), "Skipped a variation of product {$id}: it does not name a value for every option (\"any\" values are not supported).");

                    continue;
                }

                $ordered = [];
                foreach ($names as $name) {
                    $ordered[$name] = $chosen[$name];
                    $values[$name][$chosen[$name]] = true;
                }

                $sku = $this->sku($variation['sku'] ?? null, "WC-{$id}-".($variation['id'] ?? count($variants)));
                $variants[] = $this->variant($sku, $variation, $ordered);
                $this->stockOf($variation, $sku, $stock);
            }

            foreach ($values as $name => $set) {
                $options[] = ['name' => $name, 'values' => array_map('strval', array_keys($set))];
            }
        }

        if ($variants === []) {
            throw new RuntimeException('No importable variant.');
        }

        $categories = [];
        foreach ((array) ($product['categories'] ?? []) as $category) {
            $ulid = is_array($category) ? $this->map->get('category', (int) ($category['id'] ?? 0)) : null;
            if ($ulid !== null) {
                $categories[] = $ulid;
            }
        }

        $tags = [];
        foreach ((array) ($product['tags'] ?? []) as $tag) {
            if (is_array($tag) && trim((string) ($tag['name'] ?? '')) !== '') {
                $tags[] = mb_substr(trim((string) $tag['name']), 0, 50);
            }
        }

        $data = ProductData::validateAndCreate(Payload::compact([
            'title' => mb_substr(trim(html_entity_decode((string) ($product['name'] ?? ''))), 0, 255) ?: "Product {$id}",
            'slug' => $this->slug($product['slug'] ?? null, (string) ($product['name'] ?? '')),
            'description' => Text::plain(is_string($product['description'] ?? null) ? $product['description'] : null, 20000),
            'shortDescription' => Text::plain(is_string($product['short_description'] ?? null) ? $product['short_description'] : null, 500),
            'categoryUlids' => array_values(array_unique($categories)),
            'tags' => $tags,
            'options' => $options,
            'variants' => $variants,
        ]));

        return [$data, $stock];
    }

    /**
     * @param  array<string, mixed>  $source  a simple product or a variation
     * @param  array<string, string>  $optionValues
     * @return array<string, mixed>
     */
    private function variant(string $sku, array $source, array $optionValues): array
    {
        $regular = Decimal::scaled($source['regular_price'] ?? null, $this->digits);
        $sale = Decimal::scaled($source['sale_price'] ?? null, $this->digits);
        $price = $sale !== null && $regular !== null && $sale < $regular ? $sale : ($regular ?? Decimal::scaled($source['price'] ?? null, $this->digits));

        if ($price === null || $price < 0) {
            throw new RuntimeException("Variant {$sku} has no price.");
        }

        $grams = Decimal::scaled($source['weight'] ?? null, 3);

        return [
            'sku' => $sku,
            'priceAmount' => $price,
            'compareAtPriceAmount' => $price === $sale ? $regular : null,
            'weightGrams' => $grams !== null && $grams > 0 ? $grams : null,
            'allowBackorder' => in_array($source['backorders'] ?? 'no', ['notify', 'yes'], true),
            'optionValues' => $optionValues,
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, int>  $stock
     */
    private function stockOf(array $source, string $sku, array &$stock): void
    {
        if (($source['manage_stock'] ?? false) === true && is_int($source['stock_quantity'] ?? null)) {
            $stock[$sku] = max(0, $source['stock_quantity']);
        }
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<string, int>  $stock
     */
    private function product(WooCommerceClient $client, array $product, ProductData $data, array $stock): void
    {
        $id = (string) $product['id'];
        $existing = $this->map->get('product', $id);
        $active = ($product['status'] ?? '') === 'publish';

        if ($this->dryRun) {
            $this->log->count('products', $existing === null ? 'would_create' : 'would_update');
            $this->log->count('variants', 'found', count($data->variants));

            return;
        }

        $saved = $this->catalog->saveProduct($existing, $data, $active);
        $this->map->put('product', $id, $saved['product']);
        $this->log->count('products', $existing === null ? 'created' : 'updated');

        foreach ($saved['skipped_skus'] as $sku) {
            $this->log->issue('warning', 'product', $id, "Variant {$sku} is new since the last import; add it in the admin.");
        }

        $quantities = [];
        foreach ($stock as $sku => $quantity) {
            if (isset($saved['variants'][$sku])) {
                $quantities[$saved['variants'][$sku]] = $quantity;
            }
        }

        if ($quantities !== []) {
            $this->stock->count($quantities, 'Opening stock from WooCommerce product '.$id);
        }

        if (! $this->catalog->hasImages($saved['product'])) {
            $this->images($client, $saved['product'], $id, (array) ($product['images'] ?? []));
        }

        $old = parse_url((string) ($product['permalink'] ?? ''), PHP_URL_PATH);
        if (is_string($old) && $old !== '' && $old !== '/') {
            $this->redirect($old, 'product', $saved['product']);
        }
    }

    /**
     * @param  array<mixed>  $images
     */
    private function images(WooCommerceClient $client, string $productUlid, string $id, array $images): void
    {
        foreach (array_slice($images, 0, self::MAX_IMAGES) as $image) {
            if (! is_array($image) || ! is_string($image['src'] ?? null)) {
                continue;
            }

            $path = null;

            try {
                $path = $client->download($image['src'], self::MAX_IMAGE_BYTES);
                $alt = trim((string) ($image['alt'] ?? ''));
                $this->catalog->attachImage($productUlid, $path, $alt === '' ? null : mb_substr($alt, 0, 255));
                $this->log->count('images', 'imported');
            } catch (Throwable $e) {
                $this->log->issue('warning', 'image', $id, 'Image not imported ('.$image['src'].'): '.Messages::of($e));
                $this->log->count('images', 'failed');
            } finally {
                if ($path !== null && is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function customers(WooCommerceClient $client): void
    {
        $this->log->step('customers');

        foreach ($client->each('customers', ['role' => 'all', 'orderby' => 'id']) as $customer) {
            $id = (string) ($customer['id'] ?? '');
            $email = trim((string) ($customer['email'] ?? ''));

            if ($email === '') {
                $this->log->count('customers', 'skipped');

                continue;
            }

            $existing = $this->map->get('customer', $id);

            if ($this->dryRun) {
                $this->log->count('customers', $existing === null ? 'would_create' : 'would_update');

                continue;
            }

            try {
                $billing = (array) ($customer['billing'] ?? []);
                $shipping = (array) ($customer['shipping'] ?? []);
                $phone = trim((string) ($billing['phone'] ?? ''));

                $customerId = $this->customers->upsert($email, $customer['first_name'] ?? null, $customer['last_name'] ?? null, $phone === '' ? null : $phone);
                $this->map->put('customer', $id, (string) $customerId);
                $this->log->count('customers', $existing === null ? 'created' : 'updated');

                $address = $this->address(($shipping['address_1'] ?? '') !== '' ? $shipping : $billing, $phone);

                if ($address !== null && $this->addresses->add($customerId, $address, true)) {
                    $this->log->count('addresses', 'created');
                }
            } catch (Throwable $e) {
                $this->failed('customers', 'customer', $id, $e);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function address(array $source, string $fallbackPhone): ?AddressData
    {
        $value = fn (string $key, int $max): string => mb_substr(trim((string) ($source[$key] ?? '')), 0, $max);
        $phone = $value('phone', 20) ?: mb_substr($fallbackPhone, 0, 20);

        if ($value('address_1', 255) === '' || $value('city', 100) === '' || $phone === '' || $value('first_name', 100) === '') {
            return null;
        }

        $country = strtoupper($value('country', 2));

        return new AddressData(
            firstName: $value('first_name', 100),
            lastName: $value('last_name', 100) ?: '-',
            phone: $phone,
            line1: $value('address_1', 255),
            city: $value('city', 100),
            line2: $value('address_2', 255) ?: null,
            province: $value('state', 100) ?: null,
            postalCode: $value('postcode', 20) ?: null,
            countryCode: strlen($country) === 2 ? $country : 'PK',
        );
    }

    private function orders(WooCommerceClient $client, string $storeCurrency): void
    {
        $this->log->step('orders');
        $source = $this->source;

        foreach ($client->each('orders', ['status' => 'any', 'orderby' => 'id', 'order' => 'asc']) as $order) {
            $id = (string) ($order['id'] ?? '');
            $exists = ImportedOrder::query()->where('source', $source)->where('external_id', $id)->exists();

            if ($this->dryRun) {
                $this->log->count('orders', $exists ? 'would_update' : 'would_create');

                continue;
            }

            try {
                $this->order($source, $order, $storeCurrency);
                $this->log->count('orders', $exists ? 'updated' : 'created');
            } catch (Throwable $e) {
                $this->failed('orders', 'order', $id, $e);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function order(string $source, array $order, string $storeCurrency): void
    {
        $currency = strtoupper((string) ($order['currency'] ?? $storeCurrency));
        $digits = Money::zero($currency)->currency->minorUnitDigits();
        $minor = fn (mixed $value): int => Decimal::scaled($value, $digits) ?? 0;
        $billing = (array) ($order['billing'] ?? []);

        $lines = [];
        $subtotal = 0;
        foreach ((array) ($order['line_items'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $subtotal += $minor($line['subtotal'] ?? '0');
            $lines[] = [
                'title' => mb_substr(trim((string) ($line['name'] ?? '')), 0, 255),
                'sku' => ($sku = trim((string) ($line['sku'] ?? ''))) === '' ? null : mb_substr($sku, 0, 100),
                'quantity' => (int) ($line['quantity'] ?? 0),
                'total' => $minor($line['total'] ?? '0'),
            ];
        }

        $shipping = $this->address((array) ($order['shipping'] ?? []), (string) ($billing['phone'] ?? ''));
        $customerRef = (int) ($order['customer_id'] ?? 0) > 0 ? $this->map->get('customer', (int) $order['customer_id']) : null;
        $name = trim(((string) ($billing['first_name'] ?? '')).' '.((string) ($billing['last_name'] ?? '')));

        ImportedOrder::query()->updateOrCreate(['source' => $source, 'external_id' => (string) $order['id']], [
            'number' => mb_substr((string) ($order['number'] ?? $order['id']), 0, 64),
            'status' => mb_substr((string) ($order['status'] ?? 'unknown'), 0, 32),
            'currency' => $currency,
            'subtotal_amount' => $subtotal,
            'discount_amount' => $minor($order['discount_total'] ?? '0'),
            'shipping_amount' => $minor($order['shipping_total'] ?? '0'),
            'tax_amount' => $minor($order['total_tax'] ?? '0'),
            'total_amount' => $minor($order['total'] ?? '0'),
            'email' => ($email = trim((string) ($billing['email'] ?? ''))) === '' ? null : mb_strtolower(mb_substr($email, 0, 255)),
            'customer_id' => $customerRef === null ? null : (int) $customerRef,
            'customer_name' => $name === '' ? null : mb_substr($name, 0, 255),
            'phone' => ($phone = trim((string) ($billing['phone'] ?? ''))) === '' ? null : mb_substr($phone, 0, 32),
            'payment_method' => ($method = trim((string) ($order['payment_method_title'] ?? ''))) === '' ? null : mb_substr($method, 0, 255),
            'shipping_address' => $shipping === null ? null : [
                'name' => trim($shipping->firstName.' '.$shipping->lastName),
                'line1' => $shipping->line1, 'line2' => $shipping->line2, 'city' => $shipping->city,
                'province' => $shipping->province, 'postal_code' => $shipping->postalCode, 'country' => $shipping->countryCode,
            ],
            'lines' => $lines,
            'placed_at' => $this->gmt($order['date_created_gmt'] ?? null) ?? Carbon::now(),
            'completed_at' => $this->gmt($order['date_completed_gmt'] ?? null),
        ]);
    }

    private function redirect(string $old, string $type, string $ulid): void
    {
        $new = $this->catalog->path($type, $ulid);

        if ($new === null) {
            return;
        }

        $this->seo->recordMove($old, $new, 'import');
        $this->log->count('redirects', 'recorded');
    }

    private function sku(mixed $sku, string $fallback): string
    {
        $sku = trim((string) $sku);

        return mb_substr($sku === '' ? $fallback : $sku, 0, 100);
    }

    private function slug(mixed $slug, string $name): ?string
    {
        $clean = Str::slug(rawurldecode((string) $slug)) ?: Str::slug($name);

        return $clean === '' ? null : mb_substr($clean, 0, 200);
    }

    private function gmt(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value, 'UTC') : null;
    }

    private function failed(string $counter, string $entity, string $ref, Throwable $e): void
    {
        $this->log->issue('error', $entity, $ref, Messages::of($e));
        $this->log->count($counter, 'failed');
    }
}
