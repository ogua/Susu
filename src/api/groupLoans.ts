import { api } from '@/api/client';
import type { GroupLoan, Paginated, RepaymentFrequency } from '@/types/api';

export async function getGroupLoans(page = 1): Promise<Paginated<GroupLoan>> {
  const { data } = await api.get<Paginated<GroupLoan>>('/group-loans', { params: { page } });

  return data;
}

export async function getGroupLoan(groupLoanId: string): Promise<GroupLoan> {
  const { data } = await api.get<{ data: GroupLoan }>(`/group-loans/${groupLoanId}`);

  return data.data;
}

/**
 * Issue one loan to one member of a loan group. All amounts are in minor
 * units (pesewas); start_date is an ISO date string (YYYY-MM-DD).
 */
export async function issueGroupMemberLoan(payload: {
  loan_group_id: string;
  customer_id: string;
  principal_amount: number;
  security_deposit_amount: number;
  periodic_amount: number;
  repayment_frequency: RepaymentFrequency;
  start_date: string;
  notes?: string;
}): Promise<GroupLoan> {
  const { data } = await api.post<{ data: GroupLoan }>('/group-loans', payload);

  return data.data;
}

/** Record the member paying in their agreed security deposit (in minor units). */
export async function recordGroupLoanDeposit(groupLoanId: string, amount: number): Promise<GroupLoan> {
  const { data } = await api.post<{ group_loan: GroupLoan }>(`/group-loans/${groupLoanId}/deposit`, { amount });

  return data.group_loan;
}

/** Apply the held deposit against the outstanding balance (non-cash); excess is refunded. */
export async function applyGroupLoanDeposit(groupLoanId: string): Promise<GroupLoan> {
  const { data } = await api.post<{ group_loan: GroupLoan }>(`/group-loans/${groupLoanId}/apply-deposit`, {});

  return data.group_loan;
}

/** Activate a draft loan once its deposit is held: generates the schedule and disburses the principal. */
export async function activateGroupLoan(groupLoanId: string): Promise<GroupLoan> {
  const { data } = await api.post<{ data: GroupLoan }>(`/group-loans/${groupLoanId}/activate`, {});

  return data.data;
}

/** Record a cash repayment against the member's loan (in minor units). */
export async function recordGroupLoanRepayment(groupLoanId: string, amount: number): Promise<GroupLoan> {
  const { data } = await api.post<{ group_loan: GroupLoan }>(`/group-loans/${groupLoanId}/repayments`, { amount });

  return data.group_loan;
}

/** Manager-tier: declare the remaining balance uncollectible. */
export async function writeOffGroupLoan(groupLoanId: string, reason: string): Promise<GroupLoan> {
  const { data } = await api.post<{ data: GroupLoan }>(`/group-loans/${groupLoanId}/write-off`, { reason });

  return data.data;
}
