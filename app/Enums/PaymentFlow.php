<?php

namespace App\Enums;

enum PaymentFlow: string
{
    /** POST /charge — PIN prompt on the customer's phone, polled/verified fast. */
    case ChargeApi = 'charge_api';

    /** Hosted Paystack checkout page, opened in-app or a new tab. */
    case Checkout = 'checkout';
}
