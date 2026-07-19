<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\ClientType;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
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
 * Individual = 7 steps (Client Type, Client Details, Identification, Other
 * Info, Employment/Business Details, Beneficiaries, Preview — "Branch Info"
 * is folded into the Client Type step since this app already scopes creation
 * to the current tenant/branch). Business = 4 steps (Client Type, Client
 * Details, Principal Contact, Preview).
 */
class CustomerWizardSteps
{
    /**
     * @return array<int, Step>
     */
    public static function steps(ClientType $clientType): array
    {
        $clientTypeStep = Step::make('Client Type')
            ->schema([
                Hidden::make('client_type')->default($clientType),
                Placeholder::make('client_type_display')
                    ->label('Client type')
                    ->content($clientType === ClientType::Business ? 'Business' : 'Individual'),
                Placeholder::make('branch')
                    ->label('Branch')
                    ->content(fn (): string => Filament::getTenant()?->name ?? '—'),
            ]);

        if ($clientType === ClientType::Business) {
            return [
                $clientTypeStep,

                Step::make('Client Details')
                    ->schema([
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
            $clientTypeStep,

            Step::make('Client Details')
                ->schema([
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
