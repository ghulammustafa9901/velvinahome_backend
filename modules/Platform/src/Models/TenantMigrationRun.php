<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string $release_version
 * @property int $migrations_applied
 * @property string $status
 * @property string|null $error
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
final class TenantMigrationRun extends LandlordModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'migrations_applied' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
