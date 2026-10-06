<?php

namespace App\Filament\SuperAdmin\Resources\Companies\Schemas;

use App\Models\Branch;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/**
 * Branch fields shared by the onboarding wizard's "First branch" step and
 * the company's Branches relation manager.
 */
class BranchForm
{
    /**
     * @param  (callable(): ?string)|null  $companyId  Scopes the slug uniqueness check; null for a company not yet created.
     * @return array<int, mixed>
     */
    public static function fields(?callable $companyId = null): array
    {
        $slug = TextInput::make('slug')
            ->required()
            ->alphaDash();

        if ($companyId !== null) {
            // The record is a Branch when editing one, but the Company when the
            // form runs inside a company-level "Add branch" action.
            $slug->unique(
                table: 'branches',
                ignorable: fn (?Model $record): ?Branch => $record instanceof Branch ? $record : null,
                ignoreRecord: false,
                modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('company_id', $companyId()),
            );
        }

        return [
            TextInput::make('name')
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                    if (blank($get('slug'))) {
                        $set('slug', Str::slug($state ?? ''));
                    }
                }),
            $slug,
            TextInput::make('code')
                ->maxLength(10)
                ->helperText('Short code used on ledger accounts and receipts, e.g. "MB".'),
            TextInput::make('contact_phone')->tel(),
            TextInput::make('contact_email')->email(),
            Textarea::make('address')->columnSpanFull(),
        ];
    }
}
