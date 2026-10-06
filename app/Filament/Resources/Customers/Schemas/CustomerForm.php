<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\AccountStatus;
use App\Enums\ClientType;
use App\Models\Customer;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerForm
{
    /**
     * Only used for the edit form (create always goes through the
     * client_type-specific wizard — see CustomerWizardSteps). client_type
     * is therefore fixed once a customer exists and is determined here from
     * the record, not from a live Select: Group/Section ->visible() closures
     * that reference a sibling field were found (by manual browser testing)
     * to always evaluate against that field's static default rather than
     * its live/filled-in value in this Filament install, both inside
     * Wizards and in plain schemas — so the individual/business section set
     * is decided once, statically, when the schema is built.
     */
    public static function configure(Schema $schema): Schema
    {
        $record = $schema->getRecord();
        $clientType = ($record instanceof Customer && $record->client_type === ClientType::Business)
            ? ClientType::Business
            : ClientType::Individual;

        $components = [
            Section::make('Client type')
                ->columns(2)
                ->schema([
                    Select::make('client_type')
                        ->options(ClientType::class)
                        ->default($clientType)
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Client type is fixed at creation and cannot be changed here.'),
                    TextInput::make('branch.name')->label('Branch')->disabled()->dehydrated(false),
                ]),
        ];

        $components[] = $clientType === ClientType::Business
            ? Section::make('Client details')->columns(2)->schema(CustomerFormFields::businessClientDetailsFields())
            : Section::make('Client details')
                ->columns(2)
                ->schema(
                    CustomerFormFields::individualClientDetailsFields()
                );

        $components[] = Section::make('Status & photo')
            ->columns(2)
            ->schema([
                Select::make('status')
                    ->options(AccountStatus::class)
                    ->default(AccountStatus::Active)
                    ->required(),
                FileUpload::make('photo_path')
                    ->image()
                    ->disk('local')
                    ->directory('customers/photos')
                    ->visibility('private')
                    ->columnSpanFull(),
            ]);

        $components[] = Section::make('Address & location')
            ->columns(2)
            ->schema(CustomerFormFields::addressLocationFields());

        if ($clientType === ClientType::Business) {
            $components[] = Section::make('Principal contact')
                ->columns(2)
                ->schema(CustomerFormFields::principalContactFields());
        } else {
            // Identification, beneficiary and family rows are edited one at a
            // time in modals (relation managers below the form), not inline.
            $components[] = Section::make('Identification document')
                ->description('Add or edit ID records in the Identifications tab below.')
                ->schema([
                    FileUpload::make('id_photo_path')
                        ->label('Primary ID document photo')
                        ->image()
                        ->disk('local')
                        ->directory('customers/id-photos')
                        ->visibility('private')
                        ->columnSpanFull(),
                ]);

            $components[] = Section::make('Other info')
                ->columns(2)
                ->schema(CustomerFormFields::otherInfoFields(includeFamilyMembers: false));

            $components[] = Section::make('Employment/business details')
                ->columns(2)
                ->collapsible()
                ->schema(CustomerFormFields::employmentBusinessFields());
        }

        $components[] = Section::make('Next of kin')
            ->columns(3)
            ->schema([
                TextInput::make('next_of_kin_name')->label('Name'),
                TextInput::make('next_of_kin_phone')->label('Phone')->tel(),
                TextInput::make('next_of_kin_relationship')->label('Relationship'),
            ]);

        $components[] = Section::make('Preview & assignment')
            ->columns(2)
            ->schema(CustomerFormFields::previewAssignmentFields());

        $components[] = Grid::make(2)
            ->schema([
                TextInput::make('customer_code')->disabled()->dehydrated(false),
            ])
            ->visibleOn('edit');

        return $schema->components(
            Section::make('Customer form')->schema($components)
        );
    }
}
