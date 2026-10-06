<?php

namespace App\Filament\SuperAdmin\Resources\Users\Pages;

use App\Actions\Company\SyncCompanyAdminBranchAccessAction;
use App\Actions\Staff\SendStaffCredentialsAction;
use App\Filament\SuperAdmin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

/**
 * Like staff created in the admin panel, a tenant user created here gets a
 * temporary password: it is sent to them and must be changed at first sign-in.
 */
class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    private ?string $temporaryPassword = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->temporaryPassword = $data['password'] ?? null;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->getRecord()->unsetRelation('roles');

        app(SyncCompanyAdminBranchAccessAction::class)->execute($user);

        if ($user->company_id !== null && filled($this->temporaryPassword)) {
            $user->update(['must_change_password' => true]);
            app(SendStaffCredentialsAction::class)->execute($user, $this->temporaryPassword);
        }

        $this->temporaryPassword = null;
    }
}
