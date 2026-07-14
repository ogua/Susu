<?php

namespace App\Filament\Resources\Groups\Schemas;

use App\Enums\LoanFrequency;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/** Members are added afterward via the relation manager; activation locks the rotation in. */
class GroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                TextInput::make('code')->required()->maxLength(20),
                TextInput::make('contribution_amount')
                    ->label('Contribution per round (GHS)')
                    ->numeric()
                    ->required()
                    ->formatStateUsing(fn (?int $state): ?float => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn (?float $state): int => (int) round(($state ?? 0) * 100))
                    ->disabledOn('edit'),
                Select::make('frequency')
                    ->options(LoanFrequency::class)
                    ->default(LoanFrequency::Monthly)
                    ->required()
                    ->disabledOn('edit'),
            ]);
    }
}
