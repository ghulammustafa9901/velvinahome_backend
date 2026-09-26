<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Modules\Inventory\Data\StockDocumentData;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Exceptions\InventoryRuleViolation;
use Modules\Inventory\Support\Ledger;
use Modules\Inventory\Support\Posting;
use Modules\Inventory\Support\Resolver;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * Moves stock between two locations as a `transfer_out` / `transfer_in` pair per line, in one
 * transaction: the two legs can never be separated. (An in-transit state is a later
 * refinement; v1 transfers are immediate.)
 */
final class TransferStock implements Action
{
    use ChecksPermission;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Resolver $resolve,
        private readonly DatabaseManager $db,
    ) {}

    public function handle(StockDocumentData $data, ?Authorizable $actor = null): string
    {
        $this->authorizePermission($actor, 'inventory.transfer');

        $from = $this->resolve->location((string) $data->fromLocationUlid);
        $to = $this->resolve->location($data->locationUlid);

        if ($from->id === $to->id) {
            throw InventoryRuleViolation::sameLocation();
        }

        $lines = [];

        foreach ($data->lines as $line) {
            if ($line->quantity < 1) {
                throw InventoryRuleViolation::nonPositiveQuantity();
            }

            $lines[] = [$this->resolve->variant($line->variantUlid), $line->quantity];
        }

        return $this->db->connection()->transaction(function () use ($data, $from, $to, $lines, $actor): string {
            $transferUlid = (string) Str::ulid();
            $actorUlid = $this->resolve->actorUlid($actor);

            $this->db->connection()->table('stock_transfers')->insert([
                'ulid' => $transferUlid,
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'notes' => $data->notes,
                'actor_ulid' => $actorUlid,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $pairs = [];
            foreach ($lines as [$variant]) {
                $pairs[] = [$variant->id, $from->id];
                $pairs[] = [$variant->id, $to->id];
            }

            $levels = $this->ledger->lock($pairs);
            $posting = new Posting(referenceType: 'stock_transfer', referenceUlid: $transferUlid, actorUlid: $actorUlid);

            foreach ($lines as [$variant, $quantity]) {
                $this->ledger->post($levels[$variant->id.':'.$from->id], $variant, MovementType::TransferOut, -$quantity, $posting);
                $this->ledger->post($levels[$variant->id.':'.$to->id], $variant, MovementType::TransferIn, $quantity, $posting);

                $this->db->connection()->table('document_lines')->insert([
                    'document_type' => 'stock_transfer',
                    'document_ulid' => $transferUlid,
                    'variant_id' => $variant->id,
                    'quantity' => $quantity,
                ]);
            }

            return $transferUlid;
        });
    }
}
