<?php

/*
|--------------------------------------------------------------------------
| Ogua product family
|--------------------------------------------------------------------------
|
| Every Ogua product site links to its siblings and to OguSes IT Solutions.
| The list lives on the company site (includes/config.php → /products.json)
| and is pulled daily by `php artisan ogua-family:refresh` into the cache;
| App\Services\OguaFamily reads that cache and never calls the network on a
| page request. `fallback` is a bundled snapshot of the feed, shown until
| the first successful refresh — keep it in sync when a product changes
| (see the "Ogua product family" section of CLAUDE.md).
|
*/

return [

    'feed_url' => env('OGUA_FAMILY_FEED_URL', 'https://ogusesitsolutions.com/products.json'),

    // This product's slug in the feed, so it is left out of its own "family" links.
    'current' => 'oguafinance',

    'fallback' => [
        'schema' => 1,
        'company' => [
            'name' => 'Oguses IT Solutions',
            'url' => 'https://ogusesitsolutions.com',
            'tagline' => 'Coding our passion',
            'email' => 'ogusesitsolutions@gmail.com',
            'phones' => ['+233 27 218 5090', '+233 54 581 9229'],
            'address' => 'Teshie - Nungua Estate, Opposite Maxxon Filling Station, Accra, Ghana',
        ],
        'products' => [
            [
                'slug' => 'oguaschoolz',
                'name' => 'OguaSchoolz',
                'category' => 'School management',
                'tagline' => 'School management, simplified for Basic, Secondary & Tertiary institutions.',
                'accent_color' => '#2E5AAC',
                'accent_text_color' => '#2E5AAC',
                'website_url' => 'https://oguaschoolz.com',
                'login_url' => 'https://oguaschoolz.com/admin',
                'company_page_url' => 'https://ogusesitsolutions.com/oguaschoolz.php',
                'status' => 'live',
            ],
            [
                'slug' => 'oguapos',
                'name' => 'OguaPOS',
                'category' => 'Point of sale & inventory',
                'tagline' => 'Point of sale & inventory for stores, supermarkets and pharmacies.',
                'accent_color' => '#1E9E6B',
                'accent_text_color' => '#147F58',
                'website_url' => 'https://pos.oguaschoolz.com',
                'login_url' => 'https://pos.oguaschoolz.com/admin/login',
                'company_page_url' => 'https://ogusesitsolutions.com/oguapos.php',
                'status' => 'live',
            ],
            [
                'slug' => 'oguafinance',
                'name' => 'OguaFinance',
                'category' => 'Susu & microfinance',
                'tagline' => 'Digital transformation for Susu, loans and microfinance.',
                'accent_color' => '#D99A1E',
                'accent_text_color' => '#9C5E14',
                'website_url' => 'https://finance.oguaschoolz.com',
                'login_url' => 'https://finance.oguaschoolz.com/admin/login',
                'company_page_url' => 'https://ogusesitsolutions.com/oguafinance.php',
                'status' => 'coming_soon',
            ],
            [
                'slug' => 'oguachurch',
                'name' => 'OguaChurch',
                'category' => 'Church management',
                'tagline' => 'Church management for giving, membership, ministries and media.',
                'accent_color' => '#0E3C5D',
                'accent_text_color' => '#0E3C5D',
                'website_url' => 'https://oguachurch.oguaschoolz.com',
                'login_url' => 'https://oguachurch.oguaschoolz.com/admin/login',
                'company_page_url' => 'https://ogusesitsolutions.com/oguachurch.php',
                'status' => 'live',
            ],
            [
                'slug' => 'oguacare',
                'name' => 'OguaCare+',
                'category' => 'Hospital & clinic management',
                'tagline' => 'Hospital & clinic management, from front desk to discharge.',
                'accent_color' => '#059669',
                'accent_text_color' => '#047857',
                'website_url' => 'https://oguacareplus.com',
                'login_url' => null,
                'company_page_url' => 'https://ogusesitsolutions.com/oguacare.php',
                'status' => 'coming_soon',
            ],
        ],
    ],

];
