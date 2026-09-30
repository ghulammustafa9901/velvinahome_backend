<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\OpenApi\BreakingChanges;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Console\Command;
use stdClass;

/**
 * The API v1 contract lives in `docs/api/openapi-v1.json`, committed. CI runs `--check`:
 * the document generated from the code must equal the snapshot, so every contract change is a
 * reviewed diff — and changes that would break existing clients are named explicitly.
 */
final class ApiContractCommand extends Command
{
    public const SNAPSHOT = 'docs/api/openapi-v1.json';

    protected $signature = 'api:contract {--check : Fail when the code no longer matches the committed contract} {--write : Update the committed contract}';

    protected $description = 'Compare (or update) the committed Storefront API v1 OpenAPI contract';

    public function handle(Generator $generator, BreakingChanges $breaking): int
    {
        $current = self::encode($generator->generate(Scramble::getGeneratorConfig(Scramble::DEFAULT_API))->spec());
        $path = base_path(self::SNAPSHOT);

        // A list whose items are undescribed gives the TypeScript client `unknown[]`; describe
        // it (array-shape @scramble-return on the presenter) instead of shipping it.
        $untyped = self::untypedLists(json_decode($current, false, 512, JSON_THROW_ON_ERROR), '');

        if ($untyped !== []) {
            foreach ($untyped as $where) {
                $this->error('Untyped list items at '.$where);
            }

            return self::FAILURE;
        }
        $snapshot = is_file($path) ? (string) file_get_contents($path) : null;

        if ($this->option('write')) {
            if ($snapshot !== null) {
                foreach ($breaking->between(self::decode($snapshot), self::decode($current)) as $problem) {
                    $this->warn('BREAKING: '.$problem);
                }
            }

            file_put_contents($path, $current);
            $this->info('Contract written to '.self::SNAPSHOT.'.');

            return self::SUCCESS;
        }

        if ($snapshot === null) {
            $this->error('No committed contract at '.self::SNAPSHOT.'. Run `php artisan api:contract --write`.');

            return self::FAILURE;
        }

        if (str_replace("\r\n", "\n", $snapshot) === $current) {
            $this->info('The code matches the committed API contract.');

            return self::SUCCESS;
        }

        $problems = $breaking->between(self::decode($snapshot), self::decode($current));

        foreach ($problems as $problem) {
            $this->error('BREAKING: '.$problem);
        }

        $this->error($problems === []
            ? 'The API changed (additively). Review it, then run `php artisan api:contract --write` and commit the snapshot.'
            : 'The API changed in ways that break v1 clients. v1 is frozen: make the change additive, or plan v2.');

        return self::FAILURE;
    }

    /**
     * Canonical JSON: keys sorted, host-specific servers removed. Decoded as objects so an
     * empty schema `{}` stays an object and is never turned into `[]`.
     *
     * @param  array<string, mixed>  $document
     */
    private static function encode(array $document): string
    {
        $json = json_decode((string) json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        unset($json->servers);

        return json_encode(self::sortKeys($json), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::sortKeys(...), $value);
        }

        if (! $value instanceof stdClass) {
            return $value;
        }

        $properties = get_object_vars($value);
        ksort($properties);
        $sorted = new stdClass;

        foreach ($properties as $key => $property) {
            $sorted->{$key} = self::sortKeys($property);
        }

        return $sorted;
    }

    /**
     * @return list<string>
     */
    private static function untypedLists(mixed $node, string $path): array
    {
        $found = [];

        if (is_array($node)) {
            foreach ($node as $index => $child) {
                $found = [...$found, ...self::untypedLists($child, $path.'/'.$index)];
            }

            return $found;
        }

        if (! $node instanceof stdClass) {
            return [];
        }

        foreach (get_object_vars($node) as $key => $child) {
            if ($key === 'items' && $child instanceof stdClass && get_object_vars($child) === []) {
                $found[] = $path;
            }

            $found = [...$found, ...self::untypedLists($child, $path.'/'.$key)];
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        return (array) json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
