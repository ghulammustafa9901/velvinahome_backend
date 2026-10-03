<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Scout\Jobs\MakeSearchable;
use Modules\Catalog\Models\ProductVariant;
use Modules\Imports\Actions\StartCsvImport;
use Modules\Imports\Models\ImportRun;
use Modules\Inventory\Contracts\StockReader;
use Tests\Support\TestPlatform;

/*
| Spreadsheet imports: products with variants, and stock counts. Bad rows are reported and
| skipped; a dry run writes nothing; the uploaded file is removed afterwards.
*/

beforeEach(function (): void {
    $this->brand = TestPlatform::brand(TestPlatform::BRAND_B);
    tenancy()->initialize($this->brand);
    $this->owner = TestPlatform::staff($this->brand, role: 'Owner');
    $this->sku = strtoupper(Str::random(8));
});

function csvRun(object $test, string $source, string $csv, bool $dryRun): ImportRun
{
    $path = 'imports/'.Str::random(12).'.csv';
    Storage::disk(StartCsvImport::DISK)->put($path, $csv);

    return app(StartCsvImport::class)->handle($source, $path, $dryRun, $test->owner)->refresh();
}

it('imports products with variants and stock from a CSV, reporting the bad ones', function (): void {
    $s = $this->sku;
    $csv = "\u{FEFF}handle,title,description,status,option1_name,option1_value,sku,price,compare_at_price,weight_grams,stock\n"
        ."kurta-{$s},Cotton Kurta,Breathable cotton,active,Size,S,{$s}-S,2500,2999.99,400,4\n"
        ."kurta-{$s},Cotton Kurta,,active,Size,M,{$s}-M,2600.5,,400,\n"
        ."mug-{$s},Mug,,draft,,,{$s}-MUG,abc,,,\n";

    $dry = csvRun($this, ImportRun::SOURCE_CSV_PRODUCTS, $csv, true);
    expect($dry->status)->toBe('completed')
        ->and($dry->counts['products'])->toEqual(['would_create' => 1, 'failed' => 1])
        ->and(ProductVariant::query()->where('sku', "{$s}-S")->exists())->toBeFalse();

    $run = csvRun($this, ImportRun::SOURCE_CSV_PRODUCTS, $csv, false);
    $small = ProductVariant::query()->where('sku', "{$s}-S")->firstOrFail();
    $medium = ProductVariant::query()->where('sku', "{$s}-M")->firstOrFail();

    expect($run->counts['products'])->toEqual(['created' => 1, 'failed' => 1])
        ->and($run->issues[0]['message'])->toContain('price must be a number')
        ->and($small->product_id)->toBe($medium->product_id)
        ->and($small->price->amount)->toBe(250_000)
        ->and($small->compare_at_price?->amount)->toBe(299_999)
        ->and($medium->price->amount)->toBe(260_050)
        ->and(app(StockReader::class)->available([$small->id])[$small->id])->toBe(4)
        ->and(Storage::disk(StartCsvImport::DISK)->files('imports'))->not->toContain($run->file_path);
});

it('sets counted stock by SKU and reports unknown SKUs', function (): void {
    $s = $this->sku;
    csvRun($this, ImportRun::SOURCE_CSV_PRODUCTS, "handle,title,sku,price\ntee-{$s},Tee,{$s}-TEE,999\n", false);
    $variant = ProductVariant::query()->where('sku', "{$s}-TEE")->firstOrFail();

    $run = csvRun($this, ImportRun::SOURCE_CSV_STOCK, "sku,quantity\n{$s}-TEE,12\nNOPE-{$s},3\n{$s}-TEE,-1\n", false);

    expect($run->status)->toBe('completed')
        ->and($run->counts['stock'])->toMatchArray(['counted' => 1, 'failed' => 2])
        ->and(app(StockReader::class)->available([$variant->id])[$variant->id])->toBe(12);
});

it('rejects a file with missing columns and files outside the upload folder', function (): void {
    $run = csvRun($this, ImportRun::SOURCE_CSV_STOCK, "code,qty\nA,1\n", false);

    expect($run->status)->toBe('failed')->and($run->error)->toContain('Missing columns: sku, quantity');

    expect(fn () => app(StartCsvImport::class)->handle(ImportRun::SOURCE_CSV_STOCK, '../secrets.csv', false, $this->owner))
        ->toThrow(ValidationException::class);
});

it('imports a CSV from the command line into the named brand only', function (): void {
    $s = $this->sku;
    $file = tempnam(sys_get_temp_dir(), 'csvcli');
    file_put_contents($file, "handle,title,status,sku,price,stock\nlamp-{$s},Lamp,active,{$s}-LAMP,4500,7\n");
    tenancy()->end();

    try {
        $this->artisan('imports:csv', ['tenant' => $this->brand->getTenantKey(), 'file' => $file])
            ->expectsOutputToContain('created 1')->assertSuccessful();
        $this->artisan('imports:csv', ['tenant' => $this->brand->getTenantKey(), 'file' => $file.'-missing'])->assertFailed();
    } finally {
        @unlink($file);
    }

    $variant = $this->brand->run(fn () => ProductVariant::query()->where('sku', "{$s}-LAMP")->firstOrFail());

    expect($variant->price->amount)->toBe(450_000)
        ->and($this->brand->run(fn () => app(StockReader::class)->available([$variant->id])[$variant->id]))->toBe(7)
        ->and(TestPlatform::brand(TestPlatform::BRAND_C)->run(fn () => ProductVariant::query()->where('sku', "{$s}-LAMP")->exists()))->toBeFalse();
});

it('indexes an import for search in bulk, not one job per product and stock change', function (): void {
    $s = $this->sku;
    Queue::fake([MakeSearchable::class]);

    csvRun($this, ImportRun::SOURCE_CSV_PRODUCTS, "handle,title,status,option1_name,option1_value,sku,price,stock\n"
        ."a-{$s},Alpha,active,Size,S,{$s}-AS,100,3\na-{$s},Alpha,active,Size,M,{$s}-AM,100,3\n"
        ."b-{$s},Beta,active,,,{$s}-B,200,5\nc-{$s},Gamma,draft,,,{$s}-C,300,1\n", false);

    Queue::assertPushed(MakeSearchable::class, 1);
    Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->models->pluck('title')->sort()->values()->all() === ['Alpha', 'Beta']);

    csvRun($this, ImportRun::SOURCE_CSV_STOCK, "sku,quantity\n{$s}-AS,0\n{$s}-B,9\n", false);

    Queue::assertPushed(MakeSearchable::class, 2);
});

it('shows the imports screen to an owner', function (): void {
    tenancy()->end();

    $this->actingAs($this->owner, 'staff')->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_B).'/import-runs')
        ->assertOk()->assertSee('Import from WooCommerce');
});

it('shows imported order history to staff who can see orders', function (): void {
    tenancy()->end();

    $this->actingAs(TestPlatform::staff($this->brand, role: 'Accountant'), 'staff')
        ->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_B).'/imported-orders')->assertOk();
});

it('keeps imports from staff who cannot change the catalog or stock', function (): void {
    tenancy()->end();

    $this->actingAs(TestPlatform::staff($this->brand, role: 'Accountant'), 'staff')
        ->get('http://'.TestPlatform::adminHost(TestPlatform::BRAND_B).'/import-runs')->assertForbidden();
});
