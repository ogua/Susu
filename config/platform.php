<?php

return [

    /*
    | How tenants reach the SusuApp team — shown on the Billing page and in
    | suspension messages. Editable by super admins (Platform Settings).
    */
    'support_email' => env('PLATFORM_SUPPORT_EMAIL'),

    'support_phone' => env('PLATFORM_SUPPORT_PHONE'),

    /*
    | Days an archived company's records are kept before its customers' and
    | staff's personal data may be erased (financial records are always kept).
    | Six years by default, in line with financial record-keeping rules.
    */
    'data_retention_days' => (int) env('PLATFORM_DATA_RETENTION_DAYS', 2190),

    /*
    | Days a company data export (zip of every record) is kept before
    | exports:prune deletes it.
    */
    'export_retention_days' => (int) env('PLATFORM_EXPORT_RETENTION_DAYS', 30),

];
