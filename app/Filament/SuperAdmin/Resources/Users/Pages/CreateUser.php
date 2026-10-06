<?php

namespace App\Filament\SuperAdmin\Resources\Users\Pages;

use App\Actions\Company\SyncCompanyAdminBranchAccessAction;
use App\Filament\SuperAdmin\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        app(SyncCompanyAdminBranchAccessAction::class)->execute($this->getRecord()->unsetRelation('roles'));
    }
}
