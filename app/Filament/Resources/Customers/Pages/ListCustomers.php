<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Enums\ClientType;
use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Individual Client')
                ->url(fn (): string => static::getResource()::getUrl('create', [
                    'client_type' => ClientType::Individual->value,
                ])),
            Action::make('createBusinessClient')
                ->label('New Business Client')
                ->url(fn (): string => static::getResource()::getUrl('create', [
                    'client_type' => ClientType::Business->value,
                ])),
        ];
    }
}
