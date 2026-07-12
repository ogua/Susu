<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\Filament\SuperAdminPanelProvider::class,
    Maatwebsite\Excel\ExcelServiceProvider::class,
    Sajjadhossainshohag\Paystack\PaystackServiceProvider::class,
    \Torann\GeoIP\GeoIPServiceProvider::class,
];