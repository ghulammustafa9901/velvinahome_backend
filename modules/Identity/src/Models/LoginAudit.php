<?php

declare(strict_types=1);

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only; the application role cannot update or delete these rows.
 *
 * @property int $id
 * @property string $subject_type
 * @property string $email_attempted
 * @property int|null $user_id
 * @property bool $successful
 * @property string|null $failure_reason
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
final class LoginAudit extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
        ];
    }
}
