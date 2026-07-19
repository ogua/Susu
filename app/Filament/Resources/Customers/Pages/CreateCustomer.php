<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Actions\Customers\CreateCustomerAction;
use App\Enums\ClientType;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Schemas\CustomerWizardSteps;
use App\Models\Customer;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Wizard\Step;

class CreateCustomer extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = CustomerResource::class;

    /**
     * Captured once in mount() from the `client_type` query string (set by
     * the "New Individual/Business Client" actions on the customer list
     * page) and persisted as a Livewire property. getSteps() is called on
     * every Livewire request, not just the initial page load — subsequent
     * requests (e.g. clicking "Next") hit Livewire's own update endpoint,
     * which has no query string of its own, so request()->query() would
     * silently fall back to individual on every step after the first.
     */
    public string $selectedClientType = 'individual';

    public function mount(): void
    {
        $this->selectedClientType = request()->query('client_type') === ClientType::Business->value
            ? ClientType::Business->value
            : ClientType::Individual->value;

        parent::mount();
    }

    /**
     * @return array<int, Step>
     */
    protected function getSteps(): array
    {
        $clientType = $this->selectedClientType === ClientType::Business->value
            ? ClientType::Business
            : ClientType::Individual;

        return CustomerWizardSteps::steps($clientType);
    }

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
