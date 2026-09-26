<?php

declare(strict_types=1);

namespace Modules\Identity\Filament\Resources\Staff\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Identity\Actions\CreateStaffMember;
use Modules\Identity\Data\StaffMemberData;
use Modules\Identity\Filament\Resources\Staff\StaffResource;
use Modules\Identity\Models\StaffUser;

final class CreateStaffPage extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth('staff')->user();
        abort_unless($actor instanceof StaffUser, 403);

        $result = app(CreateStaffMember::class)->handle(StaffMemberData::validateAndCreate($data), $actor);

        Notification::make()
            ->title('Staff member added')
            ->body(sprintf('Temporary password for %s (shown once): %s', $result['staff']->email, $result['password']))
            ->persistent()
            ->warning()
            ->send();

        return $result['staff'];
    }
}
