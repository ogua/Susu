<?php

namespace App\Filament\SuperAdmin\Resources\Companies\RelationManagers;

use App\Filament\SuperAdmin\Resources\SubscriptionInvoices\Tables\SubscriptionInvoicesTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptionInvoices';

    protected static ?string $title = 'Invoices';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return SubscriptionInvoicesTable::configure($table, showCompany: false)
            ->recordTitleAttribute('number');
    }
}
