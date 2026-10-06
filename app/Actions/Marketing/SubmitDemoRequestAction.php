<?php

namespace App\Actions\Marketing;

use App\Models\DemoRequest;
use App\Models\User;
use App\Notifications\DemoRequestReceived;
use Illuminate\Support\Facades\Notification;

/**
 * Records a demo request from the public website and tells the super
 * admins. A repeat from the same email within a few minutes (double
 * submit, impatient resend) returns the earlier request instead.
 */
class SubmitDemoRequestAction
{
    public const DUPLICATE_WINDOW_MINUTES = 10;

    /**
     * @param  array{name: string, organisation: string, email: string, phone: string, organisation_type: string, branches_count?: int|null, message?: string|null}  $data
     */
    public function execute(array $data, ?string $ipAddress = null): DemoRequest
    {
        $recent = DemoRequest::query()
            ->where('email', $data['email'])
            ->where('created_at', '>=', now()->subMinutes(self::DUPLICATE_WINDOW_MINUTES))
            ->latest()
            ->first();

        if ($recent !== null) {
            return $recent;
        }

        $demoRequest = DemoRequest::create([...$data, 'ip_address' => $ipAddress]);

        Notification::send(
            User::role('super_admin')->where('is_active', true)->get(),
            new DemoRequestReceived($demoRequest),
        );

        return $demoRequest;
    }
}
