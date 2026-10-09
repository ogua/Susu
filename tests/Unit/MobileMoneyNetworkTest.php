<?php

use App\Services\Ussd\MobileMoneyNetwork;

it('detects the network from any number format', function (string $number, ?string $provider) {
    expect(MobileMoneyNetwork::detect($number))->toBe($provider);
})->with([
    'international' => ['233241234567', 'mtn'],
    'plus and spaces' => ['+233 55 123 4567', 'mtn'],
    'local' => ['0201234567', 'vod'],
    'AirtelTigo 057' => ['0571234567', 'atl'],
    'MTN 025' => ['0251234567', 'mtn'],
    'unknown prefix' => ['0301234567', null],
]);

it('names each network as callers know it', function () {
    expect(MobileMoneyNetwork::name('mtn'))->toBe('MTN MoMo')
        ->and(MobileMoneyNetwork::name('vod'))->toBe('Telecel Cash')
        ->and(MobileMoneyNetwork::name('atl'))->toBe('AirtelTigo Money');
});
