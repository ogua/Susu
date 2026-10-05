import * as Crypto from 'expo-crypto';

import { getDb } from '@/db/database';
import { useAuthStore } from '@/stores/authStore';

function currentActorId(): string | null {
  return useAuthStore.getState().user?.id ?? null;
}

/** Ops this user may see/drain: their own, plus legacy rows with no owner. */
const MINE = `(actor_id = ? OR actor_id IS NULL)`;

export type OutboxStatus = 'pending' | 'synced' | 'rejected';

export interface OutboxItem {
  op_id: string;
  op_type: string;
  payload: string;
  recorded_at: string;
  status: OutboxStatus;
  actor_id: string | null;
  attempts: number;
  last_error: string | null;
  /** JSON of the server's per-op result once synced (schema v2). */
  result: string | null;
}

/**
 * Queue an operation locally. This is the write path for everything an agent
 * does offline — the op gets a client-generated UUID (its idempotency key on
 * the server) and a device timestamp before it ever leaves the phone.
 */
export async function enqueue(opType: string, payload: Record<string, unknown>): Promise<string> {
  const db = await getDb();
  const opId = Crypto.randomUUID();

  await db.runAsync(
    `INSERT INTO outbox (op_id, op_type, payload, recorded_at, actor_id) VALUES (?, ?, ?, ?, ?)`,
    opId,
    opType,
    JSON.stringify({ ...payload, client_reference: opId }),
    new Date().toISOString(),
    currentActorId(),
  );

  return opId;
}

export async function pendingItems(limit = 100): Promise<OutboxItem[]> {
  const db = await getDb();

  return db.getAllAsync<OutboxItem>(
    `SELECT * FROM outbox WHERE status = 'pending' AND ${MINE} ORDER BY created_at LIMIT ?`,
    currentActorId(),
    limit,
  );
}

export async function pendingCount(): Promise<number> {
  const db = await getDb();
  const row = await db.getFirstAsync<{ n: number }>(
    `SELECT COUNT(*) AS n FROM outbox WHERE status = 'pending' AND ${MINE}`,
    currentActorId(),
  );

  return row?.n ?? 0;
}

export async function rejectedCount(): Promise<number> {
  const db = await getDb();
  const row = await db.getFirstAsync<{ n: number }>(
    `SELECT COUNT(*) AS n FROM outbox WHERE status = 'rejected' AND ${MINE}`,
    currentActorId(),
  );

  return row?.n ?? 0;
}

export async function getOutboxItem(opId: string): Promise<OutboxItem | null> {
  const db = await getDb();

  return db.getFirstAsync<OutboxItem>(`SELECT * FROM outbox WHERE op_id = ?`, opId);
}

/** Most recent ops for the Sync screen, scoped to the signed-in user. */
export async function recentItems(limit = 200): Promise<OutboxItem[]> {
  const db = await getDb();

  return db.getAllAsync<OutboxItem>(
    `SELECT * FROM outbox WHERE ${MINE} ORDER BY created_at DESC LIMIT ?`,
    currentActorId(),
    limit,
  );
}

export async function markSynced(opId: string, result?: Record<string, unknown> | null): Promise<void> {
  const db = await getDb();
  await db.runAsync(
    `UPDATE outbox SET status = 'synced', result = ? WHERE op_id = ?`,
    result ? JSON.stringify(result) : null,
    opId,
  );
}

export async function markRejected(opId: string, error: string): Promise<void> {
  const db = await getDb();
  await db.runAsync(
    `UPDATE outbox SET status = 'rejected', last_error = ?, attempts = attempts + 1 WHERE op_id = ?`,
    error,
    opId,
  );
}

export async function bumpAttempt(opId: string, error: string): Promise<void> {
  const db = await getDb();
  await db.runAsync(
    `UPDATE outbox SET attempts = attempts + 1, last_error = ? WHERE op_id = ?`,
    error,
    opId,
  );
}

export async function retryRejected(opId: string): Promise<void> {
  const db = await getDb();
  await db.runAsync(
    `UPDATE outbox SET status = 'pending', last_error = NULL WHERE op_id = ? AND status = 'rejected'`,
    opId,
  );
}
