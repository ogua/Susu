import { create } from 'zustand';

/**
 * Local duty flag. The backend has no "get current duty state" endpoint —
 * duty on/off is a fire-and-forget action (POST /agent/duty) that also
 * opens/closes today's day sheet server-side, so this is just a UI hint,
 * reset each app session (defaults to "on duty" so collections aren't
 * accidentally blocked after a restart).
 */
interface DutyState {
  onDuty: boolean;
  setOnDuty: (value: boolean) => void;
}

export const useDutyStore = create<DutyState>((set) => ({
  onDuty: true,
  setOnDuty: (value) => set({ onDuty: value }),
}));
