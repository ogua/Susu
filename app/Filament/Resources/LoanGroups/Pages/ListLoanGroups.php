<?php

namespace App\Filament\Resources\LoanGroups\Pages;

use App\Filament\Resources\LoanGroups\LoanGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLoanGroups extends ListRecords
{
    protected static string $resource = LoanGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
