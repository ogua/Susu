<?php

namespace App\Filament\SuperAdmin\Resources\Companies\Tables;

use App\Filament\SuperAdmin\Resources\Companies\CompanyActions;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('activeCompanyAdmins as company_admins_count')
                ->with('subscription.plan'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('domain_alias')->searchable()->placeholder('—'),
                TextColumn::make('contact_email')->searchable(),
                TextColumn::make('contact_phone'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('subscription.plan.name')->label('Plan')->placeholder('—'),
                TextColumn::make('subscription.status')->label('Billing')->badge()->placeholder('—'),
                TextColumn::make('branches_count')->counts('branches')->label('Branches')
                    ->color(fn (int $state): ?string => $state === 0 ? 'danger' : null),
                TextColumn::make('company_admins_count')->label('Admins')
                    ->color(fn (int $state): ?string => $state === 0 ? 'danger' : null),
                TextColumn::make('users_count')->counts('users')->label('Users'),
                TextColumn::make('customers_count')->counts('customers')->label('Customers'),
                TextColumn::make('created_at')->label('Onboarded')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('archived')
                    ->label('Archived')
                    ->placeholder('Hide archived')
                    ->trueLabel('Archived only')
                    ->falseLabel('Hide archived')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('archived_at'),
                        blank: fn (Builder $query): Builder => $query->whereNull('archived_at'),
                    ),
                Filter::make('incomplete_onboarding')
                    ->label('Onboarding incomplete')
                    ->query(fn (Builder $query): Builder => $query->onboardingIncomplete()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make([
                    CompanyActions::addCompanyAdmin(),
                    CompanyActions::addBranch(),
                    CompanyActions::changePlan(),
                    CompanyActions::toggleActive(),
                ]),
            ])
            ->defaultSort('name');
    }
}
