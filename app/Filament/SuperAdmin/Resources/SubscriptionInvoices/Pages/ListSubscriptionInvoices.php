<?php

namespace App\Filament\SuperAdmin\Resources\SubscriptionInvoices\Pages;

use App\Filament\SuperAdmin\Resources\SubscriptionInvoices\SubscriptionInvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptionInvoices extends ListRecords
{
    protected static string $resource = SubscriptionInvoiceResource::class;
}
