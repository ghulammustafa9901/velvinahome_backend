<?php

declare(strict_types=1);

namespace Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Support\Casts\MoneyCast;
use Modules\Support\Concerns\HasUlid;
use Modules\Support\ValueObjects\Money;

/**
 * @property int $id
 * @property string $ulid
 * @property string $order_ulid
 * @property string $method_code
 * @property string $status
 * @property Money $amount
 * @property int $paid_amount
 * @property int $refunded_amount
 * @property string|null $gateway_reference
 * @property string|null $proof_path
 * @property string|null $idempotency_key
 * @property string|null $failure_reason
 * @property Carbon|null $confirmed_at
 */
final class Payment extends Model
{
    use HasUlid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'paid_amount' => 'integer',
            'refunded_amount' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PaymentTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class)->orderBy('id');
    }

    public function paid(): Money
    {
        return Money::of($this->paid_amount, $this->amount->currency);
    }

    public function refunded(): Money
    {
        return Money::of($this->refunded_amount, $this->amount->currency);
    }
}
