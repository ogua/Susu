<?php

namespace App\Filament\SuperAdmin\Resources\SubscriptionInvoices\Tables;

use App\Enums\InvoiceStatus;
use App\Filament\SuperAdmin\Resources\SubscriptionInvoices\InvoiceActions;
use App\Models\SubscriptionInvoice;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionInvoicesTable
{
    public static function configure(Table $table, bool $showCompany = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['company', 'plan']))
            ->columns(array_values(array_filter([
                TextColumn::make('number')->searchable(),
                $showCompany ? TextColumn::make('company.name')->label('Company')->searchable() : null,
                TextColumn::make('plan.name')->label('Plan'),
                TextColumn::make('period_start')->label('Period')
                    ->formatStateUsing(fn (SubscriptionInvoice $record): string => $record->period_start->format('d M Y').' – '.$record->period_end->format('d M Y')),
                TextColumn::make('amount')
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state, SubscriptionInvoice $record): string => Money::format($state, $record->currency)),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (SubscriptionInvoice $record): string => $record->isOverdue() ? 'overdue' : $record->status->value)
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'overdue' => 'danger',
                        'void' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('due_at')->label('Due')->date()->sortable(),
                TextColumn::make('paid_at')->label('Paid')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('payment_method')->label('Method')->placeholder('—')->toggleable(),
            ])))
            ->filters([
                SelectFilter::make('status')->options(InvoiceStatus::class),
                Filter::make('overdue')
                    ->query(fn (Builder $query): Builder => $query->where('status', InvoiceStatus::Unpaid)->where('due_at', '<', now())),
            ])
            ->recordActions([
                Action::make('pdf')
                    ->label('PDF')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->url(fn (SubscriptionInvoice $record): string => route('billing.invoices.pdf', $record))
                    ->openUrlInNewTab(),
                InvoiceActions::recordPayment(),
                InvoiceActions::void(),
            ])
            ->defaultSort('due_at', 'desc');
    }
}
