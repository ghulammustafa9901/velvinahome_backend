<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\DatabaseManager;
use Modules\Inventory\Data\LocationData;
use Modules\Inventory\Models\Location;
use Modules\Support\Authorization\ChecksPermission;
use Modules\Support\Contracts\Action;

final class CreateLocation implements Action
{
    use ChecksPermission;

    public function __construct(private readonly DatabaseManager $db) {}

    public function handle(LocationData $data, ?Authorizable $actor = null): Location
    {
        $this->authorizePermission($actor, 'inventory.adjust');

        return $this->db->connection()->transaction(function () use ($data): Location {
            $isFirst = ! Location::query()->exists();

            if ($data->isDefault && ! $isFirst) {
                Location::query()->where('is_default', true)->update(['is_default' => false]);
            }

            return Location::query()->create([
                'name' => $data->name,
                'code' => strtoupper($data->code),
                'type' => $data->type,
                'address' => $data->address,
                'is_default' => $data->isDefault || $isFirst,
                'is_active' => true,
                'priority' => $data->priority,
            ]);
        });
    }
}
