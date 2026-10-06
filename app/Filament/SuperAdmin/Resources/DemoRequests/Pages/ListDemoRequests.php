<?php

namespace App\Filament\SuperAdmin\Resources\DemoRequests\Pages;

use App\Filament\SuperAdmin\Resources\DemoRequests\DemoRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListDemoRequests extends ListRecords
{
    protected static string $resource = DemoRequestResource::class;
}
