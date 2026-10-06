<?php

namespace App\Filament\SuperAdmin\Pages;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoanProduct;
use App\Models\SavingsProduct;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * Every logged change across all tenants — the platform counterpart of the
 * admin panel's company-scoped ActivityLogReport, plus Company and Branch
 * changes (onboarding, suspension) made from this panel.
 */
class PlatformAuditLog extends Page implements HasTable
{
    use InteractsWithTable;

    /** Logged models that carry a company_id column. */
    private const COMPANY_SCOPED_SUBJECTS = [Branch::class, Customer::class, User::class, SavingsProduct::class, LoanProduct::class];

    /** @var array<string, string>|null Per-request cache; never a Livewire property. */
    protected ?array $companyNames = null;

    protected string $view = 'filament.pages.report-table';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Audit Log';

    protected static ?string $title = 'Platform Audit Log';

    public function table(Table $table): Table
    {
        return $table
            ->query(Activity::query()->with(['causer', 'subject']))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('company')
                    ->state(fn (Activity $record): string => match (true) {
                        $record->subject instanceof Company => $record->subject->name,
                        filled($record->subject?->company_id) => $this->companyNames()[$record->subject->company_id] ?? '—',
                        default => '—',
                    }),
                TextColumn::make('subject_type')
                    ->label('Record Type')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—'),
                TextColumn::make('record')
                    ->state(fn (Activity $record): string => match (true) {
                        $record->subject instanceof Customer => $record->subject->fullName(),
                        $record->subject !== null => (string) ($record->subject->name ?? '—'),
                        default => 'Deleted record',
                    }),
                TextColumn::make('event')->badge(),
                TextColumn::make('description')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('causer.name')->label('Changed By')->default('System'),
                TextColumn::make('changes')
                    ->label('Fields Changed')
                    ->state(fn (Activity $record): string => collect($record->properties->get('attributes', []))->keys()->join(', ') ?: '—')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('company')
                    ->options(fn (): array => Company::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $companyId): Builder => $query->where(fn (Builder $query) => $query
                            ->where(fn (Builder $query) => $query
                                ->where('subject_type', (new Company)->getMorphClass())
                                ->where('subject_id', $companyId))
                            ->orWhereHasMorph('subject', self::COMPANY_SCOPED_SUBJECTS, fn (Builder $subject) => $subject->where('company_id', $companyId))),
                    )),
                SelectFilter::make('subject_type')
                    ->label('Record Type')
                    ->options([
                        Company::class => 'Company',
                        Branch::class => 'Branch',
                        User::class => 'User',
                        Customer::class => 'Customer',
                        SavingsProduct::class => 'Savings Product',
                        LoanProduct::class => 'Loan Product',
                    ]),
                SelectFilter::make('event')->options([
                    'created' => 'Created',
                    'updated' => 'Updated',
                    'deleted' => 'Deleted',
                    'impersonation_started' => 'Impersonation started',
                    'impersonation_ended' => 'Impersonation ended',
                ]),
                SelectFilter::make('log_name')
                    ->label('Log')
                    ->options(fn (): array => Activity::query()->distinct()->orderBy('log_name')->pluck('log_name', 'log_name')->filter()->all()),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until')->afterOrEqual('from'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No changes have been logged yet.');
    }

    /**
     * @return array<string, string>
     */
    private function companyNames(): array
    {
        return $this->companyNames ??= Company::pluck('name', 'id')->all();
    }
}
