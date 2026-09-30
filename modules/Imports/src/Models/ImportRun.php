<?php

declare(strict_types=1);

namespace Modules\Imports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property string $source
 * @property string $status
 * @property bool $dry_run
 * @property array<string, mixed> $options
 * @property array<string, string>|null $credentials
 * @property string|null $file_path
 * @property string|null $step
 * @property array<string, array<string, int>> $counts
 * @property list<array{level: string, entity: string, ref: string|null, message: string}> $issues
 * @property int $issue_count
 * @property string|null $error
 * @property string|null $actor_ulid
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 */
final class ImportRun extends Model
{
    use HasUlid;

    public const SOURCE_WOOCOMMERCE = 'woocommerce';

    public const SOURCE_CSV_PRODUCTS = 'csv_products';

    public const SOURCE_CSV_STOCK = 'csv_stock';

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'options' => 'array',
            'credentials' => 'encrypted:array',
            'counts' => 'array',
            'issues' => 'array',
            'issue_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }
}
