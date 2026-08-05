<?php

namespace App\Filament\Resources\PaymentIntents;

use App\Filament\Resources\PaymentIntents\Pages\ListPaymentIntents;
use App\Filament\Resources\PaymentIntents\Tables\PaymentIntentsTable;
use App\Models\PaymentIntent;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** System-managed by the charge/verify/webhook flow (AD-13/Phase 4) — view and re-verify only. */
class PaymentIntentResource extends Resource
{
    protected static ?string $model = PaymentIntent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static ?string $recordTitleAttribute = 'provider_reference';

    /** Company/super admins oversee every branch, so they get a company-wide query instead of the current tenant (see isScopedToTenant()). */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->hasAnyRole(['company_admin', 'super_admin'])) {
            return $query->where('company_id', $user->company_id);
        }

        return $query->where('branch_id', Filament::getTenant()?->id);
    }

    public static function isScopedToTenant(): bool
    {
        return ! (auth()->user()?->hasAnyRole(['company_admin', 'super_admin']) ?? false);
    }

    public static function table(Table $table): Table
    {
        return PaymentIntentsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentIntents::route('/'),
        ];
    }
}
