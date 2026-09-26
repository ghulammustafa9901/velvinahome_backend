<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where a tenant's database lives and the two roles that may reach it. Passwords are
 * encrypted with APP_KEY, so a copy of the landlord database alone opens nothing
 * (database-roles.md §4).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $host
 * @property int $port
 * @property string $database
 * @property string $app_username
 * @property string $app_password
 * @property string $migration_username
 * @property string $migration_password
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class TenantDatabase extends LandlordModel
{
    protected $hidden = ['app_password', 'migration_password'];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'app_password' => 'encrypted',
            'migration_password' => 'encrypted',
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
