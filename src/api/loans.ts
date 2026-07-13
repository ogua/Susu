import { api } from '@/api/client';
import type { Loan, LoanEligibility, LoanProduct, Paginated } from '@/types/api';

export async function getLoanProducts(): Promise<LoanProduct[]> {
  const { data } = await api.get<{ data: LoanProduct[] }>('/loans/products');

  return data.data;
}

export async function getLoanEligibility(savingsAccountId: string, amount: number): Promise<LoanEligibility> {
  const { data } = await api.get<LoanEligibility>('/loans/eligibility', {
    params: { savings_account_id: savingsAccountId, amount },
  });

  return data;
}

export async function getLoans(page = 1): Promise<Paginated<Loan>> {
  const { data } = await api.get<Paginated<Loan>>('/loans', { params: { page } });

  return data;
}

export async function getLoan(loanId: string): Promise<Loan> {
  const { data } = await api.get<{ data: Loan }>(`/loans/${loanId}`);

  return data.data;
}

export async function applyForLoan(payload: {
  customer_id?: string;
  loan_product_id: string;
  amount: number;
  savings_account_id?: string;
  guarantor_name?: string;
  guarantor_phone?: string;
  notes?: string;
}): Promise<Loan> {
  const { data } = await api.post<{ data: Loan }>('/loans', payload);

  return data.data;
}

export async function recordLoanRepayment(loanId: string, amount: number): Promise<Loan> {
  const { data } = await api.post<{ loan: Loan }>(`/loans/${loanId}/repayments`, { amount });

  return data.loan;
}
