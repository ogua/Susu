<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Actions\Customers\UpdateCustomerAction;
use App\Filament\Resources\Customers\CustomerActions;
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
            CustomerActions::guardedDelete(DeleteAction::make()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Customer $record */
        $record = $this->getRecord();

        // Identifications/beneficiaries/family members are not part of this
        // form — their relation managers edit them row-by-row in modals, so
        // UpdateCustomerAction receives no arrays and leaves them untouched.

        // CustomerResource::getEloquentQuery() doesn't eager-load branch, so
        // the disabled branch.name TextInput has nothing to hydrate from
        // without this — it's display-only (dehydrated(false)).
        $data['branch']['name'] = $record->branch?->name;

        return $data;
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
