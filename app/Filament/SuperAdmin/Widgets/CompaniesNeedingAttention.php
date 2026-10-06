<?php

namespace App\Filament\SuperAdmin\Widgets;

use App\Filament\SuperAdmin\Resources\Companies\CompanyActions;
use App\Filament\SuperAdmin\Resources\Companies\CompanyResource;
use App\Models\Company;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Active companies the operator should follow up: onboarding not finished
 * (no branch / no active company admin), or no transaction in the last
 * DORMANT_AFTER_DAYS days.
 */
class CompaniesNeedingAttention extends TableWidget
{
    public const DORMANT_AFTER_DAYS = 30;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Companies Needing Attention';

    public function table(Table $table): Table
    {
        $dormantSince = now()->subDays(self::DORMANT_AFTER_DAYS);

        return $table
            ->query(fn (): Builder => Company::query()
                ->where('is_active', true)
                ->where(fn (Builder $query) => $query
                    ->onboardingIncomplete()
                    ->orWhereDoesntHave('journalEntries', fn (Builder $entries) => $entries->where('recorded_at', '>=', $dormantSince)))
                ->withCount(['branches', 'activeCompanyAdmins as company_admins_count'])
                ->withMax('journalEntries as last_activity_at', 'recorded_at'))
            ->columns([
                TextColumn::make('name')->label('Company')->searchable(),
                TextColumn::make('issue')
                    ->badge()
                    ->state(fn (Company $record): string => match (true) {
                        $record->branches_count === 0 => 'No branch',
                        $record->company_admins_count === 0 => 'No active company admin',
                        default => 'No activity in '.self::DORMANT_AFTER_DAYS.' days',
                    })
                    ->color(fn (Company $record): string => $record->branches_count === 0 || $record->company_admins_count === 0 ? 'danger' : 'warning'),
                TextColumn::make('last_activity_at')->label('Last transaction')->dateTime()->placeholder('Never'),
                TextColumn::make('contact_phone')->label('Contact'),
                TextColumn::make('created_at')->label('Onboarded')->date(),
            ])
            ->recordUrl(fn (Company $record): string => CompanyResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                CompanyActions::addBranch()->visible(fn (Company $record): bool => $record->branches_count === 0),
                CompanyActions::addCompanyAdmin()->visible(fn (Company $record): bool => $record->branches_count > 0 && $record->company_admins_count === 0),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([5, 10, 25])
            ->emptyStateHeading('Every active company is set up and transacting.');
    }
}
