import type { Database as SqliteDatabase } from 'better-sqlite3'

/**
 * SQLite schema for FlowOneDrive — Wave B.1.
 *
 * The shape mirrors the original electron-store JSON schema so callers don't
 * change. Every table has a stable surrogate `id` plus a unique business key
 * (`remote_id` for files/folders) and indexed columns for the lookup paths
 * the sync engine actually uses.
 *
 * Why this layout:
 *   - `local_path_norm` is a denormalized lower-cased / forward-slash variant
 *     of `local_path`. The sync engine looks up files by case-insensitive
 *     path on Windows; storing the normalized form lets us hit a B-tree index
 *     instead of doing a full-table scan on every event.
 *   - `meta` holds opaque key/value pairs (migration version, last cycle
 *     time, etc).
 *
 * Migrations:
 *   - `migration_version` row in `meta` records the schema version.
 *   - Schema is created idempotently with `CREATE TABLE IF NOT EXISTS` so
 *     re-init on existing databases is a no-op.
 *   - Future schema changes append to `applyMigrations()` keyed off
 *     `migration_version`.
 */

export const SCHEMA_VERSION = 1

export function applySchema(db: SqliteDatabase): void {
  db.pragma('journal_mode = WAL')
  db.pragma('synchronous = NORMAL')
  db.pragma('temp_store = MEMORY')
  db.pragma('foreign_keys = ON')

  db.exec(`
    CREATE TABLE IF NOT EXISTS meta (
      key   TEXT PRIMARY KEY,
      value TEXT
    );

    CREATE TABLE IF NOT EXISTS files (
      id                          INTEGER PRIMARY KEY AUTOINCREMENT,
      remote_id                   INTEGER NOT NULL UNIQUE,
      remote_folder_id            INTEGER,
      local_path                  TEXT NOT NULL,
      local_path_norm             TEXT NOT NULL,
      filename                    TEXT NOT NULL,
      checksum                    TEXT NOT NULL DEFAULT '',
      size                        INTEGER NOT NULL DEFAULT 0,
      mime_type                   TEXT NOT NULL DEFAULT 'application/octet-stream',
      remote_updated_at           TEXT NOT NULL DEFAULT '',
      local_updated_at            TEXT NOT NULL DEFAULT '',
      sync_status                 TEXT NOT NULL DEFAULT 'synced',
      last_sync_at                TEXT,
      is_public                   INTEGER NOT NULL DEFAULT 0,
      public_token                TEXT,
      has_public_link             INTEGER NOT NULL DEFAULT 0,
      share_link                  TEXT,
      nas_relative_path           TEXT,
      storage_location            TEXT NOT NULL DEFAULT 'unknown',
      last_known_server_checksum  TEXT
    );
    CREATE INDEX IF NOT EXISTS files_remote_folder_id ON files(remote_folder_id);
    CREATE INDEX IF NOT EXISTS files_local_path_norm  ON files(local_path_norm);
    CREATE INDEX IF NOT EXISTS files_sync_status      ON files(sync_status);

    CREATE TABLE IF NOT EXISTS folders (
      id                INTEGER PRIMARY KEY AUTOINCREMENT,
      remote_id         INTEGER NOT NULL UNIQUE,
      remote_parent_id  INTEGER,
      local_path        TEXT NOT NULL DEFAULT '',
      local_path_norm   TEXT NOT NULL DEFAULT '',
      name              TEXT NOT NULL DEFAULT '',
      sync_status       TEXT NOT NULL DEFAULT 'synced',
      last_sync_at      TEXT,
      is_public         INTEGER NOT NULL DEFAULT 0,
      public_token      TEXT,
      has_public_link   INTEGER NOT NULL DEFAULT 0,
      share_link        TEXT,
      color             TEXT,
      nas_relative_path TEXT
    );
    CREATE INDEX IF NOT EXISTS folders_remote_parent_id ON folders(remote_parent_id);
    CREATE INDEX IF NOT EXISTS folders_local_path_norm  ON folders(local_path_norm);

    CREATE TABLE IF NOT EXISTS logs (
      id          INTEGER PRIMARY KEY AUTOINCREMENT,
      action      TEXT NOT NULL,
      item_type   TEXT NOT NULL,
      item_id     INTEGER,
      item_name   TEXT NOT NULL,
      status      TEXT NOT NULL,
      message     TEXT,
      created_at  TEXT NOT NULL,
      access_mode TEXT
    );
    CREATE INDEX IF NOT EXISTS logs_created_at ON logs(created_at);

    CREATE TABLE IF NOT EXISTS offline_operations (
      id          INTEGER PRIMARY KEY AUTOINCREMENT,
      type        TEXT NOT NULL,
      local_path  TEXT NOT NULL,
      remote_path TEXT,
      remote_id   INTEGER,
      folder_id   INTEGER,
      filename    TEXT,
      checksum    TEXT,
      size        INTEGER,
      mime_type   TEXT,
      created_at  TEXT NOT NULL,
      status      TEXT NOT NULL DEFAULT 'pending',
      retries     INTEGER NOT NULL DEFAULT 0,
      last_error  TEXT
    );
    CREATE INDEX IF NOT EXISTS offline_ops_status ON offline_operations(status);
  `)

  // Set / verify migration version.
  const row = db.prepare(`SELECT value FROM meta WHERE key = 'migration_version'`).get() as
    | { value: string }
    | undefined

  if (!row) {
    db.prepare(`INSERT INTO meta(key, value) VALUES ('migration_version', ?)`)
      .run(String(SCHEMA_VERSION))
  } else if (Number(row.value) < SCHEMA_VERSION) {
    applyMigrations(db, Number(row.value))
    db.prepare(`UPDATE meta SET value = ? WHERE key = 'migration_version'`)
      .run(String(SCHEMA_VERSION))
  }
}

function applyMigrations(_db: SqliteDatabase, _from: number): void {
  // Future schema changes go here. Each block guarded by `_from < N`.
  // Keep this function below ~50 lines per block; split into separate files
  // (e.g. db/migrations/002_xxx.ts) once it grows beyond that.
}

/**
 * Path normalization used both at write time (column population) and at
 * read time (lookup parameter). Mirrors `Database.normalizePath` from the
 * legacy electron-store implementation.
 */
export function normalizePath(p: string): string {
  return (p || '').toLowerCase().replace(/\\/g, '/').replace(/\/+$/, '')
}
