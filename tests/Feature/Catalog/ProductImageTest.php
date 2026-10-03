<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Modules\Catalog\Actions\AttachProductImage;
use Tests\Support\TestCatalog;

/*
| Spec §6.9: MIME by content, size limits, re-encoding (strips EXIF and embedded payloads),
| random names, no SVG; files on the media disk; conversions for responsive use.
*/

beforeEach(function (): void {
    $this->product = TestCatalog::simpleProduct(publish: true);
    $this->files = [];
});

afterEach(function (): void {
    foreach ($this->files as $file) {
        @unlink($file);
    }
});

function tempFile(object $test, string $contents, string $extension): string
{
    $path = tempnam(sys_get_temp_dir(), 'upl').'.'.$extension;
    file_put_contents($path, $contents);
    $test->files[] = $path;

    return $path;
}

function jpegWithExif(): string
{
    $image = imagecreatetruecolor(1200, 900);
    imagefilledrectangle($image, 0, 0, 1199, 899, (int) imagecolorallocate($image, 200, 120, 80));
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    // Splice an APP1/EXIF segment carrying "GPS" data and a script payload after SOI.
    $payload = "Exif\0\0GPSLatitude=24.8607;<?php echo 'owned'; ?>";
    $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
}

it('re-encodes uploads to WebP, dropping EXIF and payloads, under a random name', function (): void {
    $media = app(AttachProductImage::class)->handle($this->product, tempFile($this, jpegWithExif(), 'jpg'), 'Terracotta cushion');
    $stored = (string) file_get_contents($media->getPath());

    expect($media->mime_type)->toBe('image/webp')
        ->and($media->file_name)->toMatch('/^[0-9a-z]{26}\.webp$/')
        ->and($stored)->not->toContain('GPSLatitude')
        ->and($stored)->not->toContain('<?php')
        ->and($media->getCustomProperty('alt'))->toBe('Terracotta cushion');
});

it('generates the responsive WebP sizes', function (): void {
    $media = app(AttachProductImage::class)->handle($this->product, tempFile($this, jpegWithExif(), 'jpg'));
    $media->refresh();

    expect($media->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and($media->hasGeneratedConversion('card'))->toBeTrue()
        ->and(getimagesize($media->getPath('thumb'))[0] ?? null)->toBe(400);
});

it('rejects anything that is not a raster image, whatever its name says', function (string $contents, string $extension): void {
    app(AttachProductImage::class)->handle($this->product, tempFile($this, $contents, $extension));
})->throws(ValidationException::class)->with([
    'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'svg'],
    'svg disguised as png' => ['<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'png'],
    'php disguised as jpg' => ["<?php system(\$_GET['c']);", 'jpg'],
    'empty file' => ['', 'jpg'],
]);

it('shows images with their sizes in the storefront API', function (): void {
    app(AttachProductImage::class)->handle($this->product, tempFile($this, jpegWithExif(), 'jpg'), 'Front view');

    $this->getJson('http://localhost/api/v1/products/'.$this->product->ulid)
        ->assertOk()
        ->assertJsonPath('data.images.0.alt', 'Front view')
        ->assertJsonPath('data.images.0.width', 1200)
        ->assertJsonPath('data.images.0.sizes.card.width', 800)
        ->assertJsonPath('data.image.alt', 'Front view');
});

it('stores images on the media disk and moves rows from the old tenant disk names', function (): void {
    $media = app(AttachProductImage::class)->handle($this->product, tempFile($this, jpegWithExif(), 'jpg'));

    expect($media->disk)->toBe('media');

    // A row written before the single-store conversion.
    Illuminate\Support\Facades\DB::table('media')->where('id', $media->id)->update(['disk' => 'tenant_media', 'conversions_disk' => 'tenant_media']);
    (require base_path('modules/Catalog/database/migrations/2026_10_04_100002_rename_media_disks.php'))->up();

    expect($media->refresh()->disk)->toBe('media')
        ->and($media->conversions_disk)->toBe('media')
        ->and(is_file($media->getPath()))->toBeTrue();
});
