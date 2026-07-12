import { api } from '@/api/client';
import type { AgentDailySummary } from '@/types/api';

export interface TodaySummary {
  summary: AgentDailySummary;
  cash_in_hand: number;
}

export async function getTodaySummary(): Promise<TodaySummary> {
  const { data } = await api.get<TodaySummary>('/agent/summary/today');

  return data;
}
