<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Modules\Catalog\Contracts\VariantCatalog;
use Modules\Inventory\Data\StockDocumentData;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Exceptions\InventoryRuleViolation;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\Supplier;
use Modules\Inventory\Support\Ledger;
use Modules\Inventory\Support\Posting;
use Modules\Inventory\Support\Resolver;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;
use Modules\Support\ValueObjects\Money;

/**
 * Goods receipt: stock arrives at a location, a `purchase_receipt` movement per line, and the
 * variant's weighted-average cost is recomputed (spec §6.5.5).
 *
 * Weighted average, in integer minor units:
 *   new = (on_hand_everywhere × old_cost + received × unit_cost) / (on_hand_everywhere + received)
 * rounded half-up. Negative on-hand (backorders) counts as zero, so owed units carry no cost.
 */
final class ReceiveStock implements Action
{
    use ChecksPermission;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Resolver $resolve,
        private readonly VariantCatalog $catalog,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return string the goods receipt ULID
     */
    public function handle(StockDocumentData $data, ?Authorizable $actor = null): string
    {
        $this->authorizePermission($actor, 'inventory.receive');

        $location = $this->resolve->location($data->locationUlid);
        $supplier = $data->supplierUlid === null ? null : Supplier::query()->where('ulid', $data->supplierUlid)->firstOrFail();
        $lines = [];

        foreach ($data->lines as $line) {
            if ($line->quantity < 1) {
                throw InventoryRuleViolation::nonPositiveQuantity();
            }

            $lines[] = [$this->resolve->variant($line->variantUlid), $line];
        }

        return $this->db->connection()->transaction(function () use ($data, $location, $supplier, $lines, $actor): string {
            $receiptUlid = (string) Str::ulid();
            $actorUlid = $this->resolve->actorUlid($actor);

            $this->db->connection()->table('goods_receipts')->insert([
                'ulid' => $receiptUlid,
                'supplier_id' => $supplier?->id,
                'location_id' => $location->id,
                'supplier_reference' => $data->supplierReference,
                'notes' => $data->notes,
                'actor_ulid' => $actorUlid,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $levels = $this->ledger->lock(array_map(fn (array $pair): array => [$pair[0]->id, $location->id], $lines));

            foreach ($lines as [$variant, $line]) {
                $unitCost = $line->unitCostAmount === null ? null : Money::of($line->unitCostAmount, $variant->currency);

                if ($unitCost !== null) {
                    $current = $this->catalog->findById($variant->id)?->averageCost;
                    $this->recalculateAverageCost($variant->id, $line->quantity, $unitCost, $current);
                }

                $this->ledger->post($levels[$variant->id.':'.$location->id], $variant, MovementType::PurchaseReceipt, $line->quantity, new Posting(
                    referenceType: 'goods_receipt',
                    referenceUlid: $receiptUlid,
                    actorUlid: $actorUlid,
                    unitCost: $unitCost,
                ));

                $this->db->connection()->table('document_lines')->insert([
                    'document_type' => 'goods_receipt',
                    'document_ulid' => $receiptUlid,
                    'variant_id' => $variant->id,
                    'quantity' => $line->quantity,
                    'unit_cost_amount' => $unitCost?->amount,
                    'unit_cost_currency' => $unitCost?->currency->value,
                ]);
            }

            return $receiptUlid;
        });
    }

    private function recalculateAverageCost(int $variantId, int $received, Money $unitCost, ?Money $current): void
    {
        $onHand = max(0, (int) StockLevel::query()->where('variant_id', $variantId)->sum('on_hand'));
        $currentCost = $current === null ? $unitCost->amount : $current->amount;

        $total = $onHand + $received;
        $weighted = $onHand * $currentCost + $received * $unitCost->amount;
        $average = intdiv($weighted * 2 + $total, $total * 2);

        $this->catalog->recordAverageCost($variantId, Money::of($average, $unitCost->currency));
    }
}
