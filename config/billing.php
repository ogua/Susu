<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment grace period
    |--------------------------------------------------------------------------
    |
    | Days after an invoice's period starts before it is due. The daily
    | billing:run marks the subscription past due once an invoice is overdue
    | and suspends the company suspend_after_days later; paying lifts both.
    |
    */

    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    /*
    | Days an invoice may stay overdue (subscription past due, admins see a
    | warning) before the company is suspended for non-payment.
    */
    'suspend_after_days' => (int) env('BILLING_SUSPEND_AFTER_DAYS', 7),

    'currency' => env('BILLING_CURRENCY', 'GHS'),

    /*
    | Paystack references for invoice payments. They share the license
    | prefix (SUSULIC-) so the shared payment gateway routes them to the
    | platform-billing webhook rather than the susu-payment one.
    */
    'reference_prefix' => 'SUSULIC-SUB-',

];
