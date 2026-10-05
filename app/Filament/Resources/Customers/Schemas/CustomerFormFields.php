<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\BusinessIncomeLevel;
use App\Enums\BusinessSector;
use App\Enums\BusinessStructure;
use App\Enums\ClientType;
use App\Enums\IdentificationType;
use App\Enums\MaritalStatus;
use App\Enums\ResidencyStatus;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

/**
 * Static field-group builders reused by both the create wizard
 * (CustomerWizardSteps) and the flat edit form (CustomerForm), so the field
 * definitions for a given eBanQR onboarding section only exist once.
 */
class CustomerFormFields
{
    /**
     * @return array<int, Component>
     */
    public static function clientTypeFields(): array
    {
        return [
            Select::make('client_type')
                ->options(ClientType::class)
                ->default(ClientType::Individual)
                ->live()
                ->required(),
            Placeholder::make('branch')
                ->label('Branch')
                ->content(fn (): string => Filament::getTenant()?->name ?? '—'),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function individualClientDetailsFields(): array
    {
        return [
            TextInput::make('first_name')->required(),
            TextInput::make('last_name')->required(),
            Select::make('gender')->options([
                'male' => 'Male',
                'female' => 'Female',
            ]),
            TextInput::make('phone')->tel()->required(),
            TextInput::make('external_id')->label('External ID'),
            DatePicker::make('date_of_birth')->maxDate(now()->subYears(18)),
            TextInput::make('place_of_birth'),
            TextInput::make('nationality'),
            TextInput::make('email')->email(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function businessClientDetailsFields(): array
    {
        return [
            TextInput::make('business_name')->label('Name')->required(),
            Select::make('business_structure')
                ->label('Business constitution')
                ->options(BusinessStructure::class)
                ->required(),
            Select::make('business_line')
                ->label('Main business line')
                ->options(BusinessSector::class),
            TextInput::make('phone')->tel()->required(),
            TextInput::make('external_id')->label('External ID'),
            DatePicker::make('business_start_date')->label('Business start date')->required(),
            TextInput::make('business_tin')->label('Business TIN'),
            Select::make('business_income_level')->label('Business income level')->options(BusinessIncomeLevel::class),
            TextInput::make('email')->email(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function addressLocationFields(): array
    {
        return [
            Textarea::make('address')->label('Street address'),
            TextInput::make('city_town')->label('City/Town'),
            TextInput::make('state_region')->label('State/Region'),
            TextInput::make('country')->default('Ghana'),
            TextInput::make('digital_address')->label('Digital address'),
            TextInput::make('latitude')->numeric(),
            TextInput::make('longitude')->numeric(),
        ];
    }

    public static function identificationRepeater(): Repeater
    {
        return Repeater::make('identifications')
            ->label('Identification')
            ->schema(static::identificationFields())
            ->columns(3)
            ->itemLabel(fn (array $state): string => static::itemLabel($state, ['id_number'], 'New identification'))
            ->collapsible()
            ->addActionLabel('Add Identification')
            ->defaultItems(0)
            ->reorderable(false)
            ->columnSpanFull();
    }

    /**
     * Shared by the create-wizard repeater and the modal form of the
     * Identifications relation manager on the view/edit pages.
     *
     * @return array<int, Component>
     */
    public static function identificationFields(): array
    {
        return [
            Hidden::make('id'),
            Select::make('id_type')->label('ID type')->options(IdentificationType::class)->required(),
            TextInput::make('id_number')->label('Unique ID number')->required(),
            DatePicker::make('issue_date')->required(),
            DatePicker::make('expiry_date')->afterOrEqual('issue_date'),
            TextInput::make('description')->columnSpan(2),
            Toggle::make('is_primary')->label('Primary ID')->inline(false),
        ];
    }

    /**
     * Collapsed repeater cards show the row's key values instead of a bare
     * index, so a long list stays scannable.
     *
     * @param  array<string, mixed>  $state
     * @param  list<string>  $keys
     */
    private static function itemLabel(array $state, array $keys, string $fallback): string
    {
        $parts = array_filter(array_map(fn (string $key): string => trim((string) ($state[$key] ?? '')), $keys));

        return $parts === [] ? $fallback : implode(' — ', $parts);
    }

    /**
     * The edit form passes false: on an existing customer, family members are
     * managed one at a time in a modal (FamilyMembersRelationManager).
     *
     * @return array<int, Component>
     */
    public static function otherInfoFields(bool $includeFamilyMembers = true): array
    {
        return [
            Select::make('marital_status')->options(MaritalStatus::class),
            TextInput::make('spouse_name')->label('Spouse name'),
            DatePicker::make('spouse_date_of_birth')->label('Spouse date of birth'),
            TextInput::make('spouse_occupation')->label('Spouse occupation'),
            Toggle::make('has_past_loan')->label('Past loan by client or spouse?'),
            TextInput::make('past_loan_institution')->label('Institution of past loan'),
            TextInput::make('spouse_employer_name')->label('Spouse employer name'),
            Textarea::make('spouse_employer_address')->label('Spouse employer address'),
            TextInput::make('spouse_employer_town')->label('Spouse employer town'),
            TextInput::make('spouse_employer_county')->label('Spouse employer county'),
            TextInput::make('spouse_employer_region')->label('Spouse employer state/region'),
            Section::make('Family & religion')
                ->collapsed()
                ->schema(array_values(array_filter([
                    TextInput::make('religion')
                        ->helperText('Best-guess field — the source recording never expanded this section.'),
                    $includeFamilyMembers ? static::familyMembersRepeater() : null,
                ])))
                ->columnSpanFull(),
        ];
    }

    public static function familyMembersRepeater(): Repeater
    {
        return Repeater::make('family_members')
            ->label('Family details')
            ->schema(static::familyMemberFields())
            ->columns(2)
            ->itemLabel(fn (array $state): string => static::itemLabel($state, ['name', 'relationship'], 'New family member'))
            ->collapsible()
            ->addActionLabel('Add Family Member')
            ->defaultItems(0)
            ->reorderable(false)
            ->columnSpanFull();
    }

    /**
     * @return array<int, Component>
     */
    public static function familyMemberFields(): array
    {
        return [
            Hidden::make('id'),
            TextInput::make('name')->required(),
            TextInput::make('relationship'),
            TextInput::make('contact_phone')->tel(),
            TextInput::make('occupation'),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function employmentBusinessFields(): array
    {
        return [
            TextInput::make('business_name')->label('Business name'),
            TextInput::make('business_line')->label('Nature of business'),
            Select::make('business_structure')->label('Business structure')->options(BusinessStructure::class),
            DatePicker::make('business_start_date')->label('Business start date'),
            TextInput::make('business_phone')->label('Business phone number')->tel(),
            Select::make('business_income_level')->label('Business income level')->options(BusinessIncomeLevel::class),
            Textarea::make('business_address')->label('Business address'),
            TextInput::make('business_town')->label('Business town'),
            TextInput::make('business_county')->label('Business county'),
            TextInput::make('business_region')->label('Business state/region'),
            TextInput::make('business_latitude')->label('Business latitude')->numeric(),
            TextInput::make('business_longitude')->label('Business longitude')->numeric(),
        ];
    }

    public static function beneficiariesRepeater(): Repeater
    {
        return Repeater::make('beneficiaries')
            ->schema(static::beneficiaryFields())
            ->columns(3)
            ->itemLabel(fn (array $state): string => static::itemLabel($state, ['name', 'relationship'], 'New beneficiary'))
            ->collapsible()
            ->addActionLabel('Add Beneficiary')
            ->defaultItems(0)
            ->reorderable(false)
            ->columnSpanFull();
    }

    /**
     * @return array<int, Component>
     */
    public static function beneficiaryFields(): array
    {
        return [
            Hidden::make('id'),
            TextInput::make('name')->label('Beneficiary name')->required(),
            TextInput::make('relationship')->label('Relationship to client'),
            TextInput::make('amount_of_legacy')
                ->label('Amount of legacy (GHS)')
                ->numeric()
                ->required()
                ->formatStateUsing(fn (?int $state): ?float => $state === null ? null : $state / 100)
                ->dehydrateStateUsing(fn (?float $state): int => (int) round(($state ?? 0) * 100)),
            TextInput::make('phone')->tel(),
            TextInput::make('town'),
            TextInput::make('county'),
            TextInput::make('state_region')->label('State/Region'),
            Textarea::make('address')->columnSpan(2),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function principalContactFields(): array
    {
        return [
            TextInput::make('first_name')->required(),
            TextInput::make('last_name')->required(),
            TextInput::make('other_names'),
            Select::make('gender')->options([
                'male' => 'Male',
                'female' => 'Female',
            ]),
            DatePicker::make('date_of_birth')->label('Date of birth'),
            TextInput::make('occupation'),
            TextInput::make('job_title'),
            TextInput::make('tin')->label('TIN'),
            TextInput::make('nationality'),
            TextInput::make('country_of_residence')->label('Country of residence'),
            TextInput::make('residence_permit')->label('Residence permit'),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function previewAssignmentFields(): array
    {
        return [
            Select::make('assigned_agent_id')
                ->label('Assign loan officer')
                ->relationship(
                    name: 'assignedAgent',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn ($query) => $query
                        ->whereHas('roles', fn ($roles) => $roles->where('name', 'field_agent'))
                        ->where('branch_id', Filament::getTenant()?->id),
                )
                ->searchable()
                ->preload(),
            Select::make('residency_status')->options(ResidencyStatus::class),
            Placeholder::make('submission_date')
                ->label('Submission date')
                ->content(fn (): string => now()->toFormattedDateString()),
        ];
    }
}
