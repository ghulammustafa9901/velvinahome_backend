<?php

declare(strict_types=1);

namespace App\OpenApi;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * What code inference cannot see: which operations need the customer bearer token, and the
 * headers that carry the storefront's secrets and idempotency (api-conventions §6, §9).
 * Stable operation ids come from route names, so the generated client's method names do not
 * change when controllers are refactored.
 */
final class DescribeStorefrontOperations implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $route = $routeInfo->route;
        $name = (string) $route->getName();
        $middleware = $route->gatherMiddleware();

        if ($name !== '') {
            $operation->setOperationId(str_replace(['api.v1.', '.', '-'], ['', '_', '_'], $name));
        }

        if (in_array('auth:customer', $middleware, true)) {
            $operation->addSecurity(new SecurityRequirement(['customerToken' => []]));
        }

        foreach ($middleware as $entry) {
            if (is_string($entry) && str_starts_with($entry, 'feature:')) {
                $operation->description(trim($operation->description."\n\nAvailable when the store has the `".substr($entry, 8).'` feature; otherwise `403 feature_disabled`.'));
            }
        }

        if (str_starts_with($name, 'api.v1.carts.') || in_array($name, ['api.v1.checkout.quote', 'api.v1.checkout.place', 'api.v1.checkout.shipping-methods'], true)) {
            $operation->addParameters([self::header('X-Cart-Token', false, 'Secret returned once when a guest cart is created. Not needed for a signed-in customer\'s own cart.')]);
        }

        if (in_array($name, ['api.v1.orders.show', 'api.v1.orders.payment-proof'], true)) {
            $operation->addParameters([self::header('X-Order-Token', false, 'Guest order secret returned at placement (`access_token`). Not needed for the signed-in owner.')]);
        }

        if ($name === 'api.v1.checkout.place') {
            $operation->addParameters([self::header('Idempotency-Key', true, '16–100 characters [A-Za-z0-9_-]. Same key + same body replays the original order (200, `Idempotent-Replayed: true`); same key + different body is `409 idempotency_key_reused`.')]);
        }

        // Error statuses the middleware and domain rules can produce; their bodies are all
        // the Error envelope (see DescribeStorefrontDocument).
        // Every operation validates some input (body, query, headers or the path itself).
        $statuses = [422 => 'validation_failed — `details` maps fields to messages.'];

        if ($route->parameterNames() !== []) {
            $statuses[404] = 'Not found (also for anything the caller may not see).';
        }

        foreach ($middleware as $entry) {
            if (is_string($entry) && str_starts_with($entry, 'throttle:')) {
                $statuses[429] = 'rate_limited — see Retry-After.';
            }

            if (is_string($entry) && str_starts_with($entry, 'feature:')) {
                $statuses[403] = 'feature_disabled for this store.';
            }
        }

        if (in_array($name, ['api.v1.checkout.place'], true)) {
            $statuses[409] = 'stock_unavailable, price_changed, coupon_unavailable or idempotency_key_reused.';
        }

        if (in_array($name, ['api.v1.carts.items.store', 'api.v1.carts.items.update'], true)) {
            $statuses[409] = 'stock_unavailable.';
        }

        foreach ($statuses as $status => $description) {
            $documented = array_map(fn ($response) => $response instanceof Response ? (string) $response->code : null, $operation->responses ?? []);

            if (! in_array((string) $status, $documented, true)) {
                $operation->addResponse(Response::make($status)->setDescription($description));
            }
        }
    }

    private static function header(string $name, bool $required, string $description): Parameter
    {
        return (new Parameter($name, 'header'))
            ->required($required)
            ->description($description)
            ->setSchema(Schema::fromType(new StringType));
    }
}
