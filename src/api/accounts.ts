import { api } from '@/api/client';
import type { Paginated, SavingsAccount, Transaction } from '@/types/api';

export async function getAgentAccounts(search?: string): Promise<Paginated<SavingsAccount>> {
  const { data } = await api.get<Paginated<SavingsAccount>>('/agent/accounts', {
    params: search ? { search } : undefined,
  });

  return data;
}

export async function getCustomerAccounts(): Promise<SavingsAccount[]> {
  const { data } = await api.get<{ data: SavingsAccount[] }>('/customer/accounts');

  return data.data;
}

export async function getAccountTransactions(
  accountId: string,
  page = 1,
): Promise<Paginated<Transaction>> {
  const { data } = await api.get<Paginated<Transaction>>(
    `/customer/accounts/${accountId}/transactions`,
    { params: { page } },
  );

  return data;
}
