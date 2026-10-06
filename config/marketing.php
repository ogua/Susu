<?php

/*
|--------------------------------------------------------------------------
| Public website (OguaFinance marketing pages)
|--------------------------------------------------------------------------
|
| Copy-level settings for the public pages served at "/". Support contact
| details prefer the super admin's Platform Settings (config('platform.*'))
| and fall back to the company contacts here. Links to the sibling Ogua
| products come from config/ogua_family.php, not from this file.
|
*/

return [

    'product_name' => 'OguaFinance',

    'company_name' => 'Oguses IT Solutions',

    'address' => 'Teshie - Nungua Estate, Opposite Maxxon Filling Station, Accra, Ghana',

    'phone' => '+233 54 581 9229',
    'phone_tel' => '+233545819229',

    'phone_2' => '+233 27 218 5090',
    'phone_2_tel' => '+233272185090',

    // WhatsApp number in international format without "+" (wa.me links).
    'whatsapp' => '233545819229',

    'email' => 'ogusesitsolutions@gmail.com',

    // Optional Google Analytics 4 measurement id; nothing is loaded when empty.
    'ga_measurement_id' => env('MARKETING_GA_ID'),

];
