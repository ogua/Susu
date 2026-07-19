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
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Customer $record */
        $record = $this->getRecord();

        $data['identifications'] = $record->identifications()
            ->get(['id', 'id_type', 'id_number', 'issue_date', 'expiry_date', 'description', 'is_primary'])
            ->toArray();
        $data['beneficiaries'] = $record->beneficiaries()
            ->get(['id', 'name', 'relationship', 'amount_of_legacy', 'phone', 'address', 'town', 'county', 'state_region'])
            ->toArray();
        $data['family_members'] = $record->familyMembers()
            ->get(['id', 'name', 'relationship', 'contact_phone', 'occupation'])
            ->toArray();

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
