<?php

declare(strict_types=1);

namespace Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $key
 * @property Carbon $value
 */
final class ReportCursor extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'report_cursors';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'datetime'];
    }
}
