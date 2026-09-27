<?php

declare(strict_types=1);

namespace Modules\Payments\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Validation\ValidationException;
use Modules\Payments\Models\PaymentMethod;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

final class UpdatePaymentMethod implements Action
{
    use ChecksPermission;

    public function handle(PaymentMethod $method, bool $enabled, string $label, ?string $description, ?string $instructions, ?Authorizable $actor = null): PaymentMethod
    {
        $this->authorizePermission($actor, 'settings.manage');

        if ($enabled && $method->code === 'manual_transfer' && trim((string) $instructions) === '') {
            throw ValidationException::withMessages(['instructions' => __('Add your account details before enabling transfers.')]);
        }

        $method->forceFill([
            'is_enabled' => $enabled,
            'label' => $label,
            'description' => $description,
            'config' => array_merge($method->config, ['instructions' => $instructions]),
        ])->save();

        return $method;
    }
}
