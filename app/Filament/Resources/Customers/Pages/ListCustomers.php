<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Enums\ClientType;
use App\Enums\CustomerSegment;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Widgets\CustomerOverview;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

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

    protected function getHeaderWidgets(): array
    {
        return [CustomerOverview::class];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [];

        foreach (CustomerSegment::cases() as $segment) {
            $tabs[$segment->value] = Tab::make($segment->label())
                ->modifyQueryUsing(fn (Builder $query): Builder => $segment->apply($query))
                ->badge(fn (): int => $segment->apply(CustomerResource::getEloquentQuery())->count())
                ->badgeColor($segment->color())
                ->deferBadge();
        }

        return $tabs;
    }
}
