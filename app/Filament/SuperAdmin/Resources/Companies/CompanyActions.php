<?php

namespace App\Filament\SuperAdmin\Resources\Companies;

use App\Actions\Billing\SubscribeCompanyAction;
use App\Actions\Company\CreateBranchAction;
use App\Actions\Company\CreateCompanyAdminAction;
use App\Actions\Company\ProvisionStarterProductsAction;
use App\Actions\Company\SetCompanyActiveStatusAction;
use App\Filament\SuperAdmin\Resources\Companies\Schemas\BranchForm;
use App\Filament\SuperAdmin\Resources\Companies\Schemas\CompanyAdminForm;
use App\Models\Company;
use App\Models\Plan;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;

/**
 * Company actions shared by the companies table and the company view page.
 */
class CompanyActions
{
    public static function addCompanyAdmin(): Action
    {
        return Action::make('addCompanyAdmin')
            ->label('Add company admin')
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalDescription('A company super admin signs in to the admin panel and adds the company\'s own staff.')
            ->schema([Grid::make(2)->schema(CompanyAdminForm::fields())])
            ->visible(fn (Company $record): bool => $record->branches()->exists())
            ->action(function (array $data, Company $record): void {
                $admin = app(CreateCompanyAdminAction::class)->execute($record, $data);

                Notification::make()
                    ->title("{$admin->name} can now sign in as company admin")
                    ->success()
                    ->send();
            });
    }

    public static function addBranch(): Action
    {
        return Action::make('addBranch')
            ->label('Add branch')
            ->icon(Heroicon::OutlinedMapPin)
            ->schema(fn (Company $record): array => [Grid::make(2)->schema(BranchForm::fields(fn (): string => $record->id))])
            ->action(function (array $data, Company $record): void {
                $branch = app(CreateBranchAction::class)->execute($record, $data);

                Notification::make()->title("{$branch->name} added")->success()->send();
            });
    }

    public static function addStarterProducts(): Action
    {
        return Action::make('addStarterProducts')
            ->label('Add starter products')
            ->icon(Heroicon::OutlinedSquaresPlus)
            ->requiresConfirmation()
            ->modalDescription('Adds the standard savings and loan products this company does not already have. Existing products are left untouched.')
            ->action(function (Company $record): void {
                $added = app(ProvisionStarterProductsAction::class)->execute($record);

                Notification::make()
                    ->title("Added {$added['savings']} savings and {$added['loans']} loan product(s)")
                    ->success()
                    ->send();
            });
    }

    public static function changePlan(): Action
    {
        return Action::make('changePlan')
            ->label(fn (Company $record): string => $record->subscription === null ? 'Subscribe to plan' : 'Change plan')
            ->icon(Heroicon::OutlinedRectangleStack)
            ->modalDescription('A first subscription starts the plan\'s trial (or invoices the first period). Changing plan applies the new limits now and the new price from the next period.')
            ->schema([
                Select::make('plan_id')
                    ->label('Plan')
                    ->options(fn (): array => Plan::where('is_active', true)->orderBy('sort')->pluck('name', 'id')->all())
                    ->default(fn (Company $record): ?string => $record->subscription?->plan_id)
                    ->required(),
            ])
            ->action(function (array $data, Company $record): void {
                $plan = Plan::findOrFail($data['plan_id']);
                app(SubscribeCompanyAction::class)->execute($record, $plan);

                Notification::make()->title("{$record->name} is on the {$plan->name} plan")->success()->send();
            });
    }

    public static function toggleActive(): Action
    {
        return Action::make('toggleActive')
            ->label(fn (Company $record): string => $record->is_active ? 'Suspend' : 'Reactivate')
            ->icon(fn (Company $record): Heroicon => $record->is_active ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle)
            ->color(fn (Company $record): string => $record->is_active ? 'danger' : 'success')
            ->requiresConfirmation()
            ->modalHeading(fn (Company $record): string => ($record->is_active ? 'Suspend ' : 'Reactivate ').$record->name)
            ->modalDescription(fn (Company $record): string => $record->is_active
                ? 'Every staff member and customer of this company will be signed out and refused on the web and the apps until it is reactivated. No data is deleted.'
                : 'The company\'s active users will be able to sign in again.')
            ->action(function (Company $record): void {
                app(SetCompanyActiveStatusAction::class)->execute($record, ! $record->is_active);

                Notification::make()
                    ->title($record->is_active ? "{$record->name} reactivated" : "{$record->name} suspended")
                    ->success()
                    ->send();
            });
    }
}
