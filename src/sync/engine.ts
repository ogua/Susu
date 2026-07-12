import * as Network from 'expo-network';

import { api } from '@/api/client';
import {
  bumpAttempt,
  markRejected,
  markSynced,
  pendingItems,
} from '@/sync/outbox';

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
}

interface SyncOpResult {
  op_id: string;
  status: 'applied' | 'duplicate' | 'rejected';
  errors?: string[];
}

let inFlight = false;

export async function drainOutbox(): Promise<SyncSummary> {
  const summary: SyncSummary = { pushed: 0, applied: 0, duplicates: 0, rejected: 0, skipped: null };

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
        await markSynced(result.op_id);
      } else if (result.status === 'duplicate') {
        summary.duplicates += 1;
        await markSynced(result.op_id);
      } else {
        summary.rejected += 1;
        await markRejected(result.op_id, result.errors?.join(' ') ?? 'Rejected by server.');
      }
    }
  } catch (error) {
    // Whole-batch transport failure: bump attempts, keep everything pending.
    const message = error instanceof Error ? error.message : 'Network error';
    await Promise.all(items.map((item) => bumpAttempt(item.op_id, message)));
  } finally {
    inFlight = false;
  }

  return summary;
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
