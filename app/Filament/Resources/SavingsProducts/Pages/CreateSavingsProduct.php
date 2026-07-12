<?php

namespace App\Filament\Resources\SavingsProducts\Pages;

use App\Filament\Resources\SavingsProducts\SavingsProductResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateSavingsProduct extends CreateRecord
{
    protected static string $resource = SavingsProductResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()->company_id;

        return $data;
    }
}
