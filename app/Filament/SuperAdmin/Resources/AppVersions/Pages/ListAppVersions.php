<?php

namespace App\Filament\SuperAdmin\Resources\AppVersions\Pages;

use App\Filament\SuperAdmin\Resources\AppVersions\AppVersionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAppVersions extends ListRecords
{
    protected static string $resource = AppVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
