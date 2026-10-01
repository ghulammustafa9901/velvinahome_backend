<?php

declare(strict_types=1);

namespace Modules\Shipping\Support;

use Illuminate\Contracts\Container\Container;
use Modules\Shipping\Contracts\CourierAdapter;

/**
 * The courier integrations this deployment knows, by key. "manual" is always there.
 */
final class CourierAdapters
{
    /** @var array<string, class-string<CourierAdapter>> */
    private array $adapters = ['manual' => ManualCourier::class];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<CourierAdapter>  $adapter
     */
    public function register(string $key, string $adapter): void
    {
        $this->adapters[$key] = $adapter;
    }

    public function get(string $key): ?CourierAdapter
    {
        $class = $this->adapters[$key] ?? null;

        return $class === null ? null : $this->container->make($class);
    }

    /**
     * @return array<string, string> key => label
     */
    public function options(): array
    {
        $options = [];
        foreach (array_keys($this->adapters) as $key) {
            $options[$key] = (string) $this->get($key)?->label();
        }

        return $options;
    }
}
