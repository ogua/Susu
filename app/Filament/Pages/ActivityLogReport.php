<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use App\Models\LoanProduct;
use App\Models\SavingsProduct;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Read-only audit trail (AD-16) for master-data models that opted into
 * LogsActivity: Customer, User, SavingsProduct, LoanProduct. Company-scoped
 * for company_admin; branch_manager additionally loses visibility into
 * customer/user changes outside their own branch (products stay
 * company-wide since they carry no branch_id).
 */
class ActivityLogReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.activity-log-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Activity Log';

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasRole(['company_admin', 'branch_manager']) ?? false;
    }

    /**
     * @return Builder<Activity>
     */
    private function activitiesQuery(): Builder
    {
        $tenant = Filament::getTenant();
        $companyId = $tenant?->company_id;
        $isCompanyAdmin = Filament::auth()->user()?->hasRole('company_admin') ?? false;

        return Activity::query()
            ->whereHasMorph(
                'subject',
                [Customer::class, User::class, SavingsProduct::class, LoanProduct::class],
                function (Builder $query, string $type) use ($companyId, $tenant, $isCompanyAdmin): void {
                    $query->where('company_id', $companyId);

                    if (! $isCompanyAdmin && in_array($type, [Customer::class, User::class], true)) {
                        $query->where('branch_id', $tenant?->id);
                    }
                }
            )
            ->with('causer');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->activitiesQuery())
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('subject_type')
                    ->label('Record Type')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—'),
                TextColumn::make('event')->badge(),
                TextColumn::make('causer.name')->label('Changed By')->default('System'),
                TextColumn::make('changes')
                    ->label('Fields Changed')
                    ->state(fn (Activity $record): string => collect($record->properties->get('attributes', []))->keys()->join(', ') ?: '—')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('event')->options([
                    'created' => 'Created',
                    'updated' => 'Updated',
                    'deleted' => 'Deleted',
                ]),
                SelectFilter::make('subject_type')
                    ->label('Record Type')
                    ->options([
                        Customer::class => 'Customer',
                        User::class => 'User',
                        SavingsProduct::class => 'Savings Product',
                        LoanProduct::class => 'Loan Product',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No changes have been logged yet.');
    }
}
