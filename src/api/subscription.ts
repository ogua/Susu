import { api } from '@/api/client';

export type SubscriptionStatus = 'trialing' | 'active' | 'past_due' | 'cancelled';

export interface UsageFigure {
  used: number;
  /** null = unlimited on this plan. */
  limit: number | null;
}

export interface OpenInvoice {
  id: string;
  number: string;
  /** Minor units (pesewas). */
  amount: number;
  currency: string;
  period_start: string;
  period_end: string;
  due_at: string;
  is_overdue: boolean;
}

export interface CompanySubscription {
  plan: {
    id: string;
    name: string;
    code: string;
    price_amount: number;
    currency: string;
    billing_period: 'monthly' | 'yearly';
  } | null;
  status: SubscriptionStatus | null;
  trial_ends_at: string | null;
  current_period_end: string | null;
  usage: Record<'branches' | 'staff' | 'customers', UsageFigure>;
  /** Absent on servers older than the in-app payment release. */
  support?: { email: string | null; phone: string | null };
  open_invoices: OpenInvoice[];
}

/** The company admin's plan, usage against its limits and unpaid invoices. */
export async function getSubscription(): Promise<CompanySubscription> {
  const { data } = await api.get<{ data: CompanySubscription }>('/company/subscription');

  return data.data;
}

/** Starts a Paystack checkout for an invoice; open the returned URL in a browser. */
export async function startInvoiceCheckout(invoiceId: string): Promise<{ authorization_url: string; reference: string }> {
  const { data } = await api.post<{ authorization_url: string; reference: string }>(
    `/company/subscription/invoices/${invoiceId}/checkout`,
  );

  return data;
}

/** Asks the server to confirm the payment with Paystack once the browser closes. */
export async function verifyInvoicePayment(invoiceId: string): Promise<CompanySubscription> {
  const { data } = await api.post<{ data: CompanySubscription }>(`/company/subscription/invoices/${invoiceId}/verify`);

  return data.data;
}
