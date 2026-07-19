<?php

namespace App\Filament\Resources\LoanGroups\Pages;

use App\Filament\Resources\LoanGroups\LoanGroupResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateLoanGroup extends CreateRecord
{
    protected static string $resource = LoanGroupResource::class;

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
        $data['is_active'] = true;

        return $data;
    }
}
