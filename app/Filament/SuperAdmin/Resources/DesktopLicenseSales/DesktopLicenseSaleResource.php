<?php

namespace App\Filament\SuperAdmin\Resources\DesktopLicenseSales;

use App\Filament\SuperAdmin\Resources\DesktopLicenseSales\Pages\ListDesktopLicenseSales;
use App\Filament\SuperAdmin\Resources\DesktopLicenseSales\Pages\ViewDesktopLicenseSale;
use App\Filament\SuperAdmin\Resources\DesktopLicenseSales\Tables\DesktopLicenseSalesTable;
use App\Models\DesktopLicenseSale;
use App\Support\Money;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Every desktop activation key sale, web purchase or admin-manual — this
 * page also has the "Generate Key" header action (ListDesktopLicenseSales),
 * so it doubles as the mint-a-key tool that used to be a standalone page.
 */
class DesktopLicenseSaleResource extends Resource
{
    protected static ?string $model = DesktopLicenseSale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'License Sales';

    protected static ?string $recordTitleAttribute = 'install_id';

    public static function table(Table $table): Table
    {
        return DesktopLicenseSalesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sale')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('install_id'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('source')->badge(),
                        TextEntry::make('amount')
                            ->formatStateUsing(fn (int $state, DesktopLicenseSale $record): string => Money::format($state, $record->currency)),
                        TextEntry::make('customer_name')->label('Customer'),
                        TextEntry::make('customer_email'),
                        TextEntry::make('customer_phone')->placeholder('—'),
                        TextEntry::make('issuedBy.name')->label('Issued By')->placeholder('—'),
                        TextEntry::make('expires_at')->date()->placeholder('—'),
                        TextEntry::make('notified_at')->dateTime()->placeholder('Not sent'),
                        TextEntry::make('created_at')->dateTime(),
                    ]),

                Section::make('Activation key')
                    ->schema([
                        TextEntry::make('license_key')
                            ->hiddenLabel()
                            ->placeholder('Not issued yet')
                            ->copyable()
                            ->fontFamily(FontFamily::Mono)
                            ->columnSpanFull(),
                    ]),

                Section::make('Raw payment response')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('raw_response')
                            ->hiddenLabel()
                            ->formatStateUsing(fn (?array $state): string => $state ? json_encode($state, JSON_PRETTY_PRINT) : '—')
                            ->fontFamily(FontFamily::Mono)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDesktopLicenseSales::route('/'),
            'view' => ViewDesktopLicenseSale::route('/{record}'),
        ];
    }
}
