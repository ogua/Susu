<?php

namespace App\Enums;

enum AnnouncementAudience: string
{
    /** Every tenant staff member (company admins, branch managers, field agents). */
    case AllStaff = 'all_staff';
    case CompanyAdmins = 'company_admins';

    /**
     * Roles that see an announcement for this audience.
     *
     * @return list<string>
     */
    public function roles(): array
    {
        return match ($this) {
            self::AllStaff => ['company_admin', 'branch_manager', 'field_agent'],
            self::CompanyAdmins => ['company_admin'],
        };
    }
}
