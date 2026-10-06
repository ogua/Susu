<?php

namespace App\Notifications;

use App\Actions\Billing\InvoicePdfAction;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Support\Money;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Billing emails to a company: invoice issued, payment reminder, overdue
 * warning, and payment receipt — each with the invoice/receipt PDF — plus a
 * copy in the admin panel's notification bell for company admins.
 */
class SubscriptionInvoiceNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const ISSUED = 'issued';

    public const REMINDER = 'reminder';

    public const OVERDUE = 'overdue';

    public const PAID = 'paid';

    public function __construct(
        public readonly SubscriptionInvoice $invoice,
        public readonly string $type,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = $this->invoice->loadMissing(['company', 'plan']);
        $amount = Money::format($invoice->amount, $invoice->currency);
        $due = $invoice->due_at->format('d M Y');
        $suspendsOn = $invoice->due_at->copy()->addDays((int) config('billing.suspend_after_days'))->format('d M Y');

        $message = (new MailMessage)->subject($this->subject());

        $message = match ($this->type) {
            self::ISSUED => $message
                ->line("A new invoice for {$invoice->company->name} is ready: {$invoice->number} for {$amount}, covering {$invoice->period_start->format('d M Y')} – {$invoice->period_end->format('d M Y')}.")
                ->line("It is due on {$due}."),
            self::REMINDER => $message
                ->line("Invoice {$invoice->number} for {$amount} is due on {$due}.")
                ->line('Pay it before then to keep your account in good standing.'),
            self::OVERDUE => $message
                ->error()
                ->line("Invoice {$invoice->number} for {$amount} was due on {$due} and has not been paid.")
                ->line("Pay it by {$suspendsOn} to avoid your company's account being suspended."),
            default => $message
                ->success()
                ->line("Thank you — we received {$amount} for invoice {$invoice->number}.")
                ->line('Your receipt is attached.'),
        };

        $pdf = app(InvoicePdfAction::class);

        return $message
            ->action($this->type === self::PAID ? 'View subscription' : 'Pay now', url('/admin'))
            ->attachData($pdf->render($invoice), $pdf->filename($invoice), ['mime' => 'application/pdf']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->subject())
            ->body(Money::format($this->invoice->amount, $this->invoice->currency).' · due '.$this->invoice->due_at->format('d M Y'));

        $notification = match ($this->type) {
            self::OVERDUE => $notification->danger(),
            self::PAID => $notification->success(),
            default => $notification->warning(),
        };

        return $notification->getDatabaseMessage();
    }

    private function subject(): string
    {
        $number = $this->invoice->number;

        return match ($this->type) {
            self::ISSUED => "New invoice {$number}",
            self::REMINDER => "Payment reminder: invoice {$number}",
            self::OVERDUE => "Overdue: invoice {$number}",
            default => "Receipt for invoice {$number}",
        };
    }
}
