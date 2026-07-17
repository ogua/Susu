import { api } from '@/api/client';

export interface TrendPoint {
  date: string;
  total: number;
  count: number;
}

export interface AgentDashboard {
  today: {
    collections_total: number;
    collections_count: number;
    expected_cash: number;
    variance: number;
    summary_status: string | null;
  };
  accounts: { active_count: number };
  trend: TrendPoint[];
}

export interface CustomerDashboardAccount {
  id: string;
  account_number: string;
  product: string | null;
  balance: number;
  status: string;
  target_progress_percent: number | null;
}

export interface CustomerDashboard {
  accounts: CustomerDashboardAccount[];
  totals: { balance: number };
  balance_trend: { date: string; balance: number }[];
  loans: { active_count: number; outstanding: number };
}

export async function getAgentDashboard(): Promise<AgentDashboard> {
  const { data } = await api.get<AgentDashboard>('/dashboard/agent');

  return data;
}

export async function getCustomerDashboard(): Promise<CustomerDashboard> {
  const { data } = await api.get<CustomerDashboard>('/dashboard/customer');

  return data;
}
