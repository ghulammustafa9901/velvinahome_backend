<?php

declare(strict_types=1);

namespace Modules\Integrations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Support\Concerns\HasUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property Carbon $available_at
 * @property Carbon|null $published_at
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon $created_at
 */
final class OutboxMessage extends Model
{
    use HasUlid;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
