<?php

namespace App\Filament\SuperAdmin\Resources\Companies\Pages;

use App\Actions\Company\OnboardCompanyAction;
use App\Filament\SuperAdmin\Resources\Companies\CompanyResource;
use App\Filament\SuperAdmin\Resources\Companies\Schemas\BranchForm;
use App\Filament\SuperAdmin\Resources\Companies\Schemas\CompanyAdminForm;
use App\Filament\SuperAdmin\Resources\Companies\Schemas\CompanyForm;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Onboarding wizard: a company is only usable once it has a branch and a
 * company super admin to log in with, so all three are captured here and
 * created together by OnboardCompanyAction.
 */
class CreateCompany extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = CompanyResource::class;

    protected static ?string $title = 'Onboard Company';

    /**
     * @return array<int, Step>
     */
    protected function getSteps(): array
    {
        return [
            Step::make('Company')
                ->icon(Heroicon::OutlinedBuildingOffice2)
                ->columns(2)
                ->schema(CompanyForm::detailsFields()),

            Step::make('Branding & contact')
                ->icon(Heroicon::OutlinedSwatch)
                ->schema([
                    CompanyForm::brandingSection(),
                    CompanyForm::contactSection(),
                ]),

            Step::make('First branch')
                ->icon(Heroicon::OutlinedMapPin)
                ->description('Staff log in through a branch; more can be added later.')
                ->statePath('branch')
                ->columns(2)
                ->schema(BranchForm::fields()),

            Step::make('Company admin')
                ->icon(Heroicon::OutlinedUserCircle)
                ->description('The company super admin who signs in and adds the rest of the staff.')
                ->statePath('admin')
                ->columns(2)
                ->schema([
                    ...CompanyAdminForm::fields(),
                    Toggle::make('with_starter_products')
                        ->label('Add starter savings and loan products')
                        ->helperText('Five savings and five loan products the company can edit or switch off later.')
                        ->default(true)
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(OnboardCompanyAction::class)->execute(
            Arr::except($data, ['branch', 'admin']),
            $data['branch'],
            Arr::except($data['admin'], ['with_starter_products']),
            (bool) ($data['admin']['with_starter_products'] ?? true),
        );
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Company onboarded — its admin can now sign in';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
