<?php

namespace App\Filament\Resources\SavingsProducts\Pages;

use App\Filament\Resources\SavingsProducts\SavingsProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSavingsProducts extends ListRecords
{
    protected static string $resource = SavingsProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
