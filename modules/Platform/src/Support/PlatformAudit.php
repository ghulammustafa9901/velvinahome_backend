<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

use Illuminate\Http\Request;
use Modules\Platform\Models\PlatformActivity;
use Modules\Platform\Models\PlatformUser;

/**
 * Writes the append-only platform audit trail. Every platform-side change to a tenant —
 * create, suspend, delete, impersonate — lands here with who and from where (spec §9).
 */
final class PlatformAudit
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        string $event,
        ?PlatformUser $actor = null,
        ?string $tenantId = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $properties = [],
    ): PlatformActivity {
        return PlatformActivity::query()->create([
            'platform_user_id' => $actor?->id,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'tenant_id' => $tenantId,
            'properties' => $properties + ['via' => app()->runningInConsole() ? 'console' : 'web'],
            'ip' => app()->runningInConsole() ? null : $this->request->ip(),
            'user_agent' => app()->runningInConsole() ? null : $this->request->userAgent(),
        ]);
    }
}
