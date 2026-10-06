<?php

namespace App\Filament\SuperAdmin\Resources\Users\Pages;

use App\Actions\Company\SyncCompanyAdminBranchAccessAction;
use App\Filament\SuperAdmin\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function afterSave(): void
    {
        app(SyncCompanyAdminBranchAccessAction::class)->execute($this->getRecord()->unsetRelation('roles'));
    }
}
