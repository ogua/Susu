import { enqueue } from '@/sync/outbox';

/**
 * Typed wrappers around the outbox for each offline-capable operation.
 * Payload shapes mirror the backend's Form Request payloadRules() so the
 * same fields validate identically whether pushed online or replayed from
 * the outbox (App\Http\Requests\Api\V1\*).
 */

export async function enqueueCollection(payload: {
  savings_account_id: string;
  amount: number;
  latitude?: number | null;
  longitude?: number | null;
}): Promise<string> {
  return enqueue('collection.record', {
    savings_account_id: payload.savings_account_id,
    amount: payload.amount,
    latitude: payload.latitude ?? undefined,
    longitude: payload.longitude ?? undefined,
  });
}

export async function enqueueCustomerRegistration(payload: {
  first_name: string;
  last_name: string;
  phone: string;
  gender?: string;
  date_of_birth?: string;
  id_type?: string;
  id_number?: string;
  next_of_kin_name?: string;
  next_of_kin_phone?: string;
  next_of_kin_relationship?: string;
  address?: string;
}): Promise<string> {
  return enqueue('customer.register', payload);
}

export async function enqueueDailySummary(payload: {
  declared_cash: number;
  notes?: string;
}): Promise<string> {
  return enqueue('summary.submit', payload);
}
