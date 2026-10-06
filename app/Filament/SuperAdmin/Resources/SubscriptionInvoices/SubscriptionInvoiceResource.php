<?php

namespace App\Filament\SuperAdmin\Resources\SubscriptionInvoices;

use App\Filament\SuperAdmin\Resources\SubscriptionInvoices\Pages\ListSubscriptionInvoices;
use App\Filament\SuperAdmin\Resources\SubscriptionInvoices\Tables\SubscriptionInvoicesTable;
use App\Models\SubscriptionInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Every subscription invoice on the platform. Invoices are issued by billing:run, never by hand. */
class SubscriptionInvoiceResource extends Resource
{
    protected static ?string $model = SubscriptionInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Invoices';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    public static function table(Table $table): Table
    {
        return SubscriptionInvoicesTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $overdue = SubscriptionInvoice::query()->where('status', 'unpaid')->where('due_at', '<', now())->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptionInvoices::route('/'),
        ];
    }
}
