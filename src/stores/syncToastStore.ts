import { create } from 'zustand';

export type SyncToastTone = 'success' | 'warning';

interface SyncToastState {
  message: string | null;
  tone: SyncToastTone;
  show: (message: string, tone: SyncToastTone) => void;
  dismiss: () => void;
}

export const useSyncToastStore = create<SyncToastState>((set) => ({
  message: null,
  tone: 'success',
  show: (message, tone) => set({ message, tone }),
  dismiss: () => set({ message: null }),
}));
