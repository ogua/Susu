import * as Crypto from 'expo-crypto';

import { getDb } from '@/db/database';

export type OutboxStatus = 'pending' | 'synced' | 'rejected';

export interface OutboxItem {
  op_id: string;
  op_type: string;
  payload: string;
  recorded_at: string;
  status: OutboxStatus;
  attempts: number;
  last_error: string | null;
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
    `INSERT INTO outbox (op_id, op_type, payload, recorded_at) VALUES (?, ?, ?, ?)`,
    opId,
    opType,
    JSON.stringify({ ...payload, client_reference: opId }),
    new Date().toISOString(),
  );

  return opId;
}

export async function pendingItems(limit = 100): Promise<OutboxItem[]> {
  const db = await getDb();

  return db.getAllAsync<OutboxItem>(
    `SELECT * FROM outbox WHERE status = 'pending' ORDER BY created_at LIMIT ?`,
    limit,
  );
}

export async function pendingCount(): Promise<number> {
  const db = await getDb();
  const row = await db.getFirstAsync<{ n: number }>(
    `SELECT COUNT(*) AS n FROM outbox WHERE status = 'pending'`,
  );

  return row?.n ?? 0;
}

export async function markSynced(opId: string): Promise<void> {
  const db = await getDb();
  await db.runAsync(`UPDATE outbox SET status = 'synced' WHERE op_id = ?`, opId);
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
