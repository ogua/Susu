<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\SuperAdminPanelProvider;
use Maatwebsite\Excel\ExcelServiceProvider;
use Torann\GeoIP\GeoIPServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    SuperAdminPanelProvider::class,
    ExcelServiceProvider::class,
    GeoIPServiceProvider::class,
];
