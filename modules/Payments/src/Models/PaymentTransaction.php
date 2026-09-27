<?php

declare(strict_types=1);

namespace Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Support\Casts\MoneyCast;
use Modules\Support\Concerns\HasUlid;
use Modules\Support\ValueObjects\Money;

/**
 * Append-only: a refund of a capture is a new `refund` row, never an edit.
 *
 * @property int $id
 * @property string $ulid
 * @property int $payment_id
 * @property string $type
 * @property Money $amount
 * @property string|null $reference
 * @property string|null $note
 * @property string|null $actor_ulid
 */
final class PaymentTransaction extends Model
{
    use HasUlid;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class, 'gateway_response' => 'array'];
    }
}
