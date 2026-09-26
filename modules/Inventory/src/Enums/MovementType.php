<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum MovementType: string
{
    case PurchaseReceipt = 'purchase_receipt';
    case Sale = 'sale';
    case Return = 'return';
    case Adjustment = 'adjustment';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case StockCount = 'stock_count';
    case Damage = 'damage';

    /**
     * Movements a person initiates by judgement rather than by a document must say why.
     */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Adjustment, self::Damage], true);
    }
}
