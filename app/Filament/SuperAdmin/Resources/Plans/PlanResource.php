<?php

namespace App\Filament\SuperAdmin\Resources\Plans;

use App\Filament\SuperAdmin\Resources\Plans\Pages\CreatePlan;
use App\Filament\SuperAdmin\Resources\Plans\Pages\EditPlan;
use App\Filament\SuperAdmin\Resources\Plans\Pages\ListPlans;
use App\Filament\SuperAdmin\Resources\Plans\Schemas\PlanForm;
use App\Filament\SuperAdmin\Resources\Plans\Tables\PlansTable;
use App\Models\Plan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Subscription plans companies are billed on. No delete: subscriptions and
 * invoices reference plans; switch one off (is_active) to stop offering it.
 */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlansTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
