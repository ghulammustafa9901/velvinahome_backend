<?php

declare(strict_types=1);

namespace Modules\Imports\Support;

use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Contracts\CatalogImport;
use Modules\Catalog\Data\ProductData;
use Modules\Imports\Actions\StartCsvImport;
use Modules\Imports\Models\ImportRun;
use Modules\Inventory\Contracts\StockImport;
use Modules\Settings\Contracts\TenantSettings;
use Modules\Support\ValueObjects\Money;
use RuntimeException;
use Throwable;

/**
 * Spreadsheet imports.
 *
 * Products — one row per variant; rows sharing a `handle` form one product:
 *   handle, title, description, category (slug), status (active|draft),
 *   option1_name, option1_value … option3_name, option3_value,
 *   sku, price, compare_at_price, weight_grams, barcode, stock
 * Stock — sku, quantity (counted on hand at the default location).
 *
 * Prices are in major units ("1299.50"). Every row is checked; a bad product or row is
 * reported and skipped, the rest are imported. A dry run writes nothing.
 */
final class CsvImport
{
    public const MAX_ROWS = 20000;

    public const PRODUCT_COLUMNS = ['handle', 'title', 'sku', 'price'];

    public const STOCK_COLUMNS = ['sku', 'quantity'];

    public function __construct(
        private readonly CatalogImport $catalog,
        private readonly StockImport $stock,
        private readonly TenantSettings $settings,
    ) {}

    public function run(ImportRun $run, ImportLog $log): void
    {
        $disk = Storage::disk(StartCsvImport::DISK);
        $stream = $run->file_path === null ? null : $disk->readStream($run->file_path);

        if (! is_resource($stream)) {
            throw new RuntimeException('The uploaded file is missing.');
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'csvimp');

        try {
            file_put_contents($path, $stream);

            $rows = $this->rows($path, $run->source === ImportRun::SOURCE_CSV_PRODUCTS ? self::PRODUCT_COLUMNS : self::STOCK_COLUMNS);

            $run->source === ImportRun::SOURCE_CSV_PRODUCTS
                ? $this->products($rows, $run->dry_run, $log)
                : $this->stockLevels($rows, $run->dry_run, $log);
        } finally {
            fclose($stream);
            @unlink($path);
            $disk->delete((string) $run->file_path);
        }
    }

    /**
     * @param  list<string>  $required
     * @return list<array{line: int, values: array<string, string>}>
     */
    private function rows(string $path, array $required): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The file could not be read.');
        }

        try {
            $header = fgetcsv($handle, escape: '');

            if (! is_array($header)) {
                throw new RuntimeException('The file is empty.');
            }

            $columns = array_map(fn (?string $name): string => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $name) ?? '')), $header);
            $missing = array_diff($required, $columns);

            if ($missing !== []) {
                throw new RuntimeException('Missing columns: '.implode(', ', $missing).'.');
            }

            $rows = [];
            for ($line = 2; ($values = fgetcsv($handle, escape: '')) !== false; $line++) {
                if ($values === [null] || $values === ['']) {
                    continue;
                }

                if (count($rows) >= self::MAX_ROWS) {
                    throw new RuntimeException('More than '.self::MAX_ROWS.' rows: split the file.');
                }

                $values = array_pad(array_slice($values, 0, count($columns)), count($columns), '');
                $rows[] = ['line' => $line, 'values' => array_combine($columns, array_map(fn (?string $v): string => trim((string) $v), $values))];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     */
    private function products(array $rows, bool $dryRun, ImportLog $log): void
    {
        $log->step('products');
        $digits = Money::zero($this->settings->string('store.currency', 'PKR'))->currency->minorUnitDigits();
        $mappings = new Mappings('csv');

        $groups = [];
        foreach ($rows as $row) {
            $groups[mb_strtolower($row['values']['handle'])][] = $row;
        }

        foreach ($groups as $handle => $group) {
            $first = $group[0]['values'];
            $ref = 'line '.$group[0]['line'];

            try {
                if ($handle === '') {
                    throw new RuntimeException('Every row needs a handle.');
                }

                [$data, $stock] = $this->productData($group, $digits);
                $existing = $mappings->get('product', $handle);

                if ($dryRun) {
                    $log->count('products', $existing === null ? 'would_create' : 'would_update');
                    $log->count('variants', 'found', count($data->variants));

                    continue;
                }

                $saved = $this->catalog->saveProduct($existing, $data, strtolower($first['status'] ?? 'draft') === 'active');
                $mappings->put('product', $handle, $saved['product']);
                $log->count('products', $existing === null ? 'created' : 'updated');

                foreach ($saved['skipped_skus'] as $sku) {
                    $log->issue('warning', 'product', $handle, "Variant {$sku} is new; add it in the admin.");
                }

                $quantities = [];
                foreach ($stock as $sku => $quantity) {
                    if (isset($saved['variants'][$sku])) {
                        $quantities[$saved['variants'][$sku]] = $quantity;
                    }
                }

                if ($quantities !== []) {
                    $this->stock->count($quantities, 'Stock from product CSV import');
                }
            } catch (Throwable $e) {
                $log->issue('error', 'product', $handle === '' ? $ref : $handle, Messages::of($e));
                $log->count('products', 'failed');
            }
        }
    }

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $group
     * @return array{0: ProductData, 1: array<string, int>}
     */
    private function productData(array $group, int $digits): array
    {
        $first = $group[0]['values'];
        $names = [];
        foreach ([1, 2, 3] as $n) {
            if (($first["option{$n}_name"] ?? '') !== '') {
                $names[$n] = mb_substr($first["option{$n}_name"], 0, 100);
            }
        }

        $values = array_fill_keys($names, []);
        $variants = [];
        $stock = [];

        foreach ($group as $row) {
            $v = $row['values'];
            $price = Decimal::scaled($v['price'] ?? '', $digits);
            $compare = ($v['compare_at_price'] ?? '') === '' ? null : Decimal::scaled($v['compare_at_price'], $digits);

            if ($price === null || $price < 0 || ($compare !== null && $compare < 0)) {
                throw new RuntimeException("Line {$row['line']}: price must be a number like 1299.50.");
            }

            $optionValues = [];
            foreach ($names as $n => $name) {
                $value = mb_substr($v["option{$n}_value"] ?? '', 0, 100);

                if ($value === '') {
                    throw new RuntimeException("Line {$row['line']}: {$name} has no value.");
                }

                $optionValues[$name] = $value;
                $values[$name][$value] = true;
            }

            $grams = ($v['weight_grams'] ?? '') === '' ? null : filter_var($v['weight_grams'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            if ($grams === false) {
                throw new RuntimeException("Line {$row['line']}: weight_grams must be a whole number.");
            }

            $sku = mb_substr($v['sku'] ?? '', 0, 100);
            $variants[] = [
                'sku' => $sku,
                'priceAmount' => $price,
                'compareAtPriceAmount' => $compare,
                'barcode' => ($v['barcode'] ?? '') === '' ? null : mb_substr($v['barcode'], 0, 100),
                'weightGrams' => $grams,
                'optionValues' => $optionValues,
            ];

            if (($v['stock'] ?? '') !== '') {
                $quantity = filter_var($v['stock'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

                if ($quantity === false) {
                    throw new RuntimeException("Line {$row['line']}: stock must be a whole number.");
                }

                $stock[$sku] = $quantity;
            }
        }

        $category = ($first['category'] ?? '') === '' ? null : $this->catalog->categoryBySlug($first['category']);

        if (($first['category'] ?? '') !== '' && $category === null) {
            throw new RuntimeException("No category with the slug \"{$first['category']}\".");
        }

        $data = ProductData::validateAndCreate(Payload::compact([
            'title' => mb_substr($first['title'], 0, 255),
            'description' => ($first['description'] ?? '') === '' ? null : mb_substr($first['description'], 0, 20000),
            'categoryUlids' => $category === null ? [] : [$category],
            'options' => array_map(fn (string $name): array => ['name' => $name, 'values' => array_map('strval', array_keys($values[$name]))], array_values($names)),
            'variants' => $variants,
        ]));

        return [$data, $stock];
    }

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     */
    private function stockLevels(array $rows, bool $dryRun, ImportLog $log): void
    {
        $log->step('stock');
        $known = $this->catalog->variantsBySku(array_map(fn (array $row): string => $row['values']['sku'], $rows));
        $quantities = [];

        foreach ($rows as $row) {
            $sku = $row['values']['sku'];
            $quantity = filter_var($row['values']['quantity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            if (! isset($known[$sku])) {
                $log->issue('error', 'stock', 'line '.$row['line'], "No variant with the SKU \"{$sku}\".");
                $log->count('stock', 'failed');
            } elseif ($quantity === false) {
                $log->issue('error', 'stock', 'line '.$row['line'], 'Quantity must be a whole number, 0 or more.');
                $log->count('stock', 'failed');
            } else {
                $quantities[$known[$sku]] = $quantity;
                $log->count('stock', $dryRun ? 'would_count' : 'counted');
            }
        }

        if (! $dryRun && $quantities !== []) {
            foreach (array_chunk($quantities, 500, true) as $chunk) {
                $log->count('stock', 'changed', $this->stock->count($chunk, 'Stock CSV import'));
            }
        }
    }
}
