<?php

declare(strict_types=1);

namespace Modules\Shipping\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Validation\ValidationException;
use Modules\Shipping\Models\Courier;
use Modules\Shipping\Support\CourierAdapters;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Add or change a courier. A tracking link must be https and contain {tracking}, so a
 * customer's "track your parcel" link can only ever go to a real courier page.
 */
final class SaveCourier implements Action
{
    use ChecksPermission;

    public function __construct(private readonly CourierAdapters $adapters) {}

    /**
     * @param  array<string, string>|null  $credentials  null keeps the stored ones
     */
    public function handle(?Courier $courier, string $name, string $adapter = 'manual', ?string $trackingUrlTemplate = null, bool $isEnabled = true, int $position = 0, ?array $credentials = null, ?Authorizable $actor = null): Courier
    {
        $this->authorizePermission($actor, 'settings.manage');

        $name = trim($name);
        $template = $trackingUrlTemplate === null || trim($trackingUrlTemplate) === '' ? null : trim($trackingUrlTemplate);

        if ($name === '' || mb_strlen($name) > 64) {
            throw ValidationException::withMessages(['name' => __('Give the courier a name of up to 64 characters.')]);
        }

        if ($this->adapters->get($adapter) === null) {
            throw ValidationException::withMessages(['adapter' => __('Unknown courier integration.')]);
        }

        if ($template !== null && (preg_match('#^https://[^\s]+$#', $template) !== 1 || ! str_contains($template, '{tracking}') || mb_strlen($template) > 2048)) {
            throw ValidationException::withMessages(['tracking_url_template' => __('Use an https link containing {tracking}, e.g. https://courier.example/track?cn={tracking}.')]);
        }

        $code = $courier !== null ? $courier->code : trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-');

        if ($code === '' || ($courier === null && Courier::query()->where('code', $code)->exists())) {
            throw ValidationException::withMessages(['name' => __('A courier with this name exists.')]);
        }

        $courier ??= new Courier(['code' => mb_substr($code, 0, 32)]);
        $courier->fill([
            'name' => $name,
            'adapter' => $adapter,
            'tracking_url_template' => $template,
            'is_enabled' => $isEnabled,
            'position' => $position,
        ]);

        if ($credentials !== null) {
            $courier->credentials = $credentials === [] ? null : $credentials;
        }

        $courier->save();

        return $courier;
    }
}
