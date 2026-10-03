<?php

declare(strict_types=1);

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Modules\Support\Concerns\HasUlid;

/**
 * A shopper. Authenticates with a Sanctum token that the storefront BFF keeps in an httpOnly
 * cookie (ADR-0003).
 *
 * @property int $id
 * @property string $ulid
 * @property string $email
 * @property string|null $phone
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $password
 * @property bool $accepts_marketing
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_login_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class Customer extends Authenticatable
{
    use HasApiTokens;
    use HasUlid;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'accepts_marketing' => 'boolean',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }
}
