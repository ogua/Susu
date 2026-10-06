<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\ClientType;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Wizard\Step;

/**
 * Reproduces eBanQR's two client-onboarding flows. Filament Wizard steps are
 * NOT reactively conditional on live form state within the same wizard —
 * a step's (or a nested Group's) visible() closure referencing a field from
 * another step is evaluated once when the step list is built, not on every
 * render (confirmed by manual browser testing: changing the client-type
 * Select never updated which fields/steps showed). So which step SET to
 * build is decided up front from a `client_type` query parameter — set by
 * the "New Individual Client" / "New Business Client" actions on the
 * customer list page — rather than a Select the user changes mid-wizard.
 *
 * Individual = 6 steps (Client Details, Identification, Other Info,
 * Employment/Business Details, Beneficiaries, Preview). Business = 3 steps
 * (Client Details, Principal Contact, Preview). Creation is scoped to the
 * current tenant branch, so there is no branch step.
 */
class CustomerWizardSteps
{
    /**
     * @return array<int, Step>
     */
    public static function steps(ClientType $clientType): array
    {
        // No separate Client Type step: the type is fixed by the list-page
        // action, so it rides along as a hidden field on the first step.
        $clientTypeField = Hidden::make('client_type')->default($clientType);

        if ($clientType === ClientType::Business) {
            return [
                Step::make('Client Details')
                    ->schema([
                        $clientTypeField,
                        ...CustomerFormFields::businessClientDetailsFields(),
                        ...CustomerFormFields::addressLocationFields(),
                    ])
                    ->columns(2),

                Step::make('Principal Contact')
                    ->schema(CustomerFormFields::principalContactFields())
                    ->columns(2),

                Step::make('Preview')
                    ->schema(CustomerFormFields::previewAssignmentFields()),
            ];
        }

        return [
            Step::make('Client Details')
                ->schema([
                    $clientTypeField,
                    ...CustomerFormFields::individualClientDetailsFields(),
                    ...CustomerFormFields::addressLocationFields(),
                ])
                ->columns(2),

            Step::make('Identification')
                ->schema([CustomerFormFields::identificationRepeater()]),

            Step::make('Other Info')
                ->schema(CustomerFormFields::otherInfoFields())
                ->columns(2),

            Step::make('Employment/Business Details')
                ->schema(CustomerFormFields::employmentBusinessFields())
                ->columns(2),

            Step::make('Beneficiaries')
                ->schema([CustomerFormFields::beneficiariesRepeater()]),

            Step::make('Preview')
                ->schema(CustomerFormFields::previewAssignmentFields()),
        ];
    }
}
