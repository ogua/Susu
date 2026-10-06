<?php

namespace App\Filament\SuperAdmin\Resources\Plans\Tables;

use App\Models\Plan;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('price_amount')
                    ->label('Price')
                    ->formatStateUsing(fn (int $state, Plan $record): string => Money::format($state, $record->currency).' / '.$record->billing_period->value),
                TextColumn::make('trial_days')->label('Trial')->suffix(' days'),
                TextColumn::make('max_branches')->label('Branches')->placeholder('Unlimited'),
                TextColumn::make('max_staff')->label('Staff')->placeholder('Unlimited'),
                TextColumn::make('max_customers')->label('Customers')->placeholder('Unlimited'),
                TextColumn::make('subscriptions_count')->counts('subscriptions')->label('Companies'),
                IconColumn::make('is_active')->label('Offered')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('sort');
    }
}
