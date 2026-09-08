<?php

namespace App\Filament\Resources\LoanGroups\Pages;

use App\Filament\Resources\LoanGroups\LoanGroupResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewLoanGroup extends ViewRecord
{
    protected static string $resource = LoanGroupResource::class;

    protected function getActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
