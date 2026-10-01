<?php

declare(strict_types=1);

namespace Modules\Shipping\Support;

use Modules\Shipping\Contracts\Couriers;
use Modules\Shipping\Data\CourierTracking;
use Modules\Shipping\Models\Courier;

final class DatabaseCouriers implements Couriers
{
    /** @var array<string, Courier|null> */
    private array $found = [];

    public function __construct(private readonly CourierAdapters $adapters) {}

    public function options(): array
    {
        return Courier::query()->where('is_enabled', true)->orderBy('position')->orderBy('name')->pluck('name', 'code')->all();
    }

    public function trackingUrl(?string $courier, ?string $trackingNumber): ?string
    {
        $template = $this->find($courier)?->tracking_url_template;
        $number = trim((string) $trackingNumber);

        if ($template === null || $number === '' || ! str_contains($template, '{tracking}')) {
            return null;
        }

        return str_replace('{tracking}', rawurlencode($number), $template);
    }

    public function track(?string $courier, string $trackingNumber): ?CourierTracking
    {
        $row = $this->find($courier);
        $adapter = $row === null ? null : $this->adapters->get($row->adapter);

        if ($row === null || $adapter === null || ! $adapter->tracks()) {
            return null;
        }

        return $adapter->track($trackingNumber, $row->credentials ?? []);
    }

    /**
     * By code, or by name for shipments recorded with the courier's name.
     */
    private function find(?string $courier): ?Courier
    {
        $key = mb_strtolower(trim((string) $courier));

        if ($key === '') {
            return null;
        }

        return $this->found[$key] ??= Courier::query()
            ->where(fn ($query) => $query->where('code', $key)->orWhereRaw('lower(name) = ?', [$key]))
            ->first();
    }
}
