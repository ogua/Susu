import { api } from '@/api/client';
import type { CollectionSheetRow } from '@/types/api';

/**
 * "Enter Transaction": who is due on a date. Field agents without a group get
 * their own sheet (the server forces the officer to them).
 */
export async function getCollectionSheet(params: { date?: string; loan_group_id?: string }): Promise<CollectionSheetRow[]> {
  const { data } = await api.get<{ data: CollectionSheetRow[] }>('/collection-sheet', { params });

  return data.data;
}
