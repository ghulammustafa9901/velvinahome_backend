<?php

declare(strict_types=1);

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $from_path
 * @property string $to_path
 * @property int $status_code
 * @property int $hits
 * @property Carbon|null $last_hit_at
 * @property string $source
 */
final class Redirect extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }
}
