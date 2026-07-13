<?php

namespace App\Filament\Resources\PaymentIntents\Pages;

use App\Filament\Resources\PaymentIntents\PaymentIntentResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentIntents extends ListRecords
{
    protected static string $resource = PaymentIntentResource::class;
}
