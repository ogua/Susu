import { api } from '@/api/client';
import type { Customer } from '@/types/api';

export async function getCustomer(id: string): Promise<Customer> {
  const { data } = await api.get<{ data: Customer }>(`/agent/customers/${id}`);

  return data.data;
}
