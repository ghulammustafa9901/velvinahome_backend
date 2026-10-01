<?php

declare(strict_types=1);

namespace Modules\Platform\Models;

use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $tenant_id
 * @property int $backup_id
 * @property string $mode
 * @property string $status
 * @property int $tables_checked
 * @property int $rows_checked
 * @property list<string> $differences
 * @property string|null $error
 * @property int|null $duration_ms
 * @property string|null $actor_ulid
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
final class TenantRestore extends LandlordModel
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'differences' => 'array',
            'tables_checked' => 'integer',
            'rows_checked' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
