<?php

declare(strict_types=1);

namespace Modules\Imports\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $source
 * @property string $entity
 * @property string $external_id
 * @property string $local_ref
 */
final class ImportMapping extends Model
{
    protected $guarded = ['id'];
}
