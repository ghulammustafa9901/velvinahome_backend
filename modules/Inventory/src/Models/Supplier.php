<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string|null $contact_name
 * @property string|null $email
 * @property string|null $phone
 */
final class Supplier extends Model
{
    use HasUlid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['address' => 'array'];
    }
}
