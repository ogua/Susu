<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Actions\Customers\UpdateCustomerAction;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Customer $record */
        return app(UpdateCustomerAction::class)->execute($record, $data);
    }
}
