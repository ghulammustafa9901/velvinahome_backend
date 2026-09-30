<?php

declare(strict_types=1);

namespace App\OpenApi;

/**
 * Compares two OpenAPI documents and lists changes that would break an existing client of
 * API v1 (api-conventions §1: additive changes only after the freeze):
 *
 *  - an operation removed;
 *  - a response status removed, or a response property removed / no longer guaranteed;
 *  - a property's type narrowed or changed;
 *  - a new required request parameter or request-body field.
 *
 * Adding operations, optional inputs, response properties and statuses is fine.
 */
final class BreakingChanges
{
    /** @var array<string, mixed> */
    private array $oldDocument = [];

    /** @var array<string, mixed> */
    private array $newDocument = [];

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<string>
     */
    public function between(array $old, array $new): array
    {
        $this->oldDocument = $old;
        $this->newDocument = $new;
        $problems = [];

        foreach ((array) ($old['paths'] ?? []) as $path => $operations) {
            foreach ((array) $operations as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                $current = $new['paths'][$path][$method] ?? null;
                $where = strtoupper($method).' '.$path;

                if (! is_array($current)) {
                    $problems[] = "$where was removed.";

                    continue;
                }

                $problems = [...$problems, ...$this->compareOperation($where, (array) $operation, $current)];
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<string>
     */
    private function compareOperation(string $where, array $old, array $new): array
    {
        $problems = [];

        // New required parameters.
        $oldRequired = $this->requiredParameters($old);

        foreach ($this->requiredParameters($new) as $parameter) {
            if (! in_array($parameter, $oldRequired, true)) {
                $problems[] = "$where now requires parameter $parameter.";
            }
        }

        // New required body fields.
        $oldBody = $this->resolve($old['requestBody']['content']['application/json']['schema'] ?? null, $this->oldDocument);
        $newBody = $this->resolve($new['requestBody']['content']['application/json']['schema'] ?? null, $this->newDocument);

        foreach ((array) ($newBody['required'] ?? []) as $field) {
            if (! in_array($field, (array) ($oldBody['required'] ?? []), true)) {
                $problems[] = "$where now requires body field $field.";
            }
        }

        // Responses must keep their statuses and shapes.
        foreach ((array) ($old['responses'] ?? []) as $status => $response) {
            $newResponse = $new['responses'][$status] ?? null;

            if (! is_array($newResponse)) {
                $problems[] = "$where no longer documents response $status.";

                continue;
            }

            $problems = [...$problems, ...$this->compareSchema(
                "$where → $status",
                $this->resolve($response['content']['application/json']['schema'] ?? null, $this->oldDocument),
                $this->resolve($newResponse['content']['application/json']['schema'] ?? null, $this->newDocument),
                0,
            )];
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @return list<string>
     */
    private function compareSchema(string $where, ?array $old, ?array $new, int $depth): array
    {
        if ($old === null || $new === null || $depth > 12) {
            return [];
        }

        $oldTypes = (array) ($old['type'] ?? []);
        $newTypes = (array) ($new['type'] ?? []);

        // A response type may only get narrower (fewer possibilities is safe for readers).
        if ($oldTypes !== [] && $newTypes !== [] && array_diff($newTypes, $oldTypes) !== []) {
            return ["$where changed type from ".implode('|', $oldTypes).' to '.implode('|', $newTypes).'.'];
        }

        $problems = [];

        foreach ((array) ($old['properties'] ?? []) as $property => $schema) {
            $newProperty = $new['properties'][$property] ?? null;

            if (! is_array($newProperty)) {
                $problems[] = "$where lost property $property.";

                continue;
            }

            if (in_array($property, (array) ($old['required'] ?? []), true) && ! in_array($property, (array) ($new['required'] ?? []), true)) {
                $problems[] = "$where.$property is no longer always present.";
            }

            $problems = [...$problems, ...$this->compareSchema(
                "$where.$property",
                $this->resolve($schema, $this->oldDocument),
                $this->resolve($newProperty, $this->newDocument),
                $depth + 1,
            )];
        }

        if (isset($old['items'], $new['items'])) {
            $problems = [...$problems, ...$this->compareSchema(
                "$where".'[]',
                $this->resolve($old['items'], $this->oldDocument),
                $this->resolve($new['items'], $this->newDocument),
                $depth + 1,
            )];
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return list<string>
     */
    private function requiredParameters(array $operation): array
    {
        $required = [];

        foreach ((array) ($operation['parameters'] ?? []) as $parameter) {
            if (is_array($parameter) && ($parameter['required'] ?? false) === true && ($parameter['in'] ?? '') !== 'path') {
                $required[] = ($parameter['in'] ?? '?').':'.($parameter['name'] ?? '?');
            }
        }

        return $required;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>|null
     */
    private function resolve(mixed $schema, array $document): ?array
    {
        for ($hops = 0; is_array($schema) && isset($schema['$ref']) && $hops < 10; $hops++) {
            $name = substr((string) $schema['$ref'], strlen('#/components/schemas/'));
            $schema = $document['components']['schemas'][$name] ?? null;
        }

        return is_array($schema) ? $schema : null;
    }
}
