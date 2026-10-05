<?php

namespace App\Filament\Resources\LoanGroups\Schemas;

use App\Actions\LoanGroups\BuildLoanGroupHistoryAction;
use App\Actions\LoanGroups\BuildLoanGroupSummaryAction;
use App\Models\LoanGroup;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Group overview (disbursed / paid / outstanding / overdue), group details and
 * the full activity history. Members and their loans are the relation-manager
 * tabs below.
 */
class LoanGroupInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Overview')
                ->columns(['default' => 2, 'md' => 4])
                ->columnSpanFull()
                ->schema([
                    self::money('total_disbursed', 'Total disbursed', 'primary'),
                    self::money('total_paid', 'Total paid', 'success'),
                    self::money('outstanding', 'Outstanding', 'warning'),
                    self::money('overdue', 'Overdue', 'danger'),
                    self::count('active_members', 'Active members'),
                    self::count('active_loans', 'Active loans'),
                    self::count('draft_loans', 'Loans awaiting deposit/activation'),
                    self::money('deposits_collected', 'Security deposits collected', 'gray'),
                ]),
            Section::make('Group details')
                ->columns(['default' => 2, 'md' => 4])
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('code'),
                    TextEntry::make('branch.name')->label('Branch')->badge(),
                    TextEntry::make('is_active')->label('Status')
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                        ->badge()
                        ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                    TextEntry::make('createdBy.name')->label('Created by')->placeholder('—'),
                    TextEntry::make('created_at')->label('Created on')->date(),
                ]),
            Section::make('Activity history')
                ->description('Every membership change, loan issue/disbursement/closure, deposit and repayment in this group.')
                ->collapsible()
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('history')
                        ->hiddenLabel()
                        ->state(fn (LoanGroup $record): array => app(BuildLoanGroupHistoryAction::class)->execute($record))
                        ->table([
                            TableColumn::make('When'),
                            TableColumn::make('Activity'),
                            TableColumn::make('Member'),
                            TableColumn::make('Amount'),
                            TableColumn::make('By'),
                        ])
                        ->schema([
                            TextEntry::make('at')->formatStateUsing(fn (?string $state): string => $state ? Carbon::parse($state)->format('d M Y H:i') : '—'),
                            TextEntry::make('description'),
                            TextEntry::make('member')->placeholder('—'),
                            TextEntry::make('amount')->formatStateUsing(fn ($state): string => $state === null ? '—' : Money::format((int) $state))->placeholder('—'),
                            TextEntry::make('by')->placeholder('—'),
                        ]),
                ]),
        ]);
    }

    private static function money(string $key, string $label, string $color): TextEntry
    {
        return TextEntry::make("summary_{$key}")
            ->label($label)
            ->state(fn (LoanGroup $record): string => Money::format(self::summary($record)[$key]))
            ->size('lg')
            ->weight('bold')
            ->color($color);
    }

    private static function count(string $key, string $label): TextEntry
    {
        return TextEntry::make("summary_{$key}")
            ->label($label)
            ->state(fn (LoanGroup $record): string => (string) self::summary($record)[$key])
            ->size('lg')
            ->weight('bold');
    }

    /**
     * @return array<string, int>
     */
    private static function summary(LoanGroup $record): array
    {
        // Request-scoped (array store): the eight overview entries share one computation.
        return Cache::store('array')->rememberForever(
            'loan-group-summary:'.$record->getKey(),
            fn (): array => app(BuildLoanGroupSummaryAction::class)->execute($record),
        );
    }
}
