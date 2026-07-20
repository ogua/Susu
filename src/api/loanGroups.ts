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
