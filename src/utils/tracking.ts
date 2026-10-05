import type { AgentTrackingStatus } from '@/types/api';

export const TRACKING_STATUS_LABELS: Record<AgentTrackingStatus, string> = {
  active: 'Active',
  stale: 'No recent signal',
  off_duty: 'Off duty',
};

/** Same colours as the web tracking map's status dots. */
export const TRACKING_STATUS_COLORS: Record<AgentTrackingStatus, string> = {
  active: '#22c55e',
  stale: '#f59e0b',
  off_duty: '#9ca3af',
};

/** Local calendar date as YYYY-MM-DD (the API's `date` format). */
export function isoDate(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function shiftIsoDate(iso: string, days: number): string {
  const [year, month, day] = iso.split('-').map(Number);

  return isoDate(new Date(year, month - 1, day + days));
}

export function formatIsoDate(iso: string): string {
  const [year, month, day] = iso.split('-').map(Number);

  return new Date(year, month - 1, day).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
}

export function formatDuration(minutes: number): string {
  return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}
