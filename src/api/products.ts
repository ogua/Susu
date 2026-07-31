import { api } from '@/api/client';
import type { SavingsProduct } from '@/types/api';

/**
 * Reuses the sync bootstrap endpoint (already pulled by desktop hybrid mode
 * to mirror the product catalogue) rather than a dedicated products
 * endpoint — it already returns the full active catalogue, gated to the
 * same field_agent|branch_manager|company_admin role tier this screen
 * requires.
 */
export async function getSavingsProducts(): Promise<SavingsProduct[]> {
  const { data } = await api.get<{ products: SavingsProduct[] }>('/sync/bootstrap');

  return data.products;
}
