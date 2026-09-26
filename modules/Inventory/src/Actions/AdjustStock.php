<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Modules\Inventory\Data\StockAdjustmentData;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Support\Ledger;
use Modules\Inventory\Support\Posting;
use Modules\Inventory\Support\Resolver;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Manual stock correction or damage write-off, always with a reason and always as a ledger
 * movement — there is no "set stock to N" that bypasses the ledger.
 */
final class AdjustStock implements Action
{
    use ChecksPermission;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Resolver $resolve,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(StockAdjustmentData $data, ?Authorizable $actor = null): InventoryMovement
    {
        $this->authorizePermission($actor, 'inventory.adjust');

        $variant = $this->resolve->variant($data->variantUlid);
        $location = $this->resolve->location($data->locationUlid);

        return $this->db->connection()->transaction(function () use ($data, $variant, $location, $actor): InventoryMovement {
            $level = $this->ledger->lock([[$variant->id, $location->id]])[$variant->id.':'.$location->id];

            return $this->ledger->post($level, $variant, MovementType::from($data->type), $data->quantity, new Posting(
                reason: $data->reason,
                actorUlid: $this->resolve->actorUlid($actor),
            ));
        });
    }
}
