import { api } from '@/api/client';
import type { CollectionSheetRow } from '@/types/api';

export type CollectionSheetParams = {
  date?: string;
  loan_group_id?: string;
  /** Every customer (not just those due), one row per open loan or a savings-only row. */
  all_customers?: boolean;
  /** Name, phone, customer code, loan/account number or group name. */
  search?: string;
};

export type CollectionSheet = {
  rows: CollectionSheetRow[];
  /** "All customers" returns at most this many customers; search to narrow. */
  customerLimit: number;
};

/**
 * "Enter Transaction": who is due on a date. Field agents only ever get their
 * own customers (the server forces the officer to them, groups included).
 */
export async function getCollectionSheet(params: CollectionSheetParams): Promise<CollectionSheet> {
  const { data } = await api.get<{ data: CollectionSheetRow[]; meta: { customer_limit: number } }>('/collection-sheet', {
    params: { ...params, all_customers: params.all_customers ? 1 : undefined },
  });

  return { rows: data.data, customerLimit: data.meta.customer_limit };
}
