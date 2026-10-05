import { create } from 'zustand';

import { pendingCount, rejectedCount } from '@/sync/outbox';

interface OutboxStatusState {
  pending: number;
  rejected: number;
  lastSyncedAt: string | null;
  syncing: boolean;
  refresh: () => Promise<void>;
  setSyncing: (syncing: boolean) => void;
  markSynced: () => void;
}

/**
 * App-wide view of the local outbox so every screen (dashboard pill, banner,
 * sync queue) agrees on "how many collections are still only on this phone".
 * Refreshed after every enqueue and every drain.
 */
export const useOutboxStatus = create<OutboxStatusState>((set) => ({
  pending: 0,
  rejected: 0,
  lastSyncedAt: null,
  syncing: false,

  refresh: async () => {
    try {
      const [pending, rejected] = await Promise.all([pendingCount(), rejectedCount()]);
      set({ pending, rejected });
    } catch {
      // DB not ready yet — keep previous counts.
    }
  },

  setSyncing: (syncing) => set({ syncing }),
  markSynced: () => set({ lastSyncedAt: new Date().toISOString() }),
}));
