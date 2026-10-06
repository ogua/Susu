<?php

namespace App\Listeners;

use App\Models\User;
use STS\FilamentImpersonate\Events\EnterImpersonation;
use STS\FilamentImpersonate\Events\LeaveImpersonation;

/**
 * Every time a super admin signs in as another user (and back out) lands in
 * the activity log (log name "impersonation"), shown on the platform Audit
 * Log — the operator can see and act as any tenant's staff, so that access
 * is always traceable.
 */
class LogImpersonation
{
    public function handleEnter(EnterImpersonation $event): void
    {
        $this->log($event->impersonator, $event->impersonated, 'started');
    }

    public function handleLeave(LeaveImpersonation $event): void
    {
        $this->log($event->impersonator, $event->impersonated, 'ended');
    }

    private function log(mixed $impersonator, mixed $impersonated, string $event): void
    {
        if (! $impersonator instanceof User || ! $impersonated instanceof User) {
            return;
        }

        activity('impersonation')
            ->causedBy($impersonator)
            ->performedOn($impersonated)
            ->event("impersonation_{$event}")
            ->withProperties([
                'company_id' => $impersonated->company_id,
                'ip' => request()->ip(),
            ])
            ->log("{$impersonator->name} {$event} impersonating {$impersonated->name}");
    }
}
