<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string $kind
 * @property string|null $location
 * @property int|null $size_bytes
 * @property string|null $checksum
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string $status
 * @property string|null $error
 */
final class TenantBackup extends LandlordModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
