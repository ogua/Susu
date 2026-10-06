<?php

namespace App\Filament\SuperAdmin\Resources\AppVersions\Pages;

use App\Filament\SuperAdmin\Resources\AppVersions\AppVersionResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateAppVersion extends CreateRecord
{
    protected static string $resource = AppVersionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Filament::auth()->id();

        return $data;
    }
}
