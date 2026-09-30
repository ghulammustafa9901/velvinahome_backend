<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;
use stdClass;

/**
 * Contract tests (api-conventions §10): a real response must validate against the committed
 * OpenAPI document. Validation is strict — an object that documents its properties may not
 * carry undocumented ones — so a field the generated TypeScript client cannot see fails here.
 */
final class Contract
{
    private const DOCUMENT_ID = 'https://contract.local/openapi-v1.json';

    /** @var array<string, mixed>|null */
    private static ?array $document = null;

    private static ?Validator $validator = null;

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @param  string  $operation  e.g. "GET /v1/products/{ulid}"
     */
    public static function assert(TestResponse $response, string $operation): void
    {
        [$method, $path] = explode(' ', $operation, 2);
        $document = self::document();
        $status = (string) $response->getStatusCode();

        $definition = $document['paths'][$path][strtolower($method)] ?? null;
        Assert::assertIsArray($definition, "$operation is not in the contract.");

        $responses = (array) ($definition['responses'] ?? []);
        Assert::assertArrayHasKey($status, $responses, "$operation does not document status $status.");

        $content = $responses[$status]['content']['application/json']['schema'] ?? null;

        if ($content === null) {
            Assert::assertTrue(in_array($status, ['204', '304'], true) || $response->getContent() === '', "$operation $status has a body the contract does not describe.");

            return;
        }

        $pointer = '#/paths/'.self::escape($path).'/'.strtolower($method).'/responses/'.$status.'/content/application~1json/schema';
        $result = self::validator()->validate(json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR), (object) ['$ref' => self::DOCUMENT_ID.$pointer]);

        $error = $result->error();

        if ($error !== null) {
            $errors = (new ErrorFormatter)->format($error, false);
            Assert::fail("$operation $status does not match the contract:\n".json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        return self::$document ??= json_decode((string) file_get_contents(base_path('docs/api/openapi-v1.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function validator(): Validator
    {
        if (self::$validator === null) {
            self::$validator = new Validator;
            self::$validator->setMaxErrors(10);
            // Decoded as objects: an empty schema `{}` must not become `[]`.
            $raw = json_decode((string) file_get_contents(base_path('docs/api/openapi-v1.json')), false, 512, JSON_THROW_ON_ERROR);
            self::$validator->resolver()?->registerRaw(json_encode(self::strict($raw), JSON_THROW_ON_ERROR), self::DOCUMENT_ID);
        }

        return self::$validator;
    }

    /**
     * Objects with documented properties refuse undocumented ones; local "#/…" references
     * point into the same document.
     */
    private static function strict(mixed $node): mixed
    {
        if (is_array($node)) {
            return array_map(self::strict(...), $node);
        }

        if (! $node instanceof stdClass) {
            return $node;
        }

        foreach (get_object_vars($node) as $key => $value) {
            $node->{$key} = self::strict($value);
        }

        if (isset($node->properties) && $node->properties instanceof stdClass && ! property_exists($node, 'additionalProperties')) {
            $node->additionalProperties = false;
        }

        if (isset($node->{'$ref'}) && is_string($node->{'$ref'}) && str_starts_with($node->{'$ref'}, '#/')) {
            $node->{'$ref'} = self::DOCUMENT_ID.$node->{'$ref'};
        }

        return $node;
    }

    private static function escape(string $path): string
    {
        return rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $path));
    }
}
