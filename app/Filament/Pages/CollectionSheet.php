<?php

namespace App\Filament\Pages;

use App\Actions\Collections\BuildCollectionSheetAction;
use App\Actions\Collections\PostCollectionSheetAction;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\LoanGroup;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * "Enter Transaction" — eBanQR's collection sheet. Pick a branch, date, and a
 * customer group and/or loan officer; the sheet lists who is due, pre-filled
 * with what they owe, plus a savings deposit column. Submitting posts every
 * row through PostCollectionSheetAction (all-or-nothing).
 */
class CollectionSheet extends Page
{
    protected string $view = 'filament.pages.collection-sheet';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'Enter Transaction';

    protected static ?string $title = 'Collection Sheet';

    protected static ?int $navigationSort = 3;

    public ?string $branchId = null;

    public string $date = '';

    #[Url(as: 'group')]
    public ?string $loanGroupId = null;

    #[Url(as: 'officer')]
    public ?string $officerId = null;

    public string $paymentMethod = 'cash';

    public bool $loaded = false;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasRole(['company_admin', 'branch_manager', 'field_agent']) ?? false;
    }

    public function mount(): void
    {
        $this->branchId = Filament::getTenant()?->id;
        $this->date = now()->toDateString();

        if (Filament::auth()->user()->hasRole('field_agent') && $this->loanGroupId === null) {
            $this->officerId = Filament::auth()->id();
        }

        if ($this->loanGroupId !== null || $this->officerId !== null) {
            $this->loadSheet();
        }
    }

    public function updatedBranchId(): void
    {
        $this->loanGroupId = null;
        $this->officerId = null;
        $this->reset('rows', 'loaded');
    }

    public function loadSheet(): void
    {
        $branch = $this->branch();
        $group = $this->loanGroupId ? LoanGroup::where('branch_id', $branch->id)->find($this->loanGroupId) : null;
        $officer = $this->officerId ? User::where('company_id', $branch->company_id)->find($this->officerId) : null;

        $this->rows = array_map(fn (array $row): array => $row + [
            'repayment' => $row['amount_due'] > 0 ? number_format($row['amount_due'] / 100, 2, '.', '') : '',
            'deposit' => '',
        ], app(BuildCollectionSheetAction::class)->execute($branch, Carbon::parse($this->date), $group, $officer));

        $this->loaded = true;
    }

    public function submit(): void
    {
        $entries = array_map(fn (array $row): array => [
            'loan_type' => $row['loan_type'],
            'loan_id' => $row['loan_id'],
            'repayment_amount' => Money::toMinorUnits((float) ($row['repayment'] ?: 0)),
            'savings_account_id' => $row['savings_account_id'],
            'deposit_amount' => Money::toMinorUnits((float) ($row['deposit'] ?: 0)),
        ], $this->rows);

        $entries = array_values(array_filter($entries, fn (array $entry): bool => $entry['repayment_amount'] > 0 || $entry['deposit_amount'] > 0));

        if ($entries === []) {
            Notification::make()->title('Nothing to post — enter at least one amount.')->warning()->send();

            return;
        }

        try {
            $totals = app(PostCollectionSheetAction::class)->execute(
                Filament::auth()->user(),
                $entries,
                Carbon::parse($this->date),
                PaymentMethod::from($this->paymentMethod),
            );
        } catch (ValidationException $e) {
            Notification::make()->title('Sheet not posted')->body(collect($e->errors())->flatten()->first())->danger()->persistent()->send();

            return;
        }

        Notification::make()
            ->title('Collection sheet posted')
            ->body($totals['repayments_count'].' repayment(s) of '.Money::format($totals['repayments_total'])
                .' and '.$totals['deposits_count'].' deposit(s) of '.Money::format($totals['deposits_total']).'.')
            ->success()
            ->send();

        $this->loadSheet();
    }

    public function totalRepayment(): int
    {
        return array_sum(array_map(fn (array $row): int => Money::toMinorUnits((float) ($row['repayment'] ?: 0)), $this->rows));
    }

    public function totalDeposit(): int
    {
        return array_sum(array_map(fn (array $row): int => Money::toMinorUnits((float) ($row['deposit'] ?: 0)), $this->rows));
    }

    /**
     * @return array<string, string>
     */
    public function branchOptions(): array
    {
        $user = Filament::auth()->user();

        return $user->hasRole('company_admin')
            ? Branch::where('company_id', $user->company_id)->orderBy('name')->pluck('name', 'id')->all()
            : [Filament::getTenant()->id => Filament::getTenant()->name];
    }

    /**
     * @return array<string, string>
     */
    public function groupOptions(): array
    {
        return LoanGroup::where('branch_id', $this->branchId)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    public function officerOptions(): array
    {
        return User::role('field_agent')->where('branch_id', $this->branchId)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    private function branch(): Branch
    {
        return Branch::where('company_id', Filament::auth()->user()->company_id)
            ->whereKey(array_keys($this->branchOptions()))
            ->findOrFail($this->branchId);
    }
}
