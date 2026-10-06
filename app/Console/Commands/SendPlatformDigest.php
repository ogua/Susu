<?php

namespace App\Console\Commands;

use App\Actions\Platform\BuildPlatformDigestAction;
use App\Models\User;
use App\Notifications\PlatformDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/** Emails every active super admin the daily platform health digest. */
class SendPlatformDigest extends Command
{
    protected $signature = 'platform:digest';

    protected $description = 'Emails super admins the daily platform health digest (billing, sync, SMS, exports).';

    public function handle(BuildPlatformDigestAction $buildDigest): int
    {
        $digest = $buildDigest->execute();
        $superAdmins = User::role('super_admin')->where('is_active', true)->get();

        Notification::send($superAdmins, new PlatformDigest($digest));

        $this->info("Digest sent to {$superAdmins->count()} super admin(s); {$digest['alerts']} item(s) need attention.");

        return self::SUCCESS;
    }
}
