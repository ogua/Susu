import { api } from '@/api/client';
import type { GroupLoan, Paginated } from '@/types/api';

export async function getGroupLoans(page = 1): Promise<Paginated<GroupLoan>> {
  const { data } = await api.get<Paginated<GroupLoan>>('/group-loans', { params: { page } });

  return data;
}

export async function getGroupLoan(groupLoanId: string): Promise<GroupLoan> {
  const { data } = await api.get<{ data: GroupLoan }>(`/group-loans/${groupLoanId}`);

  return data.data;
}

export async function applyForGroupLoan(payload: {
  loan_group_id: string;
  loan_product_id: string;
  amount: number;
  notes?: string;
}): Promise<GroupLoan> {
  const { data } = await api.post<{ data: GroupLoan }>('/group-loans', payload);

  return data.data;
}

export async function recordGroupLoanRepayment(
  groupLoanId: string,
  groupLoanBorrowerId: string,
  amount: number,
): Promise<GroupLoan> {
  const { data } = await api.post<{ group_loan: GroupLoan }>(`/group-loans/${groupLoanId}/repayments`, {
    group_loan_borrower_id: groupLoanBorrowerId,
    amount,
  });

  return data.group_loan;
}
