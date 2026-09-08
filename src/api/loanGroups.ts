import { api } from '@/api/client';
import type { LoanGroup, Paginated } from '@/types/api';

export async function getLoanGroups(page = 1): Promise<Paginated<LoanGroup>> {
  const { data } = await api.get<Paginated<LoanGroup>>('/loan-groups', { params: { page } });

  return data;
}

export async function getLoanGroup(loanGroupId: string): Promise<LoanGroup> {
  const { data } = await api.get<{ data: LoanGroup }>(`/loan-groups/${loanGroupId}`);

  return data.data;
}

/** Manager-tier: create a new (empty) loan group roster. */
export async function createLoanGroup(payload: { name: string; code: string }): Promise<LoanGroup> {
  const { data } = await api.post<{ data: LoanGroup }>('/loan-groups', payload);

  return data.data;
}

/** Manager-tier: add an existing customer to a loan group roster. */
export async function addLoanGroupMember(loanGroupId: string, customerId: string): Promise<LoanGroup> {
  const { data } = await api.post<{ data: LoanGroup }>(`/loan-groups/${loanGroupId}/members`, {
    customer_id: customerId,
  });

  return data.data;
}

/** Manager-tier: remove a member (blocked while they still have an active loan). */
export async function removeLoanGroupMember(loanGroupId: string, memberId: string): Promise<LoanGroup> {
  const { data } = await api.delete<{ data: LoanGroup }>(`/loan-groups/${loanGroupId}/members/${memberId}`);

  return data.data;
}
