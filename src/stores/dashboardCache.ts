import Storage from 'expo-sqlite/kv-store';
import { create } from 'zustand';

import type { AgentDashboard, CustomerDashboard } from '@/api/dashboard';

const AGENT_KEY = 'susu.dashboard.agent';
const CUSTOMER_KEY = 'susu.dashboard.customer';

interface Snapshot<T> {
  data: T;
  fetchedAt: string;
}

interface DashboardCacheState {
  hydrated: boolean;
  agent: Snapshot<AgentDashboard> | null;
  customer: Snapshot<CustomerDashboard> | null;
  hydrate: () => Promise<void>;
  saveAgent: (data: AgentDashboard) => Promise<void>;
  saveCustomer: (data: CustomerDashboard) => Promise<void>;
}

/**
 * Last-known dashboard payloads so the home screens (stats + charts) still
 * render in the field with no connectivity. Not SecureStore: these are not
 * secrets and trend arrays can exceed its per-key size limits.
 */
export const useDashboardCache = create<DashboardCacheState>((set) => ({
  hydrated: false,
  agent: null,
  customer: null,

  hydrate: async () => {
    try {
      const [agentRaw, customerRaw] = await Promise.all([
        Storage.getItem(AGENT_KEY),
        Storage.getItem(CUSTOMER_KEY),
      ]);
      set({
        hydrated: true,
        agent: agentRaw ? (JSON.parse(agentRaw) as Snapshot<AgentDashboard>) : null,
        customer: customerRaw ? (JSON.parse(customerRaw) as Snapshot<CustomerDashboard>) : null,
      });
    } catch {
      set({ hydrated: true });
    }
  },

  saveAgent: async (data) => {
    const snapshot: Snapshot<AgentDashboard> = { data, fetchedAt: new Date().toISOString() };
    set({ agent: snapshot });
    await Storage.setItem(AGENT_KEY, JSON.stringify(snapshot)).catch(() => undefined);
  },

  saveCustomer: async (data) => {
    const snapshot: Snapshot<CustomerDashboard> = { data, fetchedAt: new Date().toISOString() };
    set({ customer: snapshot });
    await Storage.setItem(CUSTOMER_KEY, JSON.stringify(snapshot)).catch(() => undefined);
  },
}));
