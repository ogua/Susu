import { api } from '@/api/client';
import type { MobileMoneyProvider, PaymentIntent } from '@/types/api';

/**
 * Mobile money requires connectivity (Paystack itself is an online API), so
 * unlike collections/customers this never goes through the offline outbox —
 * these calls hit the server directly and the caller should surface network
 * errors immediately rather than queueing a retry.
 */

export async function chargeMobileMoney(payload: {
  savings_account_id: string;
  amount: number;
  phone: string;
  provider: MobileMoneyProvider;
}): Promise<PaymentIntent> {
  const { data } = await api.post<{ intent: PaymentIntent }>('/payments/charge', payload);

  return data.intent;
}

export async function initializeCheckout(payload: {
  savings_account_id: string;
  amount: number;
  callback_url: string;
}): Promise<{ intent: PaymentIntent; authorization_url: string | null }> {
  const { data } = await api.post<{ intent: PaymentIntent; authorization_url: string | null }>(
    '/payments/initialize',
    payload,
  );

  return data;
}

export async function submitChargeOtp(intentId: string, otp: string): Promise<PaymentIntent> {
  const { data } = await api.post<{ intent: PaymentIntent }>(`/payments/${intentId}/submit-otp`, { otp });

  return data.intent;
}

export async function verifyPaymentIntent(intentId: string): Promise<PaymentIntent> {
  const { data } = await api.post<{ intent: PaymentIntent }>(`/payments/${intentId}/verify`);

  return data.intent;
}

export async function getPaymentIntent(intentId: string): Promise<PaymentIntent> {
  const { data } = await api.get<{ intent: PaymentIntent }>(`/payments/${intentId}`);

  return data.intent;
}
