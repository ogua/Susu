<?php

namespace App\Providers;

use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Sajjadhossainshohag\Paystack\Facades\Paystack;

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
        $loader->alias('GeoIP', \Torann\GeoIP\Facades\GeoIP::class);
        $loader->alias('Paystack', Paystack::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
