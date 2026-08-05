<?php

namespace App\Filament\Resources\Staff\Schemas;

use App\Models\Branch;
use App\Policies\UserPolicy;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class StaffForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required(),
                        TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
                        TextInput::make('phone')->tel(),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create'),
                        Toggle::make('is_active')->default(true),
                    ]),

                Section::make('Role & branch access')
                    ->columns(2)
                    ->schema([
                        Select::make('role')
                            ->options(fn (): array => collect(app(UserPolicy::class)->assignableRoles(Filament::auth()->user()))
                                ->mapWithKeys(fn (string $role): array => [$role => Str::headline($role)])
                                ->all())
                            ->required(),
                        Select::make('branch_ids')
                            ->label('Branch access')
                            ->options(fn (): array => static::branchOptions())
                            ->multiple()
                            ->required()
                            ->preload()
                            ->helperText('Which branches this staff member can log into (tenant access).')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function branchOptions(): array
    {
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();

        $query = $user->hasRole('company_admin')
            ? Branch::where('company_id', $tenant->company_id)
            : Branch::whereIn('id', $user->branches()->pluck('branches.id'));

        return $query->pluck('name', 'id')->all();
    }
}
