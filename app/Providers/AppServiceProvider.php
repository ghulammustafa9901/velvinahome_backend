<?php

declare(strict_types=1);

namespace App\Providers;

use App\OpenApi\DescribeStorefrontDocument;
use App\OpenApi\DescribeStorefrontOperations;
use App\OpenApi\MoneyToSchema;
use App\OpenApi\StringMapToSchema;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use Modules\Support\Http\TrustStorefrontService;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Lazy loading, silently discarded attributes and missing attributes are bugs; make
        // them loud everywhere except production.
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->configureRateLimiting();
        $this->configureApiDocumentation();
        $this->configureOperationsTooling();
    }

    /**
     * Horizon and the API documentation are operations tooling: signed-in staff holding
     * system.monitor (the Owner role by default) only.
     */
    private function configureOperationsTooling(): void
    {
        $monitor = static fn ($user = null): bool => $user instanceof Authorizable && $user->can('system.monitor');

        Gate::define('viewHorizon', $monitor);
        Gate::define('viewApiDocs', $monitor);
        Horizon::auth(fn (Request $request): bool => Gate::forUser($request->user('staff'))->allows('viewHorizon'));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by((string) ($request->user('customer')?->getAuthIdentifier() ?? $request->ip())));

        // Per IP *and* per account, so neither one IP spraying many accounts nor many IPs
        // hammering one account gets through (api-conventions §8).
        RateLimiter::for('auth', fn (Request $request): array => [
            Limit::perMinute(5)->by('ip|'.$request->ip()),
            Limit::perMinute(5)->by('account|'.(mb_strtolower(trim($request->string('email')->toString())) ?: self::clientKey($request))),
        ]);

        // The storefront rendering pages (signed, ISR) is not limited per server IP; shoppers
        // still are, by their own IP.
        RateLimiter::for('catalog', fn (Request $request): Limit => self::rendering($request) ? Limit::none() : Limit::perMinute(120)
            ->by((string) $request->ip()));

        RateLimiter::for('search', fn (Request $request): Limit => self::rendering($request) ? Limit::none() : Limit::perMinute(60)
            ->by((string) $request->ip()));

        RateLimiter::for('cart', fn (Request $request): Limit => Limit::perMinute(60)
            ->by(self::clientKey($request)));

        // Placing orders is expensive and a fake-COD target: tight per client, with an IP
        // ceiling so minting fresh guest carts does not reset the budget.
        RateLimiter::for('checkout', fn (Request $request): array => [
            Limit::perMinute(10)->by(self::clientKey($request)),
            Limit::perMinute(30)->by('checkout-ip|'.$request->ip()),
        ]);
    }

    private static function rendering(Request $request): bool
    {
        return $request->attributes->get(TrustStorefrontService::RENDER_ATTRIBUTE) === true;
    }

    /**
     * The OpenAPI document is generated from code (api-conventions §10); these describe what
     * inference cannot see.
     */
    private function configureApiDocumentation(): void
    {
        Scramble::registerExtensions([MoneyToSchema::class, StringMapToSchema::class]);
        Scramble::configure()
            // The local media route exists only without object storage (development); it is
            // not part of the public contract.
            ->routes(fn (\Illuminate\Routing\Route $route): bool => str_starts_with($route->uri(), 'api/v1/') && $route->getName() !== 'api.v1.media.local')
            ->withOperationTransformers(DescribeStorefrontOperations::class)
            ->withDocumentTransformers(DescribeStorefrontDocument::class);
    }

    /**
     * The "session" of api-conventions §8: the signed-in customer, else the guest's cart
     * (its token, hashed), else the IP.
     */
    private static function clientKey(Request $request): string
    {
        $customer = $request->user('customer')?->getAuthIdentifier();

        if ($customer !== null) {
            return 'customer|'.$customer;
        }

        $cartToken = $request->header('X-Cart-Token');

        return is_string($cartToken) && $cartToken !== '' ? 'cart|'.hash('sha256', $cartToken) : 'ip|'.$request->ip();
    }
}
