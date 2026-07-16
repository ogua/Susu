<?php

namespace App\Filament\SuperAdmin\Resources\DesktopLicenseSales\Pages;

use App\Actions\License\GenerateLicenseKeyAction;
use App\Enums\LicenseSaleStatus;
use App\Filament\SuperAdmin\Resources\DesktopLicenseSales\DesktopLicenseSaleResource;
use App\Models\DesktopLicenseSale;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListDesktopLicenseSales extends ListRecords
{
    protected static string $resource = DesktopLicenseSaleResource::class;

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

                    app(GenerateLicenseKeyAction::class)->execute($sale);

                    Notification::make()->title('License key generated')->success()->send();
                }),
        ];
    }
}
