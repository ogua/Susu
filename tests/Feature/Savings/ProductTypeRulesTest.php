<?php

use App\Actions\Ledger\ReverseJournalEntryAction;
use App\Actions\Savings\BuySharesAction;
use App\Actions\Savings\DecideWithdrawalAction;
use App\Actions\Savings\OpenSavingsAccountAction;
use App\Actions\Savings\PayWithdrawalAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Actions\Savings\RequestWithdrawalAction;
use App\Enums\CommissionType;
use App\Enums\TransactionType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    seedRoles();

    $this->branch = Branch::factory()->create();
    $this->agent = User::factory()->fieldAgent($this->branch)->create();
    $this->manager = User::factory()->branchManager($this->branch)->create();
    $this->admin = User::factory()->companyAdmin($this->branch->company)->create();
    $this->customer = Customer::factory()->forBranch($this->branch)->create();
});

function openProductTypeAccount(SavingsProduct $product, array $overrides = []): SavingsAccount
{
    return app(OpenSavingsAccountAction::class)->execute(...[
        'customer' => test()->customer,
        'product' => $product,
        'agent' => test()->agent,
        ...$overrides,
    ]);
}

describe('fixed deposit funding', function (): void {
    beforeEach(function (): void {
        $this->product = SavingsProduct::factory()->fixedDeposit()->create(['company_id' => $this->branch->company_id]);
        $this->account = openProductTypeAccount($this->product, ['contributionAmount' => 1_000_00, 'maturesAt' => now()->addYear()]);
    });

    it('accepts exactly the principal once', function (): void {
        app(RecordCollectionAction::class)->execute($this->agent, $this->account, 1_000_00);

        expect($this->account->fresh()->balance)->toBe(1_000_00);
    });

    it('rejects a top-up after funding', function (): void {
        app(RecordCollectionAction::class)->execute($this->agent, $this->account, 1_000_00);
        app(RecordCollectionAction::class)->execute($this->agent, $this->account->fresh(), 1_000_00);
    })->throws(ValidationException::class, 'already funded');

    it('rejects funding with anything other than the principal', function (): void {
        app(RecordCollectionAction::class)->execute($this->agent, $this->account, 2_000_00);
    })->throws(ValidationException::class, 'exactly its principal');

    it('rejects deposits after maturity', function (): void {
        $this->account->forceFill(['matured_at' => now()])->save();

        app(RecordCollectionAction::class)->execute($this->agent, $this->account->fresh(), 1_000_00);
    })->throws(ValidationException::class, 'already funded');
});

it('never charges commission on a fixed deposit even if the product row still has one', function (): void {
    $product = SavingsProduct::factory()->fixedDeposit()->create(['company_id' => $this->branch->company_id]);
    // Simulate a row saved before the model cleared commission on non-susu types.
    DB::table('savings_products')->where('id', $product->id)->update([
        'commission_type' => CommissionType::FirstContributionPerCycle->value,
    ]);

    $account = openProductTypeAccount($product->fresh(), ['contributionAmount' => 500_00, 'maturesAt' => now()->addYear()]);
    $result = app(RecordCollectionAction::class)->execute($this->agent, $account, 500_00);

    expect($result->commissionAmount)->toBe(0)
        ->and($account->fresh()->balance)->toBe(500_00);
});

describe('product term', function (): void {
    it('derives the maturity date from the product term when none is given', function (): void {
        $product = SavingsProduct::factory()->fixedDeposit()->create([
            'company_id' => $this->branch->company_id,
            'term_days' => 180,
        ]);

        $account = openProductTypeAccount($product, ['contributionAmount' => 100_00]);

        expect($account->matures_at->toDateString())->toBe(now()->addDays(180)->toDateString());
    });

    it('keeps an explicit maturity date over the product term', function (): void {
        $product = SavingsProduct::factory()->target()->create([
            'company_id' => $this->branch->company_id,
            'term_days' => 90,
        ]);
        $date = now()->addDays(30)->startOfDay();

        $account = openProductTypeAccount($product, ['targetAmount' => 500_00, 'maturesAt' => $date]);

        expect($account->matures_at->toDateString())->toBe($date->toDateString());
    });

    it('drops the term from products that have no maturity', function (): void {
        $product = SavingsProduct::factory()->create([
            'company_id' => $this->branch->company_id,
            'term_days' => 90,
        ]);

        expect($product->fresh()->term_days)->toBeNull();
    });

    it('exposes the term and penalty in the sync bootstrap catalogue', function (): void {
        SavingsProduct::factory()->target(500)->create([
            'company_id' => $this->branch->company_id,
            'term_days' => 60,
        ]);

        $this->actingAs($this->agent, 'sanctum')
            ->getJson('/api/v1/sync/bootstrap')
            ->assertOk()
            ->assertJsonPath('products.0.term_days', 60)
            ->assertJsonPath('products.0.early_withdrawal_penalty_bps', 500);
    });
});

describe('share withdrawals', function (): void {
    beforeEach(function (): void {
        $this->product = SavingsProduct::factory()->shares(10_00)->create(['company_id' => $this->branch->company_id]);
        $this->account = openProductTypeAccount($this->product);
        app(BuySharesAction::class)->execute($this->agent, $this->account, 10);
    });

    it('rejects a withdrawal that is not a whole number of shares', function (): void {
        app(RequestWithdrawalAction::class)->execute($this->agent, $this->account->fresh(), 15_00);
    })->throws(ValidationException::class, 'whole number of shares');

    it('redeems shares on payout and restores them on reversal', function (): void {
        $request = app(RequestWithdrawalAction::class)->execute($this->agent, $this->account->fresh(), 30_00);
        app(DecideWithdrawalAction::class)->approve($this->manager, $request);
        app(PayWithdrawalAction::class)->execute($this->manager, $request->refresh());

        $account = $this->account->fresh();
        expect($account->share_count)->toBe(7)
            ->and($account->balance)->toBe(70_00);

        $entry = JournalEntry::where('type', TransactionType::Withdrawal)->latest()->firstOrFail();
        app(ReverseJournalEntryAction::class)->execute($entry, $this->admin, 'Paid in error');

        $account = $this->account->fresh();
        expect($account->share_count)->toBe(10)
            ->and($account->balance)->toBe(100_00);
    });
});
