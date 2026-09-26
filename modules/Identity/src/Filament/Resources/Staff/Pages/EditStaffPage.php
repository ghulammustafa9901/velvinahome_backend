<?php

declare(strict_types=1);

namespace Modules\Identity\Filament\Resources\Staff\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Identity\Actions\UpdateStaffMember;
use Modules\Identity\Data\StaffMemberData;
use Modules\Identity\Filament\Resources\Staff\StaffResource;
use Modules\Identity\Models\StaffUser;

final class EditStaffPage extends EditRecord
{
    protected static string $resource = StaffResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if ($record instanceof StaffUser) {
            $data['role'] = $record->getRoleNames()->first();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth('staff')->user();
        abort_unless($actor instanceof StaffUser && $record instanceof StaffUser, 403);

        return app(UpdateStaffMember::class)->handle($record, StaffMemberData::validateAndCreate([
            'name' => $data['name'] ?? $record->name,
            'email' => $record->email,
            'role' => $data['role'] ?? null,
            'status' => $data['status'] ?? $record->status,
        ]), $actor);
    }
}
