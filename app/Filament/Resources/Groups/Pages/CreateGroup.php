<?php

namespace App\Filament\Resources\Groups\Pages;

use App\Actions\Groups\CreateGroupAction;
use App\Filament\Resources\Groups\GroupResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGroup extends CreateRecord
{
    protected static string $resource = GroupResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateGroupAction::class)->execute(Filament::auth()->user(), Filament::getTenant(), $data);
    }
}
