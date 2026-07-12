import { api } from '@/api/client';

export async function setDuty(onDuty: boolean): Promise<boolean> {
  const { data } = await api.post<{ on_duty: boolean }>('/agent/duty', { on_duty: onDuty });

  return data.on_duty;
}
