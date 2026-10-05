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

export async function enqueueOpenAccount(payload: {
  customer_id: string;
  savings_product_id: string;
  contribution_amount?: number;
  target_amount?: number;
  matures_at?: string;
}): Promise<string> {
  return enqueue('account.open', payload);
}

export async function enqueueCustomerRegistration(payload: {
  first_name: string;
  last_name: string;
  phone: string;
  client_type?: 'individual' | 'business';
  gender?: string;
  date_of_birth?: string;
  id_type?: string;
  id_number?: string;
  next_of_kin_name?: string;
  next_of_kin_phone?: string;
  next_of_kin_relationship?: string;
  address?: string;
  business_name?: string;
  business_structure?: string;
  business_start_date?: string;
}): Promise<string> {
  return enqueue('customer.register', payload);
}

export async function enqueueDailySummary(payload: {
  declared_cash: number;
  notes?: string;
}): Promise<string> {
  return enqueue('summary.submit', payload);
}

interface LocationPingPayload {
  latitude: number;
  longitude: number;
  accuracy?: number;
  recorded_at: string;
}

export async function enqueueLocationPings(pings: LocationPingPayload[]): Promise<string> {
  return enqueue('locations.record', { pings });
}

/**
 * Individual loan write-off has no direct HTTP route on the backend — it's
 * sync-only. Optionally draws a chosen amount down from one of the
 * customer's savings accounts first; only the residual is booked as a loss.
 */
export async function enqueueLoanWriteOff(payload: {
  loan_id: string;
  reason: string;
  savings_account_id?: string;
  savings_amount_applied?: number;
}): Promise<string> {
  return enqueue('loan.write_off', payload);
}
