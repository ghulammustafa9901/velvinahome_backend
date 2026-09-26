<?php

declare(strict_types=1);

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Modules\Identity\Contracts\StaffDirectory;
use Modules\Platform\Actions\IssueBrandSwitch;
use Modules\Platform\Models\Tenant;

/**
 * GET /_switch/{tenant} on a tenant admin host, for a signed-in company owner.
 */
final class BrandSwitchController
{
    public function __invoke(IssueBrandSwitch $issue, StaffDirectory $staff, string $tenant): RedirectResponse
    {
        $current = tenant();
        $staffUlid = $staff->currentStaffUlid();
        $target = Tenant::query()->whereKey($tenant)->first();

        abort_unless($current instanceof Tenant && $staffUlid !== null && $target !== null, 404);

        return redirect()->away($issue->handle($current, $staffUlid, $target));
    }
}
