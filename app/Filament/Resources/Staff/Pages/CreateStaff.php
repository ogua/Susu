<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Actions\Staff\CreateStaffAction;
use App\Filament\Resources\Staff\StaffResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): User
    {
        return app(CreateStaffAction::class)->execute(
            actor: Filament::auth()->user(),
            tenant: Filament::getTenant(),
            data: $data,
        );
    }
}
