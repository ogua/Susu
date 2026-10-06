<?php

namespace App\Filament\SuperAdmin\Resources\Plans\Schemas;

use App\Enums\BillingPeriod;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The price is entered in cedis and stored in pesewas (minor units), like
 * every amount in the app. Leave a limit blank for unlimited.
 */
class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Plan')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(100),
                        TextInput::make('code')->required()->alphaDash()->maxLength(40)->unique(ignoreRecord: true),
                        TextInput::make('price_amount')
                            ->label('Price (GHS)')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->default(0)
                            ->formatStateUsing(fn ($state): ?string => $state === null ? null : number_format(((int) $state) / 100, 2, '.', ''))
                            ->dehydrateStateUsing(fn ($state): int => (int) round(((float) $state) * 100)),
                        Select::make('billing_period')
                            ->options(BillingPeriod::class)
                            ->default(BillingPeriod::Monthly)
                            ->required(),
                        TextInput::make('trial_days')->label('Free trial (days)')->numeric()->minValue(0)->default(0)->required(),
                        TextInput::make('sort')->numeric()->default(0)->helperText('Lower shows first.'),
                        Textarea::make('description')->columnSpanFull(),
                        Toggle::make('is_active')->label('Offered')->default(true),
                    ]),

                Section::make('Limits')
                    ->description('Blank means unlimited. Checked whenever a branch, staff member or customer is added — on the web, the API and offline sync.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('max_branches')->numeric()->minValue(1),
                        TextInput::make('max_staff')->numeric()->minValue(1),
                        TextInput::make('max_customers')->numeric()->minValue(1),
                    ]),
            ]);
    }
}
