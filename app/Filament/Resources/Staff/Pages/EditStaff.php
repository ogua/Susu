<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Actions\Staff\UpdateStaffAction;
use App\Filament\Resources\Staff\StaffResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $record */
        $record = $this->getRecord();

        $data['role'] = $record->roles->pluck('name')->first();
        $data['branch_ids'] = $record->branches->pluck('id')->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        return app(UpdateStaffAction::class)->execute(
            actor: Filament::auth()->user(),
            target: $record,
            tenant: Filament::getTenant(),
            data: $data,
        );
    }
}
