<?php

namespace App\Actions\Sync;

use App\Actions\Agents\RecordLocationPingsAction;
use App\Actions\Agents\SubmitAgentDailySummaryAction;
use App\Actions\Customers\CreateCustomerAction;
use App\Actions\Savings\OpenSavingsAccountAction;
use App\Actions\Savings\RecordCollectionAction;
use App\Enums\ClientOrigin;
use App\Enums\SyncOpType;
use App\Http\Requests\Api\V1\StoreCollectionRequest;
use App\Http\Requests\Api\V1\StoreCustomerRequest;
use App\Http\Requests\Api\V1\StoreLocationPingsRequest;
use App\Http\Requests\Api\V1\StoreSavingsAccountRequest;
use App\Http\Requests\Api\V1\SubmitDailySummaryRequest;
use App\Models\Customer;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SyncOp;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Applies a batch of offline operations, one result per op — never
 * all-or-nothing (AD-3). Two idempotency layers: sync_ops.op_id (this class)
 * and client_reference on the produced records (the actions), so replays are
 * safe even across transport retries.
 */
class ProcessSyncBatchAction
{
    public function __construct(
        private CreateCustomerAction $createCustomer,
        private OpenSavingsAccountAction $openAccount,
        private RecordCollectionAction $recordCollection,
        private SubmitAgentDailySummaryAction $submitSummary,
        private RecordLocationPingsAction $recordPings,
    ) {}

    /**
     * @param  array<int, array{op_id: string, op_type: string, payload: array<string, mixed>, recorded_at: string}>  $ops
     * @return array<int, array<string, mixed>>
     */
    public function execute(User $actor, ClientOrigin $origin, array $ops): array
    {
        $role = $actor->getRoleNames()->first() ?? 'none';
        $allowed = SyncOpType::allowedFor($role);
        $results = [];

        foreach ($ops as $op) {
            $results[] = $this->applyOne($actor, $origin, $op, $allowed);
        }

        return $results;
    }

    /**
     * @param  array{op_id: string, op_type: string, payload: array<string, mixed>, recorded_at: string}  $op
     * @param  array<int, SyncOpType>  $allowed
     * @return array<string, mixed>
     */
    private function applyOne(User $actor, ClientOrigin $origin, array $op, array $allowed): array
    {
        $opId = $op['op_id'];
        $opType = SyncOpType::from($op['op_type']);

        $existing = SyncOp::where('op_id', $opId)->first();
        if ($existing !== null) {
            return ['op_id' => $opId, 'status' => 'duplicate', 'result' => $existing->result];
        }

        if (! in_array($opType, $allowed, true)) {
            return $this->record($actor, $origin, $op, 'rejected', [
                'errors' => ['This operation is not allowed for your role.'],
            ]);
        }

        try {
            $result = $this->dispatch($actor, $origin, $opType, $op);

            return $this->record($actor, $origin, $op, 'applied', $result);
        } catch (ValidationException $e) {
            return $this->record($actor, $origin, $op, 'rejected', [
                'errors' => collect($e->errors())->flatten()->all(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return $this->record($actor, $origin, $op, 'rejected', [
                'errors' => ['The server could not apply this operation.'],
            ]);
        }
    }

    /**
     * @param  array{op_id: string, op_type: string, payload: array<string, mixed>, recorded_at: string}  $op
     * @return array<string, mixed>
     */
    private function dispatch(User $actor, ClientOrigin $origin, SyncOpType $opType, array $op): array
    {
        $payload = $this->validatePayload($opType, $op['payload']);
        $recordedAt = Carbon::parse($op['recorded_at']);

        return match ($opType) {
            SyncOpType::RegisterCustomer => $this->applyRegisterCustomer($actor, $payload, $op['op_id']),
            SyncOpType::OpenSavingsAccount => $this->applyOpenAccount($actor, $payload, $op['op_id']),
            SyncOpType::RecordCollection => $this->applyCollection($actor, $origin, $payload, $op['op_id'], $recordedAt),
            SyncOpType::SubmitDailySummary => $this->applySummary($actor, $payload, $op['op_id'], $recordedAt),
            SyncOpType::RecordLocationPings => ['stored' => $this->recordPings->execute($actor, $payload['pings'])],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePayload(SyncOpType $opType, array $payload): array
    {
        $rules = match ($opType) {
            SyncOpType::RegisterCustomer => StoreCustomerRequest::payloadRules(),
            SyncOpType::OpenSavingsAccount => StoreSavingsAccountRequest::payloadRules(),
            SyncOpType::RecordCollection => StoreCollectionRequest::payloadRules(),
            SyncOpType::SubmitDailySummary => SubmitDailySummaryRequest::payloadRules(),
            SyncOpType::RecordLocationPings => StoreLocationPingsRequest::payloadRules(),
        };

        return Validator::make($payload, $rules)->validate();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyRegisterCustomer(User $actor, array $payload, string $opId): array
    {
        $customer = $this->createCustomer->execute(
            $actor,
            $actor->branch,
            collect($payload)->except('client_reference')->all(),
            $payload['client_reference'] ?? $opId,
        );

        return ['customer_id' => $customer->id, 'customer_code' => $customer->customer_code];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyOpenAccount(User $actor, array $payload, string $opId): array
    {
        $customer = Customer::where('company_id', $actor->company_id)->findOrFail($payload['customer_id']);
        $product = SavingsProduct::where('company_id', $actor->company_id)->findOrFail($payload['savings_product_id']);

        $account = $this->openAccount->execute(
            customer: $customer,
            product: $product,
            agent: $actor,
            contributionAmount: isset($payload['contribution_amount']) ? (int) $payload['contribution_amount'] : null,
            clientReference: $payload['client_reference'] ?? $opId,
        );

        return ['account_id' => $account->id, 'account_number' => $account->account_number];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyCollection(User $actor, ClientOrigin $origin, array $payload, string $opId, Carbon $recordedAt): array
    {
        $account = SavingsAccount::where('company_id', $actor->company_id)
            ->findOrFail($payload['savings_account_id']);

        $result = $this->recordCollection->execute(
            agent: $actor,
            account: $account,
            amount: (int) $payload['amount'],
            clientReference: $payload['client_reference'] ?? $opId,
            recordedAt: isset($payload['recorded_at']) ? Carbon::parse($payload['recorded_at']) : $recordedAt,
            origin: $origin,
            latitude: isset($payload['latitude']) ? (float) $payload['latitude'] : null,
            longitude: isset($payload['longitude']) ? (float) $payload['longitude'] : null,
        );

        return [
            'entry_id' => $result->entry->id,
            'reference' => $result->entry->reference,
            'balance' => $result->account->balance,
            'commission' => $result->commissionAmount,
            'was_duplicate' => $result->duplicate,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applySummary(User $actor, array $payload, string $opId, Carbon $recordedAt): array
    {
        $summary = $this->submitSummary->execute(
            agent: $actor,
            declaredCash: (int) $payload['declared_cash'],
            date: isset($payload['summary_date']) ? Carbon::parse($payload['summary_date']) : $recordedAt,
            notes: $payload['notes'] ?? null,
            clientReference: $payload['client_reference'] ?? $opId,
        );

        return [
            'summary_id' => $summary->id,
            'expected_cash' => $summary->expected_cash,
            'variance' => $summary->variance,
        ];
    }

    /**
     * @param  array{op_id: string, op_type: string, payload: array<string, mixed>, recorded_at: string}  $op
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function record(User $actor, ClientOrigin $origin, array $op, string $status, array $result): array
    {
        SyncOp::create([
            'op_id' => $op['op_id'],
            'company_id' => $actor->company_id,
            'actor_id' => $actor->id,
            'origin' => $origin,
            'op_type' => $op['op_type'],
            'status' => $status,
            'result' => $result,
            'recorded_at' => Carbon::parse($op['recorded_at']),
        ]);

        return ['op_id' => $op['op_id'], 'status' => $status, 'result' => $result];
    }
}
