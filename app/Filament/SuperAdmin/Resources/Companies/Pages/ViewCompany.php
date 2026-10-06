<?php

namespace App\Filament\SuperAdmin\Resources\Companies\Pages;

use App\Actions\Reports\BuildCompanyUsageReportAction;
use App\Filament\SuperAdmin\Resources\Companies\CompanyActions;
use App\Filament\SuperAdmin\Resources\Companies\CompanyResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewCompany extends ViewRecord
{
    protected static string $resource = CompanyResource::class;

    /**
     * Loads the record with the usage-report aggregates (this month) so the
     * infolist reads the same figures as the Company Usage report.
     */
    protected function resolveRecord(int|string $key): Model
    {
        return app(BuildCompanyUsageReportAction::class)->query()->whereKey($key)->firstOrFail();
    }

    protected function getHeaderActions(): array
    {
        // Reload after adding so the onboarding figures and relation tables pick up the new rows.
        $reload = fn () => $this->redirect(static::getUrl(['record' => $this->getRecord()]), navigate: true);

        return [
            CompanyActions::addCompanyAdmin()->after($reload),
            CompanyActions::addBranch()->after($reload),
            EditAction::make(),
            ActionGroup::make([
                CompanyActions::addStarterProducts()->after($reload),
                CompanyActions::toggleActive(),
            ]),
        ];
    }
}
