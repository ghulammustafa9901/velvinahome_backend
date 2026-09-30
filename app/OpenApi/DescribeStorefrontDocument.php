<?php

declare(strict_types=1);

namespace App\OpenApi;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Document-level facts: the customer bearer scheme, and the one error envelope every failure
 * uses (api-conventions §3) — replacing the framework-default error shapes that code
 * inference would otherwise describe.
 */
final class DescribeStorefrontDocument implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $document->components->addSecurityScheme('customerToken', SecurityScheme::http('bearer')
            ->setDescription('Sanctum token of a signed-in customer, held server-side by the storefront BFF (ADR-0003).'));

        $details = (new ObjectType)->setDescription('Field → messages for validation_failed; structured data for some 409s (e.g. price_changed: expected_total, total).');

        $error = (new ObjectType)
            ->addProperty('code', (new StringType)->setDescription('Stable, machine-readable: validation_failed, not_found, unauthenticated, forbidden, feature_disabled, rate_limited, stock_unavailable, price_changed, coupon_unavailable, idempotency_key_reused, server_error…'))
            ->addProperty('message', new StringType)
            ->addProperty('details', $details)
            ->addProperty('request_id', (new StringType)->setDescription('Matches the server log entry.'))
            ->setRequired(['code', 'message']);

        $reference = $document->components->addSchema('Error', Schema::fromType((new ObjectType)->addProperty('error', $error)->setRequired(['error'])));

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                $responses = [];

                // One entry per status (inference and our transformer may both add one).
                foreach ($operation->responses ?? [] as $response) {
                    $normalised = self::normalise($response, $reference);
                    $code = $normalised instanceof Response ? (string) $normalised->code : spl_object_id($normalised);
                    $responses[$code] ??= $normalised;
                }

                $operation->responses = array_values($responses);
            }
        }

        foreach (['ValidationException', 'AuthenticationException', 'AuthorizationException', 'ModelNotFoundException', 'HttpException', 'NotFoundHttpException'] as $unused) {
            $document->components->removeResponse($unused);
        }

        $this->describeCategoryTree($document);
    }

    /**
     * The category tree is recursive, which code inference cannot express: a CategoryNode
     * has CategoryNode children.
     */
    private function describeCategoryTree(OpenApi $document): void
    {
        $node = new Reference('schemas', 'CategoryNode', $document->components);
        $document->components->addSchema('CategoryNode', Schema::fromType((new ObjectType)
            ->addProperty('id', new StringType)
            ->addProperty('name', new StringType)
            ->addProperty('slug', new StringType)
            ->addProperty('children', (new ArrayType)->setItems($node))
            ->setRequired(['id', 'name', 'slug', 'children'])));

        foreach ($document->paths as $path) {
            if (ltrim($path->path, '/') !== 'v1/categories') {
                continue;
            }

            foreach ($path->operations as $operation) {
                $operation->responses = array_map(fn ($response) => $response instanceof Response && (int) $response->code === 200
                    ? Response::make(200)->setDescription('The visible category tree.')->setContent('application/json', Schema::fromType(
                        (new ObjectType)->addProperty('data', (new ArrayType)->setItems($node))->setRequired(['data']),
                    ))
                    : $response, $operation->responses ?? []);
            }
        }
    }

    /** Framework exception responses Scramble references by class. */
    private const EXCEPTION_STATUSES = [
        'ValidationException' => [422, 'validation_failed — `details` maps fields to messages.'],
        'AuthenticationException' => [401, 'unauthenticated — a customer token is required.'],
        'AuthorizationException' => [403, 'forbidden'],
        'ModelNotFoundException' => [404, 'not_found'],
        'NotFoundHttpException' => [404, 'not_found'],
    ];

    private static function normalise(mixed $response, Reference $error): mixed
    {
        if ($response instanceof Reference) {
            [$code, $description] = self::EXCEPTION_STATUSES[class_basename($response->fullName)] ?? [null, null];

            if ($code === null) {
                return $response;
            }
        } elseif ($response instanceof Response && (int) $response->code >= 400) {
            $code = (int) $response->code;
            $description = $response->description !== '' ? $response->description : 'Error';
        } else {
            return $response;
        }

        return Response::make($code)
            ->setDescription($description)
            ->setContent('application/json', Schema::fromType($error));
    }
}
