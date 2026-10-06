<?php

namespace App\Filament\Pages;

use App\Actions\Billing\InvoiceCheckoutAction;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Billing\PlanLimits;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * The company admin's view of their SusuApp subscription: plan, usage
 * against its limits, and invoices with Paystack "Pay now". Same data as
 * GET /api/v1/company/subscription.
 */
class Billing extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.billing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Subscription';

    protected static ?string $title = 'Subscription & Billing';

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasRole('company_admin') ?? false;
    }

    /**
     * The warning shown across the admin panel to a company admin whose
     * oldest unpaid invoice is overdue, with the date suspension kicks in.
     */
    public static function overdueNotice(): ?string
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || $user->company_id === null || ! $user->hasRole('company_admin')) {
            return null;
        }

        $invoice = SubscriptionInvoice::query()
            ->where('company_id', $user->company_id)
            ->where('status', InvoiceStatus::Unpaid)
            ->where('due_at', '<', now())
            ->orderBy('due_at')
            ->first();

        if ($invoice === null) {
            return null;
        }

        $suspendsOn = $invoice->due_at->copy()->addDays((int) config('billing.suspend_after_days'));

        return "Invoice {$invoice->number} (".Money::format($invoice->amount, $invoice->currency).') is overdue. '
            ."Pay it by {$suspendsOn->format('d M Y')} to avoid your company's account being suspended.";
    }

    public function company(): Company
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user->company;
    }

    public function subscription(): ?CompanySubscription
    {
        return $this->company()->subscription()->with('plan')->first();
    }

    /**
     * @return array<string, array{used: int, limit: ?int}>
     */
    public function usage(): array
    {
        return app(PlanLimits::class)->summary($this->company());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->company()->subscriptionInvoices()->with('plan')->getQuery())
            ->columns([
                TextColumn::make('number'),
                TextColumn::make('plan.name')->label('Plan'),
                TextColumn::make('period_start')->label('Period')
                    ->formatStateUsing(fn (SubscriptionInvoice $record): string => $record->period_start->format('d M Y').' – '.$record->period_end->format('d M Y')),
                TextColumn::make('amount')->alignEnd()
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
                TextColumn::make('due_at')->label('Due')->date(),
                TextColumn::make('paid_at')->label('Paid')->date()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('pdf')
                    ->label(fn (SubscriptionInvoice $record): string => $record->status === InvoiceStatus::Paid ? 'Receipt' : 'Invoice')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->url(fn (SubscriptionInvoice $record): string => route('billing.invoices.pdf', $record))
                    ->openUrlInNewTab(),
                Action::make('pay')
                    ->label('Pay now')
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->color('success')
                    ->visible(fn (SubscriptionInvoice $record): bool => $record->status === InvoiceStatus::Unpaid)
                    ->action(function (SubscriptionInvoice $record): void {
                        /** @var User $user */
                        $user = Filament::auth()->user();
                        $url = app(InvoiceCheckoutAction::class)->start($record, $user, route('billing.callback'));

                        $this->redirect($url);
                    }),
            ])
            ->defaultSort('period_start', 'desc')
            ->emptyStateHeading('No invoices yet.');
    }
}
