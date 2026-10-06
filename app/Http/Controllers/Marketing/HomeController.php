<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\AppPlatform;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Plan;
use App\Services\AppUpdatePolicy;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(Request $request, AppUpdatePolicy $updatePolicy): View|RedirectResponse
    {
        // Every company's custom domain points at this same app; on those
        // hosts "/" belongs to the company's own staff, not to our marketing.
        if ($this->isCompanyDomain($request->getHost())) {
            return redirect()->to(Filament::getPanel('admin')->getLoginUrl());
        }

        return view('marketing.home', [
            'plans' => Plan::query()->active()->get(),
            'licence' => [
                'price' => (int) config('license.price'),
                'currency' => (string) config('license.currency'),
                'duration_days' => (int) config('license.duration_days'),
            ],
            'downloads' => collect(AppPlatform::cases())
                ->mapWithKeys(fn (AppPlatform $platform): array => [$platform->value => $this->releasedDownloadUrl($updatePolicy, $platform)])
                ->all(),
        ]);
    }

    /**
     * The store/download link for a platform, but only once a release has
     * actually been published in App Releases — the config fallbacks alone
     * would advertise a listing that may not exist yet.
     */
    private function releasedDownloadUrl(AppUpdatePolicy $updatePolicy, AppPlatform $platform): ?string
    {
        $release = $updatePolicy->resolve($platform->value, null);

        return $release['latest_version'] !== '0.0.0' ? $release['download_url'] : null;
    }

    private function isCompanyDomain(string $host): bool
    {
        return Cache::remember(
            'marketing:company-host:'.strtolower($host),
            now()->addMinutes(10),
            fn (): bool => Company::query()->where('domain_alias', $host)->where('is_active', true)->exists(),
        );
    }
}
