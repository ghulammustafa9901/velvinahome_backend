<?php

declare(strict_types=1);

namespace Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property list<array{key: string, label: string, kind: string, required: bool}> $fields
 */
final class ContentType extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fields' => 'array'];
    }

    /**
     * @return HasMany<ContentEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(ContentEntry::class);
    }
}
