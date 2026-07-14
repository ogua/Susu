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

/**
 * A short-lived signed URL the in-app browser can open directly — it can't
 * attach the Bearer token, so this hits the `/statement-url` endpoint
 * (which re-checks ownership) instead of the Bearer-authed `/statement`
 * download the web/desktop clients use.
 */
export async function getCustomerStatementUrl(accountId: string): Promise<string> {
  const { data } = await api.get<{ url: string }>(`/customer/accounts/${accountId}/statement-url`);

  return data.url;
}

export async function getAgentStatementUrl(accountId: string): Promise<string> {
  const { data } = await api.get<{ url: string }>(`/agent/accounts/${accountId}/statement-url`);

  return data.url;
}
