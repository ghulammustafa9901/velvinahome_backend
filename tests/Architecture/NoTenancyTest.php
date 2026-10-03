<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| This is a single-store application (ADR-0020)
|--------------------------------------------------------------------------
|
| Multi-tenancy was removed on purpose: one installation serves one store from one database,
| and another client gets a separate installation. This test fails the build if a tenancy
| package, a landlord connection, tenant context helpers or the old Platform module come back.
| Store identity belongs in settings or `.env` (see CLAUDE.md), never in a tenant model.
|
*/

/** The project root (architecture tests run without the application). */
function projectPath(string $path = ''): string
{
    return dirname(__DIR__, 2).($path === '' ? '' : DIRECTORY_SEPARATOR.$path);
}

const TENANCY_PACKAGES = [
    'stancl/tenancy',
    'archtechx/tenancy',
    'spatie/laravel-multitenancy',
    'hyn/multi-tenant',
    'tenancy/tenancy',
];

/**
 * Code patterns that only exist in a multi-tenant application.
 *
 * @var array<string, string>
 */
const TENANCY_PATTERNS = [
    'a tenancy package namespace' => '/\bStancl\\\\|\bSpatie\\\\Multitenancy\\\\|\bHyn\\\\Tenancy\\\\/',
    'the tenancy() helper' => '/(?<![\w>:$])tenancy\(\)/',
    'the tenant() helper' => '/(?<![\w>:$])tenant\(\)/',
    'tenant context classes' => '/\b(TenantAware|EnsureTenantContext|InitializeTenancy\w*|TenantDirectory|TenantResolver)\b/',
    'the Platform (landlord) module' => '/Modules\\\\Platform\\\\/',
    'a landlord database connection' => '/[\'"]landlord(_migrator)?[\'"]/',
];

/**
 * @param  list<string>  $roots
 * @return list<string> "path: what"
 */
function tenancyViolations(array $roots): array
{
    $violations = [];

    foreach ($roots as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(projectPath($root), FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if ($file->getExtension() !== 'php' || str_contains($path, '/bootstrap/cache/')) {
                continue;
            }

            foreach (tenancyMatches((string) file_get_contents($file->getPathname())) as $what) {
                $violations[] = str_replace(str_replace('\\', '/', projectPath()).'/', '', $path).': '.$what;
            }
        }
    }

    return $violations;
}

/**
 * @return list<string> the names of the patterns the code matches
 */
function tenancyMatches(string $code): array
{
    return array_keys(array_filter(TENANCY_PATTERNS, fn (string $pattern): bool => preg_match($pattern, $code) === 1));
}

it('recognises every kind of tenancy code it guards against', function (string $code): void {
    expect(tenancyMatches($code))->not->toBe([]);
})->with([
    'package namespace' => ['use Stancl\Tenancy\Database\Models\Tenant;'],
    'tenancy helper' => ['if (tenancy()->initialized) {}'],
    'tenant helper' => ['$id = tenant()?->getTenantKey();'],
    'tenant-aware job' => ['use TenantAware;'],
    'platform module' => ['use Modules\Platform\Models\Tenant;'],
    'landlord connection' => ["DB::connection('landlord')->table('x');"],
]);

it('lets ordinary code through', function (string $code): void {
    expect(tenancyMatches($code))->toBe([]);
})->with([
    'settings contract' => ['public function __construct(private StoreSettings $settings) {}'],
    'scoped binding' => ['$this->app->scoped(Cache::class, Buffered::class);'],
    'product brand' => ['$product->brand->name;'],
]);

it('has no tenancy code in the application', function (): void {
    $violations = tenancyViolations(['app', 'bootstrap', 'config', 'database', 'modules', 'routes']);

    expect($violations)->toBe([], "Multi-tenancy must not be re-added (ADR-0020):\n".implode("\n", $violations));
});

it('requires no tenancy package', function (): void {
    $composer = json_decode((string) file_get_contents(projectPath('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $required = array_keys([...$composer['require'] ?? [], ...$composer['require-dev'] ?? []]);

    expect(array_values(array_intersect($required, TENANCY_PACKAGES)))->toBe([]);
});

it('has no tenancy configuration or Platform module', function (): void {
    expect(projectPath('config/tenancy.php'))->not->toBeFile()
        ->and(projectPath('modules/Platform'))->not->toBeDirectory();
});
