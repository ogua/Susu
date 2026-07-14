<?php

namespace App\Filament\Resources\Groups\Pages;

use App\Filament\Resources\Groups\GroupResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateGroup extends CreateRecord
{
    protected static string $resource = GroupResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $branch = Filament::getTenant();

        $data['company_id'] = $branch->company_id;
        $data['branch_id'] = $branch->id;
        $data['created_by'] = Filament::auth()->id();
        $data['status'] = 'draft';

        return $data;
    }
}
