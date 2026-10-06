<?php

namespace App\Filament\Resources\LoanGroups\Pages;

use App\Actions\LoanGroups\CreateLoanGroupAction;
use App\Filament\Resources\LoanGroups\LoanGroupResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLoanGroup extends CreateRecord
{
    protected static string $resource = LoanGroupResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateLoanGroupAction::class)->execute(Filament::auth()->user(), Filament::getTenant(), $data);
    }
}
