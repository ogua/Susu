<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Actions\Customers\CreateCustomerAction;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Customer
    {
        return app(CreateCustomerAction::class)->execute(
            registeredBy: Filament::auth()->user(),
            branch: Filament::getTenant(),
            data: $data,
        );
    }
}
