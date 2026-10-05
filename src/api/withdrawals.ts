import { api } from '@/api/client';
import type { Paginated, WithdrawalRequest } from '@/types/api';

export async function getWithdrawalRequests(page = 1): Promise<Paginated<WithdrawalRequest>> {
  const { data } = await api.get<Paginated<WithdrawalRequest>>('/customer/withdrawal-requests', {
    params: { page },
  });

  return data;
}

export async function createWithdrawalRequest(payload: {
  savings_account_id: string;
  amount: number;
  reason?: string;
  /** Idempotency key: a retry with the same key returns the original request. */
  client_reference?: string;
}): Promise<WithdrawalRequest> {
  const { data } = await api.post<{ data: WithdrawalRequest }>(
    '/customer/withdrawal-requests',
    payload,
  );

  return data.data;
}
