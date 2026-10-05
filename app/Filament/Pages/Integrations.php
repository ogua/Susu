<?php

namespace App\Filament\Pages;

use App\Actions\Company\UpdateCompanyIntegrationSettingsAction;
use App\Models\Company;
use App\Services\Sms\SmsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Company admin's SMS (Arkesel) and Paystack connection — the web face of
 * UpdateCompanyIntegrationSettingsAction (also PUT /api/v1/company/integrations).
 *
 * @property-read Schema $form
 */
class Integrations extends Page
{
    protected string $view = 'filament.pages.integrations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?string $navigationLabel = 'SMS & Payments';

    protected static ?string $title = 'SMS & Payments';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasRole('company_admin') ?? false;
    }

    public function mount(): void
    {
        $company = $this->company();
        $sms = app(SmsService::class)->settingsFor($company);

        $this->form->fill([
            'sms_provider' => $sms->provider,
            'sms_sender_id' => $sms->sender_id,
            'sms_notifications_enabled' => $sms->notifications_enabled,
            'sms_quiet_hours_start' => substr((string) $sms->quiet_hours_start, 0, 5),
            'sms_quiet_hours_end' => substr((string) $sms->quiet_hours_end, 0, 5),
            'paystack_public_key' => $company->paymentSetting?->paystack_public_key,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $company = $this->company();
        $smsKeySet = filled($company->smsSetting?->api_key);
        $paystackConnected = (bool) $company->paymentSetting?->hasOwnPaystackAccount();

        return $schema
            ->components([
                Form::make([
                    Section::make('SMS notifications')
                        ->description('Customers get an SMS for every deposit, withdrawal, loan disbursement and repayment.')
                        ->schema([
                            Grid::make(2)->schema([
                                Select::make('sms_provider')
                                    ->label('Provider')
                                    ->options(['log' => 'Off (log only)', 'arkesel' => 'Arkesel'])
                                    ->required()
                                    ->live(),
                                TextInput::make('sms_sender_id')
                                    ->label('Sender ID')
                                    ->helperText('Your registered sender name, max 11 characters.')
                                    ->maxLength(11)
                                    ->required(fn (Get $get): bool => $get('sms_provider') === 'arkesel'),
                                TextInput::make('sms_api_key')
                                    ->label('Arkesel API key')
                                    ->password()
                                    ->revealable()
                                    ->placeholder($smsKeySet ? 'Saved. Leave blank to keep it.' : null)
                                    ->required(fn (Get $get): bool => $get('sms_provider') === 'arkesel' && ! $smsKeySet)
                                    ->visible(fn (Get $get): bool => $get('sms_provider') === 'arkesel')
                                    ->columnSpanFull(),
                                Toggle::make('sms_notifications_enabled')
                                    ->label('Send customer notifications')
                                    ->columnSpanFull(),
                                TimePicker::make('sms_quiet_hours_start')
                                    ->label('Quiet hours start')
                                    ->seconds(false)
                                    ->required(),
                                TimePicker::make('sms_quiet_hours_end')
                                    ->label('Quiet hours end')
                                    ->seconds(false)
                                    ->helperText('Messages due in quiet hours are held until they end.')
                                    ->required(),
                            ]),
                        ])
                        ->columnSpanFull(),
                    Section::make('Paystack (mobile money)')
                        ->description($paystackConnected
                            ? 'Connected: mobile money collections settle into your own Paystack account.'
                            : 'Not connected: mobile money uses the platform Paystack account. Add your keys to collect into your own.')
                        ->schema([
                            TextInput::make('paystack_public_key')
                                ->label('Public key')
                                ->startsWith(['pk_'])
                                ->maxLength(255),
                            TextInput::make('paystack_secret_key')
                                ->label('Secret key')
                                ->password()
                                ->revealable()
                                ->startsWith(['sk_'])
                                ->maxLength(255)
                                ->placeholder($paystackConnected ? 'Saved. Leave blank to keep it.' : null),
                            TextEntry::make('webhook_url')
                                ->label('Webhook URL (paste into Paystack: Settings > API Keys & Webhooks)')
                                ->state(route('webhooks.paystack.company', $company))
                                ->copyable(),
                        ])
                        ->columnSpanFull(),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        app(UpdateCompanyIntegrationSettingsAction::class)->execute($this->company(), $this->form->getState());

        $this->data['sms_api_key'] = null;
        $this->data['paystack_secret_key'] = null;

        Notification::make()->title('Settings saved')->success()->send();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTestSms')
                ->label('Send test SMS')
                ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                ->schema([
                    TextInput::make('phone')
                        ->tel()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $company = $this->company();

                    try {
                        app(SmsService::class)->send($company, $data['phone'], "Test message from {$company->name}. Your SMS notifications are working.");
                        Notification::make()->title('Test SMS sent')->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Test SMS failed')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('disconnectPaystack')
                ->label('Disconnect Paystack')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('New mobile money payments will go back to the platform Paystack account. Payments already started keep using your account.')
                ->visible(fn (): bool => (bool) $this->company()->paymentSetting?->hasOwnPaystackAccount())
                ->action(function (): void {
                    app(UpdateCompanyIntegrationSettingsAction::class)->execute($this->company(), ['disconnect_paystack' => true]);
                    $this->data['paystack_public_key'] = null;
                    Notification::make()->title('Paystack disconnected')->success()->send();
                }),
        ];
    }

    private function company(): Company
    {
        return Filament::auth()->user()->company->load(['smsSetting', 'paymentSetting']);
    }
}
