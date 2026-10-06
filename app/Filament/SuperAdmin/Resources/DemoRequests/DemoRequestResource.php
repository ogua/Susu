<?php

namespace App\Filament\SuperAdmin\Resources\DemoRequests;

use App\Filament\SuperAdmin\Resources\DemoRequests\Pages\ListDemoRequests;
use App\Filament\SuperAdmin\Resources\DemoRequests\Pages\ViewDemoRequest;
use App\Filament\SuperAdmin\Resources\DemoRequests\Tables\DemoRequestsTable;
use App\Models\DemoRequest;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Demo requests from the public OguaFinance website. Read-only leads:
 * super admins follow up outside the app and mark each one handled.
 */
class DemoRequestResource extends Resource
{
    protected static ?string $model = DemoRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $recordTitleAttribute = 'organisation';

    public static function getNavigationBadge(): ?string
    {
        $open = DemoRequest::query()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function table(Table $table): Table
    {
        return DemoRequestsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('organisation'),
                        TextEntry::make('organisation_type')->label('Type')->badge(),
                        TextEntry::make('branches_count')->label('Branches')->placeholder('—'),
                        TextEntry::make('email')->copyable(),
                        TextEntry::make('phone')->copyable(),
                        TextEntry::make('message')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('created_at')->label('Received')->dateTime(),
                        TextEntry::make('handled_at')->label('Handled')->dateTime()->placeholder('Open'),
                        TextEntry::make('handler.name')->label('Handled by')->placeholder('—'),
                        TextEntry::make('ip_address')->label('IP address')->placeholder('—'),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDemoRequests::route('/'),
            'view' => ViewDemoRequest::route('/{record}'),
        ];
    }
}
