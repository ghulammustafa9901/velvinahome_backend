<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Modules\Inventory\Data\StockDocumentData;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Support\Ledger;
use Modules\Inventory\Support\Posting;
use Modules\Inventory\Support\Resolver;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

/**
 * A cycle count or stocktake: each counted quantity is compared with `on_hand` under lock and
 * any difference becomes a `stock_count` movement. Lines that match produce no movement but
 * are still recorded on the count document (a "counted, correct" is information too).
 */
final class ApplyStockCount implements Action
{
    use ChecksPermission;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Resolver $resolve,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return array{count: string, differences: int}
     */
    public function handle(StockDocumentData $data, ?Authorizable $actor = null): array
    {
        $this->authorizePermission($actor, 'inventory.adjust');

        $location = $this->resolve->location($data->locationUlid);
        $lines = array_map(fn ($line): array => [$this->resolve->variant($line->variantUlid), $line->quantity], $data->lines);

        return $this->db->connection()->transaction(function () use ($data, $location, $lines, $actor): array {
            $countUlid = (string) Str::ulid();
            $actorUlid = $this->resolve->actorUlid($actor);
            $differences = 0;

            $this->db->connection()->table('stock_counts')->insert([
                'ulid' => $countUlid,
                'location_id' => $location->id,
                'notes' => $data->notes,
                'actor_ulid' => $actorUlid,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $levels = $this->ledger->lock(array_map(fn (array $line): array => [$line[0]->id, $location->id], $lines));

            foreach ($lines as [$variant, $counted]) {
                $level = $levels[$variant->id.':'.$location->id];
                $expected = $level->on_hand;

                if ($counted !== $expected) {
                    $differences++;
                    $this->ledger->post($level, $variant, MovementType::StockCount, $counted - $expected, new Posting(
                        referenceType: 'stock_count',
                        referenceUlid: $countUlid,
                        reason: 'Counted '.$counted.', system had '.$expected,
                        actorUlid: $actorUlid,
                    ));
                }

                $this->db->connection()->table('document_lines')->insert([
                    'document_type' => 'stock_count',
                    'document_ulid' => $countUlid,
                    'variant_id' => $variant->id,
                    'quantity' => $counted,
                    'expected_quantity' => $expected,
                ]);
            }

            return ['count' => $countUlid, 'differences' => $differences];
        });
    }
}
