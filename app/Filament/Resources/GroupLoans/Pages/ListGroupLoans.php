<?php

namespace App\Filament\Resources\GroupLoans\Pages;

use App\Filament\Resources\GroupLoans\GroupLoanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGroupLoans extends ListRecords
{
    protected static string $resource = GroupLoanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
