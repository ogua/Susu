<?php

return [

    /*
    | Where each client gets a new build. Used when a published release
    | (Super Admin → Operations → App Releases) leaves its own link blank, so
    | a device told to update is never left without somewhere to go.
    */
    'store_url_android' => env('APP_UPDATE_STORE_URL_ANDROID', 'https://play.google.com/store/apps/details?id=com.oguaschoolz.susuapp'),

    'store_url_ios' => env('APP_UPDATE_STORE_URL_IOS'),

    'download_url_desktop' => env('APP_UPDATE_DOWNLOAD_URL_DESKTOP'),

];
