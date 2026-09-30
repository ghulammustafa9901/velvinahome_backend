<?php

declare(strict_types=1);

namespace Modules\Seo\Support;

use Illuminate\Contracts\Container\Container;
use Modules\Seo\Contracts\SitemapSource;
use Modules\Seo\Contracts\SitemapSources;

/**
 * Splits every registered source into chunks of at most CHUNK_SIZE URLs (well under the
 * sitemap protocol's 50,000), named "<source>-<n>", e.g. "products-3".
 */
final class SitemapRegistry implements SitemapSources
{
    public const CHUNK_SIZE = 10_000;

    /** @var list<class-string<SitemapSource>> */
    private array $sources = [];

    public function __construct(private readonly Container $container) {}

    public function register(string $source): void
    {
        $this->sources[] = $source;
    }

    /**
     * @return list<array{name: string, count: int}>
     */
    public function chunks(): array
    {
        $chunks = [];

        foreach ($this->resolved() as $source) {
            $count = $source->count();

            for ($page = 1; ($page - 1) * self::CHUNK_SIZE < $count; $page++) {
                $chunks[] = ['name' => $source->name().'-'.$page, 'count' => min(self::CHUNK_SIZE, $count - ($page - 1) * self::CHUNK_SIZE)];
            }
        }

        return $chunks;
    }

    /**
     * @return list<array{path: string, lastmod: string|null}>|null null for an unknown chunk
     */
    public function chunk(string $name): ?array
    {
        if (preg_match('/^([a-z_]+)-([1-9]\d{0,5})$/', $name, $match) !== 1) {
            return null;
        }

        foreach ($this->resolved() as $source) {
            if ($source->name() === $match[1]) {
                $offset = ((int) $match[2] - 1) * self::CHUNK_SIZE;

                return $offset < $source->count() ? $source->entries($offset, self::CHUNK_SIZE) : null;
            }
        }

        return null;
    }

    /**
     * @return list<SitemapSource>
     */
    private function resolved(): array
    {
        return array_map(fn (string $class): SitemapSource => $this->container->make($class), $this->sources);
    }
}
