import fs from 'fs'
import crypto from 'crypto'
import path from 'path'

/**
 * FileFingerprint — content fingerprint strategy that lets us avoid MD5
 * on the hot path.
 *
 * Future F.1 of drive-perf-fix-v2 (scaffold).
 *
 * Strategy:
 *
 *   1. **Identity**: (size, mtime_ms, ino on POSIX / file-id on Windows
 *      where available). If both files in a comparison have the same
 *      identity, they are byte-equal with very high confidence and we
 *      skip hashing entirely.
 *   2. **Quick hash**: SHA-1 over the first 64 KiB and last 64 KiB. For
 *      most office documents and source files this distinguishes content
 *      changes within a couple of milliseconds even on a slow NAS. SHA-1
 *      is used as a transitional choice — swap for xxh3 by adding the
 *      `xxhash-addon` dependency and replacing `quickHash`.
 *   3. **Full hash**: streaming SHA-1 over the entire file. Only invoked
 *      on conflict (same size+mtime but different ETag, or quick hash
 *      collision — extremely rare in practice).
 *
 * Returns a structured fingerprint that the sync engine can persist in a
 * `FileFingerprint` SQLite table:
 *
 *   (remote_id PRIMARY KEY,
 *    size INTEGER, mtime_ms INTEGER, ino INTEGER,
 *    quick_hash TEXT, full_hash TEXT,
 *    last_verified_at INTEGER)
 *
 * The legacy `lastKnownServerChecksum` (MD5) column stays as a
 * transitional value until the server emits xxh3 fingerprints natively.
 */

export interface FingerprintIdentity {
  size: number
  mtimeMs: number
  ino: number | null
}

export interface FileFingerprint {
  identity: FingerprintIdentity
  quickHash: string | null
  fullHash: string | null
  computedAt: number
}

const QUICK_HASH_BLOCK = 64 * 1024 // 64 KiB head + 64 KiB tail

/**
 * Read the cheap part of a fingerprint — just the OS-level identity.
 * O(1), single fs.stat call. Use this every sync cycle.
 */
export async function readIdentity(filePath: string): Promise<FingerprintIdentity | null> {
  try {
    const st = await fs.promises.stat(filePath)
    return {
      size: st.size,
      mtimeMs: Math.floor(st.mtimeMs),
      ino: typeof st.ino === 'number' ? st.ino : null,
    }
  } catch {
    return null
  }
}

export function identitiesMatch(a: FingerprintIdentity | null, b: FingerprintIdentity | null): boolean {
  if (!a || !b) return false
  if (a.size !== b.size) return false
  if (a.mtimeMs !== b.mtimeMs) return false
  // ino mismatches are tolerated when one side comes from the server
  // (where we don't have an inode). Only enforce when both sides have it.
  if (a.ino != null && b.ino != null && a.ino !== b.ino) return false
  return true
}

/**
 * Quick hash — SHA-1 of first 64 KiB + last 64 KiB. Distinguishes most
 * content changes without reading the whole file.
 */
export async function quickHash(filePath: string, sizeHint?: number): Promise<string | null> {
  let fd: fs.promises.FileHandle | null = null
  try {
    const size = sizeHint ?? (await fs.promises.stat(filePath)).size
    fd = await fs.promises.open(filePath, 'r')

    const hash = crypto.createHash('sha1')
    if (size <= QUICK_HASH_BLOCK * 2) {
      // Small enough to read in one go — same as a full hash.
      const buf = Buffer.alloc(size)
      await fd.read(buf, 0, size, 0)
      hash.update(buf)
      return hash.digest('hex')
    }

    const head = Buffer.alloc(QUICK_HASH_BLOCK)
    const tail = Buffer.alloc(QUICK_HASH_BLOCK)
    await fd.read(head, 0, QUICK_HASH_BLOCK, 0)
    await fd.read(tail, 0, QUICK_HASH_BLOCK, size - QUICK_HASH_BLOCK)
    hash.update(head)
    hash.update(Buffer.from([0])) // separator so head+tail of size n with all-zeros doesn't collide with a ~2n file
    hash.update(tail)
    return hash.digest('hex')
  } catch {
    return null
  } finally {
    if (fd) await fd.close().catch(() => undefined)
  }
}

/**
 * Full streaming SHA-1. Only invoke on conflict.
 */
export async function fullHash(filePath: string): Promise<string | null> {
  return new Promise((resolve) => {
    const hash = crypto.createHash('sha1')
    const stream = fs.createReadStream(filePath, { highWaterMark: 1024 * 1024 })
    stream.on('data', (chunk) => hash.update(chunk))
    stream.on('end', () => resolve(hash.digest('hex')))
    stream.on('error', () => resolve(null))
  })
}

/**
 * One-shot fingerprint. Reads identity, computes quick hash, and skips
 * the full hash unless `forceFull` is true. The caller decides whether
 * to escalate to full based on identity / quick comparison.
 */
export async function fingerprint(
  filePath: string,
  opts: { forceFull?: boolean } = {}
): Promise<FileFingerprint | null> {
  const identity = await readIdentity(filePath)
  if (!identity) return null
  const quick = await quickHash(filePath, identity.size)
  const full = opts.forceFull ? await fullHash(filePath) : null
  return {
    identity,
    quickHash: quick,
    fullHash: full,
    computedAt: Date.now(),
  }
}

/**
 * SQL DDL for the new fingerprint table. Apply behind a schema version
 * bump in the better-sqlite3 schema.ts when adopting F.1.
 */
export const FINGERPRINT_TABLE_DDL = `
  CREATE TABLE IF NOT EXISTS file_fingerprints (
    remote_id        INTEGER PRIMARY KEY,
    size             INTEGER NOT NULL,
    mtime_ms         INTEGER NOT NULL,
    ino              INTEGER,
    quick_hash       TEXT,
    full_hash        TEXT,
    last_verified_at INTEGER NOT NULL
  );
  CREATE INDEX IF NOT EXISTS idx_fingerprint_quick ON file_fingerprints(quick_hash);
`

// Type-only export to silence unused-warnings until the sync engine
// adopts the table. `path` import retained for future use when we add
// path-based caching.
const _unused = path
void _unused
