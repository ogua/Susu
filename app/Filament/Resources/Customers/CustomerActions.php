<?php

namespace App\Filament\Resources\Customers;

use App\Actions\Customers\AssignCustomerAgentAction;
use App\Actions\Customers\DeleteCustomerAction;
use App\Actions\Customers\TransferCustomerAction;
use App\Actions\LoanGroups\AddLoanGroupMemberAction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoanGroup;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Transfer / assign-agent / add-to-group, as single-record actions (table row
 * and view page) and bulk actions. All of them delegate to the shared action
 * classes the API uses.
 */
class CustomerActions
{
    /**
     * A customer can only be deleted once nothing financial is still open
     * for them (DeleteCustomerAction::blockingReason); otherwise the delete
     * is cancelled with the reason.
     */
    public static function guardedDelete(DeleteAction $action): DeleteAction
    {
        return $action->before(function (DeleteAction $action, Customer $record): void {
            $reason = app(DeleteCustomerAction::class)->blockingReason($record);

            if ($reason !== null) {
                Notification::make()->title('Customer not deleted')->body($reason)->danger()->send();
                $action->cancel();
            }
        });
    }

    public static function transfer(): Action
    {
        return Action::make('transfer')
            ->label('Transfer branch')
            ->icon(Heroicon::ArrowsRightLeft)
            ->color('gray')
            ->authorize('transfer')
            ->modalDescription('Moves the customer with their savings accounts, open loans and pending withdrawal requests. Posted transactions stay with the original branch.')
            ->schema(fn (Customer $record): array => self::transferSchema($record->branch_id))
            ->action(function (array $data, Customer $record): void {
                try {
                    app(TransferCustomerAction::class)->execute(
                        $record,
                        Branch::findOrFail($data['branch_id']),
                        Filament::auth()->user(),
                        filled($data['agent_id'] ?? null) ? User::find($data['agent_id']) : null,
                        $data['reason'] ?? null,
                    );
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                    return;
                }

                Notification::make()->title('Customer transferred')->success()->send();
            });
    }

    public static function bulkTransfer(): BulkAction
    {
        return BulkAction::make('bulkTransfer')
            ->label('Transfer branch')
            ->icon(Heroicon::ArrowsRightLeft)
            ->visible(fn (): bool => Filament::auth()->user()->hasRole(['company_admin', 'branch_manager']))
            ->authorizeIndividualRecords('transfer')
            ->schema(self::transferSchema(null))
            ->deselectRecordsAfterCompletion()
            ->action(function (array $data, Collection $records): void {
                $result = app(TransferCustomerAction::class)->executeMany(
                    $records,
                    Branch::findOrFail($data['branch_id']),
                    Filament::auth()->user(),
                    filled($data['agent_id'] ?? null) ? User::find($data['agent_id']) : null,
                    $data['reason'] ?? null,
                );

                self::notifyBulkResult('transferred', count($result['transferred']), $result['failed']);
            });
    }

    public static function assignAgent(): Action
    {
        return Action::make('assignAgent')
            ->label('Assign agent')
            ->icon(Heroicon::UserPlus)
            ->color('gray')
            ->authorize('assignAgent')
            ->fillForm(fn (Customer $record): array => ['agent_id' => $record->assigned_agent_id])
            ->schema(fn (Customer $record): array => [
                Select::make('agent_id')
                    ->label('Field agent')
                    ->helperText('Leave empty to unassign. The agent also takes over collection on the customer\'s active savings accounts.')
                    ->options(fn (): array => self::agentOptions($record->branch_id))
                    ->searchable(),
            ])
            ->action(function (array $data, Customer $record): void {
                try {
                    app(AssignCustomerAgentAction::class)->execute(
                        $record,
                        filled($data['agent_id'] ?? null) ? User::find($data['agent_id']) : null,
                        Filament::auth()->user(),
                    );
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                    return;
                }

                Notification::make()->title('Agent assigned')->success()->send();
            });
    }

    public static function bulkAssignAgent(): BulkAction
    {
        return BulkAction::make('bulkAssignAgent')
            ->label('Assign agent')
            ->icon(Heroicon::UserPlus)
            ->authorizeIndividualRecords('assignAgent')
            ->visible(fn (): bool => Filament::auth()->user()->hasRole(['company_admin', 'branch_manager']))
            ->schema([
                Select::make('agent_id')
                    ->label('Field agent')
                    ->helperText('Customers outside this agent\'s branch are skipped.')
                    ->options(fn (): array => self::agentOptions(null))
                    ->searchable()
                    ->required(),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (array $data, Collection $records): void {
                $user = Filament::auth()->user();
                $agent = User::findOrFail($data['agent_id']);
                $assigned = 0;
                $failed = [];

                foreach ($records as $customer) {
                    try {
                        app(AssignCustomerAgentAction::class)->execute($customer, $agent, $user);
                        $assigned++;
                    } catch (ValidationException $e) {
                        $failed[$customer->id] = $customer->fullName().': '.collect($e->errors())->flatten()->first();
                    }
                }

                self::notifyBulkResult('assigned', $assigned, $failed);
            });
    }

    public static function addToGroup(): Action
    {
        return Action::make('addToGroup')
            ->label('Add to group')
            ->icon(Heroicon::UserGroup)
            ->color('gray')
            ->authorize(fn (): bool => Filament::auth()->user()->hasRole(['company_admin', 'branch_manager']))
            ->schema(fn (Customer $record): array => [
                Select::make('loan_group_id')
                    ->label('Customer group')
                    ->options(fn (): array => self::groupOptions($record->branch_id))
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, Customer $record): void {
                try {
                    app(AddLoanGroupMemberAction::class)->execute(LoanGroup::findOrFail($data['loan_group_id']), $record);
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                    return;
                }

                Notification::make()->title('Added to group')->success()->send();
            });
    }

    public static function bulkAddToGroup(): BulkAction
    {
        return BulkAction::make('bulkAddToGroup')
            ->label('Add to group')
            ->icon(Heroicon::UserGroup)
            ->visible(fn (): bool => Filament::auth()->user()->hasRole(['company_admin', 'branch_manager']))
            ->schema([
                Select::make('loan_group_id')
                    ->label('Customer group')
                    ->options(fn (): array => self::groupOptions(null))
                    ->searchable()
                    ->required(),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (array $data, Collection $records): void {
                $group = LoanGroup::findOrFail($data['loan_group_id']);
                $added = 0;
                $failed = [];

                foreach ($records as $customer) {
                    try {
                        app(AddLoanGroupMemberAction::class)->execute($group, $customer);
                        $added++;
                    } catch (ValidationException $e) {
                        $failed[$customer->id] = $customer->fullName().': '.collect($e->errors())->flatten()->first();
                    } catch (Throwable $e) {
                        report($e);
                        $failed[$customer->id] = $customer->fullName().': could not be added.';
                    }
                }

                self::notifyBulkResult('added', $added, $failed);
            });
    }

    /**
     * @return array<int, Select|Textarea>
     */
    private static function transferSchema(?string $currentBranchId): array
    {
        return [
            Select::make('branch_id')
                ->label('Destination branch')
                ->options(fn (): array => Branch::where('company_id', Filament::auth()->user()->company_id)
                    ->when($currentBranchId, fn ($query) => $query->whereKeyNot($currentBranchId))
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->required()
                ->live(),
            Select::make('agent_id')
                ->label('Assign to field agent (optional)')
                ->options(fn (Get $get): array => filled($get('branch_id')) ? self::agentOptions($get('branch_id')) : [])
                ->searchable(),
            Textarea::make('reason')->maxLength(500),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function agentOptions(?string $branchId): array
    {
        return User::role('field_agent')
            ->where('company_id', Filament::auth()->user()->company_id)
            ->where('branch_id', $branchId ?? Filament::getTenant()?->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function groupOptions(?string $branchId): array
    {
        return LoanGroup::where('company_id', Filament::auth()->user()->company_id)
            ->where('branch_id', $branchId ?? Filament::getTenant()?->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (LoanGroup $group): array => [$group->id => "{$group->name} ({$group->code})"])
            ->all();
    }

    /**
     * @param  array<string, string>  $failed
     */
    private static function notifyBulkResult(string $verb, int $succeeded, array $failed): void
    {
        $notification = Notification::make()->title("{$succeeded} customer(s) {$verb}");

        if ($failed === []) {
            $notification->success()->send();

            return;
        }

        $notification
            ->warning()
            ->body(count($failed).' skipped: '.implode(' · ', array_slice(array_values($failed), 0, 5)).(count($failed) > 5 ? ' …' : ''))
            ->persistent()
            ->send();
    }
}
