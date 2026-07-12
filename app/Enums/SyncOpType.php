<?php

namespace App\Enums;

enum SyncOpType: string
{
    case RegisterCustomer = 'customer.register';
    case OpenSavingsAccount = 'account.open';
    case RecordCollection = 'collection.record';
    case SubmitDailySummary = 'summary.submit';
    case RecordLocationPings = 'locations.record';

    /**
     * Op types each role may push through /sync/batch.
     *
     * @return array<int, self>
     */
    public static function allowedFor(string $role): array
    {
        return match ($role) {
            'field_agent' => [
                self::RegisterCustomer,
                self::OpenSavingsAccount,
                self::RecordCollection,
                self::SubmitDailySummary,
                self::RecordLocationPings,
            ],
            'branch_manager', 'company_admin' => self::cases(),
            default => [],
        };
    }
}
