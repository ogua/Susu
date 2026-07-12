<?php

namespace App\Providers;

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Maatwebsite\Excel\Facades\Excel;
use Torann\GeoIP\Facades\GeoIP;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $loader = AliasLoader::getInstance();

        // Add your aliases
        $loader->alias('Excel', Excel::class);
        $loader->alias('GeoIP', GeoIP::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        FilamentAsset::register([
            Css::make('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'),
            Js::make('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'),
        ]);
    }
}
