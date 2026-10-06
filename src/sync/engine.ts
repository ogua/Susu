import * as Network from 'expo-network';

import { api } from '@/api/client';
import {
  bumpAttempt,
  markRejected,
  markSynced,
  pendingItems,
} from '@/sync/outbox';
import { useOutboxStatus } from '@/stores/outboxStatusStore';
import { useSyncToastStore } from '@/stores/syncToastStore';
import { errorLog } from '@/utils/debugLog';

/**
 * Outbox drain engine. Pushes queued ops to POST /api/v1/sync/batch and maps
 * each per-op result back onto the local queue. Server results are
 * per-operation: `applied` and `duplicate` both count as synced (duplicate =
 * we already pushed it in an earlier attempt), `rejected` is surfaced to the
 * user for review and never retried automatically.
 *
 * NOTE: the /sync/batch endpoint ships in Phase 1; until then a drain against
 * the backend returns 404 and items simply stay pending.
 */

export interface SyncSummary {
  pushed: number;
  applied: number;
  duplicates: number;
  rejected: number;
  skipped: 'offline' | 'empty' | 'in-flight' | null;
  error: string | null;
}

interface SyncOpResult {
  op_id: string;
  status: 'applied' | 'duplicate' | 'rejected';
  /** Op-specific outcome, e.g. { reference, balance } for a collection. */
  result?: Record<string, unknown> & { errors?: string[] };
}

let inFlight = false;

export async function drainOutbox(): Promise<SyncSummary> {
  const summary: SyncSummary = {
    pushed: 0,
    applied: 0,
    duplicates: 0,
    rejected: 0,
    skipped: null,
    error: null,
  };

  if (inFlight) {
    summary.skipped = 'in-flight';

    return summary;
  }

  const network = await Network.getNetworkStateAsync();
  if (!network.isConnected || !network.isInternetReachable) {
    summary.skipped = 'offline';

    return summary;
  }

  const items = await pendingItems();
  if (items.length === 0) {
    summary.skipped = 'empty';

    return summary;
  }

  inFlight = true;
  useOutboxStatus.getState().setSyncing(true);
  try {
    const { data } = await api.post<{ results: SyncOpResult[] }>('/sync/batch', {
      ops: items.map((item) => ({
        op_id: item.op_id,
        op_type: item.op_type,
        payload: JSON.parse(item.payload) as Record<string, unknown>,
        recorded_at: item.recorded_at,
      })),
    });

    summary.pushed = items.length;

    for (const result of data.results) {
      if (result.status === 'applied') {
        summary.applied += 1;
        await markSynced(result.op_id, result.result);
      } else if (result.status === 'duplicate') {
        summary.duplicates += 1;
        await markSynced(result.op_id, result.result);
      } else {
        summary.rejected += 1;
        errorLog('Sync', `Op ${result.op_id} rejected`, result);
        await markRejected(result.op_id, result.result?.errors?.join(' ') ?? 'Rejected by server.');
      }
    }
  } catch (error) {
    // Whole-batch transport failure: bump attempts, keep everything pending.
    const message = error instanceof Error ? error.message : 'Network error';
    summary.error = message;
    await Promise.all(items.map((item) => bumpAttempt(item.op_id, message)));
  } finally {
    inFlight = false;
    const status = useOutboxStatus.getState();
    status.setSyncing(false);
    if (!summary.error) {
      status.markSynced();
    }
    await status.refresh();
  }

  // Location pings drain every minute while on duty; a toast each time would
  // bury the ones agents need to see about their collections.
  if (!items.every((item) => item.op_type === 'locations.record')) {
    notify(summary);
  }

  return summary;
}

/** Surfaces the outcome of a real drain attempt (not a skip) as an app-wide
 * toast — the only feedback an agent gets for the silent background sync
 * triggered by `watchConnectivity`. */
function notify(summary: SyncSummary): void {
  if (summary.skipped) {
    return;
  }

  const { show } = useSyncToastStore.getState();

  if (summary.error) {
    show("Couldn't reach the server. Your records are safe on this phone and will sync automatically.", 'warning');

    return;
  }

  const synced = summary.applied + summary.duplicates;

  if (summary.rejected > 0) {
    show(
      `${synced} record${synced === 1 ? '' : 's'} synced. ${summary.rejected} need${summary.rejected === 1 ? 's' : ''} your attention in Sync.`,
      'warning',
    );

    return;
  }

  show(`${synced} record${synced === 1 ? '' : 's'} synced to the server.`, 'success');
}

/** Drain whenever connectivity returns. Returns an unsubscribe function. */
export function watchConnectivity(): () => void {
  const subscription = Network.addNetworkStateListener((state) => {
    if (state.isConnected && state.isInternetReachable) {
      void drainOutbox();
    }
  });

  return () => subscription.remove();
}
