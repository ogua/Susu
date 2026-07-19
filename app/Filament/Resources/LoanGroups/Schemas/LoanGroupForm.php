<?php

namespace App\Filament\Resources\LoanGroups\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/** Members are added afterward via the relation manager — no rotation/frequency concepts here. */
class LoanGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                TextInput::make('code')->required()->maxLength(20),
            ]);
    }
}
