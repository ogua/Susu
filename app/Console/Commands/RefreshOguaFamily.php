<?php

namespace App\Console\Commands;

use App\Services\OguaFamily;
use Illuminate\Console\Command;

/** Pulls the Ogua product-family feed from the company site into the cache. */
class RefreshOguaFamily extends Command
{
    protected $signature = 'ogua-family:refresh';

    protected $description = 'Refreshes the cached Ogua product-family links from the company site feed.';

    public function handle(OguaFamily $family): int
    {
        if (! $family->refresh()) {
            $this->warn('Could not refresh the Ogua family feed; keeping the previously cached links.');

            return self::FAILURE;
        }

        $this->info('Ogua family feed refreshed: '.count($family->products(includeCurrent: true)).' product(s).');

        return self::SUCCESS;
    }
}
