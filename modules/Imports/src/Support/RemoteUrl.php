<?php

declare(strict_types=1);

namespace Modules\Imports\Support;

use Illuminate\Validation\ValidationException;

/**
 * Guards outbound requests made on staff input (a store URL, the image URLs that store
 * returns): HTTPS only, no credentials in the URL, and never an internal address — the
 * importer must not become a way to reach the platform's own network (SSRF).
 */
final class RemoteUrl
{
    public static function check(string $url, string $field = 'url'): string
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $local = (bool) config('store.imports.allow_private_hosts', false);

        $fail = fn (string $message) => throw ValidationException::withMessages([$field => $message]);

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            $fail(__('Enter a full address, like https://shop.example.com.'));
        }

        if ($scheme !== 'https' && ! ($local && $scheme === 'http')) {
            $fail(__('The address must start with https://.'));
        }

        if (! $local) {
            $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);

            if ($addresses === []) {
                $fail(__('That address could not be found.'));
            }

            foreach ($addresses as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    $fail(__('That address is not reachable from the internet.'));
                }
            }
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $scheme.'://'.$host.$port.$path;
    }
}
