<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property int $platform_user_id
 * @property string $tenant_id
 * @property string $target_staff_user_ulid
 * @property string $reason
 * @property string $token_hash
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $ended_at
 * @property string|null $ip
 * @property string|null $user_agent
 * @property-read PlatformUser $platformUser
 * @property-read Tenant $tenant
 */
final class ImpersonationSession extends LandlordModel
{
    use HasUlid;

    public $timestamps = false;

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PlatformUser, $this>
     */
    public function platformUser(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
