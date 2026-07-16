<?php

namespace App\Filament\SuperAdmin\Pages;

use App\Actions\License\GenerateLicenseKeyAction;
use App\Enums\LicenseSaleStatus;
use App\Models\DesktopLicenseSale;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mints a desktop activation key directly (no payment) — for support cases,
 * comps, or manually-collected payment outside the web checkout flow (see
 * plan Phase 6). Not scoped to any Company/branch, so this lives in the
 * SuperAdmin panel rather than the tenant admin panel.
 */
class GenerateLicenseKey extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.super-admin.pages.generate-license-key';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'Generate License Key';

    public ?DesktopLicenseSale $lastGenerated = null;

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasRole('super_admin') ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate Key')
                ->icon(Heroicon::OutlinedKey)
                ->schema([
                    TextInput::make('install_id')
                        ->label('Install ID')
                        ->required(),
                    TextInput::make('duration_days')
                        ->label('Duration (days)')
                        ->numeric()
                        ->required()
                        ->default(config('license.duration_days')),
                    TextInput::make('customer_name')
                        ->label('Customer name')
                        ->default('Manually issued'),
                    TextInput::make('customer_email')
                        ->label('Customer email')
                        ->email()
                        ->default(fn () => Filament::auth()->user()?->email),
                    TextInput::make('customer_phone')
                        ->label('Customer phone (optional)'),
                ])
                ->action(function (array $data): void {
                    $sale = DesktopLicenseSale::create([
                        'install_id' => $data['install_id'],
                        'customer_name' => $data['customer_name'] ?: 'Manually issued',
                        'customer_email' => $data['customer_email'] ?? 'n/a@example.com',
                        'customer_phone' => $data['customer_phone'] ?? null,
                        'duration_days' => (int) $data['duration_days'],
                        'amount' => 0,
                        'currency' => config('license.currency'),
                        'status' => LicenseSaleStatus::Paid,
                        'issued_by' => Filament::auth()->user()?->id,
                        'source' => 'admin_manual',
                    ]);

                    $this->lastGenerated = app(GenerateLicenseKeyAction::class)->execute($sale);

                    Notification::make()->title('License key generated')->success()->send();
                }),
        ];
    }

    /**
     * @return Builder<DesktopLicenseSale>
     */
    private function salesQuery(): Builder
    {
        return DesktopLicenseSale::query()->where('source', 'admin_manual');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->salesQuery())
            ->columns([
                TextColumn::make('install_id')->searchable(),
                TextColumn::make('customer_name')->label('Customer'),
                TextColumn::make('duration_days')->label('Days'),
                TextColumn::make('expires_at')->date(),
                TextColumn::make('status')->badge(),
                TextColumn::make('issuedBy.name')->label('Issued By'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No keys generated from this panel yet.');
    }
}
