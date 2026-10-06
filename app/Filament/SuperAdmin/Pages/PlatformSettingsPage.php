<?php

namespace App\Filament\SuperAdmin\Pages;

use App\Services\PlatformSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Operator-editable platform defaults (PlatformSettings). Blank fields fall
 * back to the .env/config value shown as the placeholder.
 *
 * @property-read Schema $form
 */
class PlatformSettingsPage extends Page
{
    protected string $view = 'filament.super-admin.platform-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Platform Settings';

    protected static ?string $title = 'Platform Settings';

    protected static ?string $slug = 'platform-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $current = app(PlatformSettings::class)->current();
        $current['license_price'] = number_format(((int) $current['license_price']) / 100, 2, '.', '');

        $this->form->fill($current);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Form::make([
                    Section::make('Desktop licenses')
                        ->columns(2)
                        ->schema([
                            TextInput::make('license_price')->label('Price (GHS)')->numeric()->minValue(0)->required(),
                            TextInput::make('license_duration_days')->label('Duration (days)')->integer()->minValue(1)->required(),
                        ]),
                    Section::make('Subscription billing')
                        ->columns(2)
                        ->schema([
                            TextInput::make('billing_grace_days')
                                ->label('Days to pay an invoice')
                                ->helperText('From the start of the billed period to the due date.')
                                ->integer()->minValue(0)->required(),
                            TextInput::make('billing_suspend_after_days')
                                ->label('Days overdue before suspension')
                                ->integer()->minValue(0)->required(),
                        ]),
                    Section::make('Data retention')
                        ->description('After a company is archived, its records are kept this long before personal data may be erased. Financial records are never erased.')
                        ->schema([
                            TextInput::make('data_retention_days')->label('Records kept after archiving (days)')->integer()->minValue(0)->required(),
                            TextInput::make('export_retention_days')
                                ->label('Data exports kept (days)')
                                ->helperText('Exports hold every customer\'s data and are deleted after this many days.')
                                ->integer()->minValue(1)->required(),
                        ]),
                    Section::make('Support contact')
                        ->description('Shown to company admins on their Subscription page.')
                        ->columns(2)
                        ->schema([
                            TextInput::make('support_email')->email(),
                            TextInput::make('support_phone')->tel(),
                        ]),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->label('Save settings')->submit('save'),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $data['license_price'] = (int) round(((float) $data['license_price']) * 100);

        app(PlatformSettings::class)->save($data);

        Notification::make()->title('Platform settings saved')->success()->send();
    }
}
