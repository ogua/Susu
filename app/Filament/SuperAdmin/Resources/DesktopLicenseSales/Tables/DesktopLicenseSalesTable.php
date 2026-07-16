<?php

namespace App\Filament\SuperAdmin\Resources\DesktopLicenseSales\Tables;

use App\Enums\LicenseSaleStatus;
use App\Models\DesktopLicenseSale;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DesktopLicenseSalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('install_id')->searchable(),
                TextColumn::make('customer_name')->label('Customer')->searchable(),
                TextColumn::make('customer_email')->searchable(),
                TextColumn::make('amount')
                    ->formatStateUsing(fn (int $state, DesktopLicenseSale $record): string => Money::format($state, $record->currency)),
                TextColumn::make('status')->badge(),
                TextColumn::make('source')->badge(),
                TextColumn::make('expires_at')->date()->placeholder('—'),
                TextColumn::make('issuedBy.name')->label('Issued By')->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(LicenseSaleStatus::class),
                SelectFilter::make('source')->options([
                    'web_purchase' => 'Web purchase',
                    'admin_manual' => 'Admin manual',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No license sales yet.');
    }
}
