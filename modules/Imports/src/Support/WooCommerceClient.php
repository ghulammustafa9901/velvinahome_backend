<?php

declare(strict_types=1);

namespace Modules\Imports\Support;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Read-only client for the WooCommerce REST API v3 (keys with "Read" permission are enough).
 * Authenticates with the consumer key and secret over HTTPS; never follows redirects, so a
 * store cannot bounce the importer to another host.
 */
final class WooCommerceClient
{
    public const PER_PAGE = 100;

    public function __construct(
        private readonly string $storeUrl,
        private readonly string $key,
        private readonly string $secret,
    ) {}

    /**
     * @param  array<string, scalar>  $query
     * @return array<mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $response = $this->request()->get(ltrim($path, '/'), $query);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('The store refused the API keys (HTTP '.$response->status().'). Check they are correct and have read access.');
        }

        $response->throw();
        $body = $response->json();

        return is_array($body) ? $body : throw new RuntimeException("Unexpected response from {$path}.");
    }

    /**
     * Every item of a paginated list, page by page.
     *
     * @param  array<string, scalar>  $query
     * @return Generator<int, array<string, mixed>>
     */
    public function each(string $path, array $query = []): Generator
    {
        for ($page = 1; ; $page++) {
            $response = $this->request()->get(ltrim($path, '/'), $query + ['per_page' => self::PER_PAGE, 'page' => $page]);
            $response->throw();
            $items = $response->json();

            if (! is_array($items) || $items === []) {
                return;
            }

            foreach ($items as $item) {
                if (is_array($item)) {
                    yield $item;
                }
            }

            $pages = (int) $response->header('X-WP-TotalPages');

            if (count($items) < self::PER_PAGE || ($pages > 0 && $page >= $pages)) {
                return;
            }
        }
    }

    /**
     * Download a file (a product image) into a temporary path, at most $maxBytes.
     */
    public function download(string $url, int $maxBytes): string
    {
        $url = RemoteUrl::check($url, 'image');

        $response = Http::timeout(30)->connectTimeout(10)
            ->withOptions(['allow_redirects' => false])
            ->get($url);

        $declared = (int) $response->header('Content-Length');

        if (! $response->successful() || $declared > $maxBytes) {
            throw new RuntimeException('Image not downloaded (HTTP '.$response->status().').');
        }

        $body = $response->body();

        if ($body === '' || strlen($body) > $maxBytes) {
            throw new RuntimeException('Image is empty or larger than '.intdiv($maxBytes, 1024 * 1024).' MB.');
        }

        $path = tempnam(sys_get_temp_dir(), 'wcimg');

        if ($path === false || file_put_contents($path, $body) === false) {
            throw new RuntimeException('Could not store the image temporarily.');
        }

        return $path;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->storeUrl.'/wp-json/wc/v3/')
            ->withBasicAuth($this->key, $this->secret)
            ->acceptJson()
            ->timeout(60)
            ->connectTimeout(10)
            ->withOptions(['allow_redirects' => false])
            ->retry(3, 2000, fn (Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError())), throw: false);
    }
}
