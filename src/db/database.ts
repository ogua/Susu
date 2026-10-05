import * as SQLite from 'expo-sqlite';

/**
 * Local database bootstrap.
 *
 * Phase 0 ships the outbox/sync plumbing on expo-sqlite (runs everywhere,
 * including Expo Go). Phase 1 adds WatermelonDB mirrored read tables
 * (customers, accounts) on the dev build; the sync engine only talks to the
 * functions exported here, so the storage engine can evolve underneath it.
 */

const DB_NAME = 'susuapp.db';
const SCHEMA_VERSION = 2;

let dbPromise: Promise<SQLite.SQLiteDatabase> | null = null;

export function getDb(): Promise<SQLite.SQLiteDatabase> {
  dbPromise ??= openAndMigrate();

  return dbPromise;
}

async function openAndMigrate(): Promise<SQLite.SQLiteDatabase> {
  const db = await SQLite.openDatabaseAsync(DB_NAME);

  await db.execAsync('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;');

  const row = await db.getFirstAsync<{ user_version: number }>('PRAGMA user_version');
  const current = row?.user_version ?? 0;

  if (current < 1) {
    await db.execAsync(`
      CREATE TABLE IF NOT EXISTS outbox (
        op_id TEXT PRIMARY KEY,
        op_type TEXT NOT NULL,
        payload TEXT NOT NULL,
        recorded_at TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        attempts INTEGER NOT NULL DEFAULT 0,
        last_error TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_outbox_status ON outbox (status, created_at);

      CREATE TABLE IF NOT EXISTS sync_state (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
      );
    `);
  }

  if (current < 2) {
    // Server's per-op result (e.g. a collection's ledger reference and the
    // account balance after it), shown on the receipt once synced.
    await db.execAsync(`ALTER TABLE outbox ADD COLUMN result TEXT;`);
  }

  if (current !== SCHEMA_VERSION) {
    await db.execAsync(`PRAGMA user_version = ${SCHEMA_VERSION}`);
  }

  return db;
}
