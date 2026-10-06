<?php

namespace App\Mail;

use App\Models\DesktopLicenseSale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LicenseKeyIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly DesktopLicenseSale $sale) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your OguaFinance Desktop activation key',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.license-key-issued',
        );
    }
}
