# FlowOne Storage Architectural Invariants

These are the non-negotiable safety properties of the FlowOne storage system. They are the **contract**. Everything else in the codebase is an implementation detail in service of these rules.

Each invariant is:

1. **Documented here** with rationale and enforcement location.
2. **Asserted at runtime** by a method in [`FlowOne\Storage\Invariants`](../src/Storage/Invariants.php). A failed assertion logs a structured violation to the operation journal AND throws an `InvariantViolation`. Production callers wrap with try/catch where appropriate, but the goal is to fail loudly during development and chaos testing.
3. **Covered by at least one chaos scenario** under [`tests/chaos/`](../tests/chaos/). The chaos harness includes `tests/chaos/lib/AssertInvariant.php` which verifies the named invariant held throughout a scenario. CI fails the build if any documented invariant lacks an assertion mapping AND at least one chaos scenario.

**Future contributors: read this file before touching any storage code.** Most of the "weird code" you will see is enforcing one of these invariants. Removing the weirdness without removing the invariant is how data loss happens.

---

## Durability invariants

### I-1: Hot deletion preconditions

A hot-tier copy of a file may only be deleted (reclaimed) if **all** of the following hold simultaneously:

- `cold_present = 1` in `drive_files`
- `tier_state = 'cold'` in `drive_files`
- `cold_xxhash64` is not NULL
- The cold copy was re-verified within the current reclaim run (read + xxhash match)
- The reclaim stability gate (see [Stability gate](#stability-gate)) has been continuously passing for at least 60 seconds
- The daemon `boot_epoch` has not changed since the stability gate started

Enforced in `email/backend/cron/reclaim-hot-tier.php` immediately before every `unlink`, via `Invariants::assertHotDeletionPreconditions(...)`.

**Why it matters**: a deleted hot file with no verified cold counterpart is data loss. The stability gate ensures we do not delete during a flapping NAS, and the boot epoch ensures a daemon restart cannot inherit a stale "stable" verdict.

### I-2: No half-states observable

A reader must never see a file row with `hot_present = 0` AND `cold_present = 0` UNLESS `tier_state = 'missing'`. The tier-down worker uses a two-phase commit (write cold + verify + flip flags + only then schedule hot reclaim) to prevent this.

Enforced by `Invariants::assertNoHalfState($fileRow)` in `DriveService::resolveFilePath()` and by a daily integrity audit cron.

### I-3: Cold authority requires verification

A cold copy is only authoritative for reads if `cold_xxhash64` has been verified at least once since it was written. `tier_state = 'unverified'` files are NOT authoritative; reads of unverified cold files trigger a re-verification before serving.

Enforced in `DriveService::resolveFilePath()` via `Invariants::assertColdAuthoritative(...)`.

### I-4: Monotonic tier transitions

`tier_state` transitions must follow the allowed graph:

```
hot       -> tiering | corrupt | missing
tiering   -> unverified | corrupt | missing | hot           (rollback to hot allowed)
unverified -> cold | corrupt | missing
cold      -> restoring | corrupt | missing
restoring -> hot | corrupt | missing | cold                 (rollback to cold allowed)
corrupt   -> hot | cold        (only via operator action)
missing   -> hot | cold        (only via operator action)
```

Reverse or skipped transitions are bugs. Enforced by a DB trigger (added in migration 167) AND by `Invariants::assertTransitionAllowed($from, $to)` in every code path that updates `tier_state`.

### I-5: No silent tier_state mutations

Every transition writes an entry to the operation journal AND updates `last_tier_change` to NOW(). Any code path that mutates `tier_state` without doing both is a bug.

Enforced by a static analysis grep in CI (`tier_state\s*=` must appear inside a function that also calls `$journal->record(...)`) and by `Invariants::assertJournaledChange($before, $after, $journalEntry)`.

---

## Request-path invariants

### I-6: Request path never blocks on storage operations

No HTTP request thread may block waiting on:

- Tier-up (cold-to-hot restore)
- Mount, umount, or remount operations
- Recovery actions (VPN restart, NFS remount)
- Restore queue processing
- Any helper RPC

Reads of cold files either stream directly through the kernel page cache (no application-layer wait) or return immediately with a queued background restore and a "warming up" hint. Uploads either accept-to-hot synchronously or return HTTP 503 via admission control.

Enforced by an instrumentation hook in `BaseController` that times every storage-related method call. Calls taking more than 2 seconds log a structured violation. Strict mode (`STORAGE_STRICT_INVARIANTS=1` in `.env`) throws.

### I-7: Admission is centralized

`AdmissionController::canAcceptUpload($tenant, $size)` is the SINGLE authority on whether an upload may proceed. Any upload code path that does not call it first is a bug.

Caught by a CI grep: `move_uploaded_file` / `file_put_contents` calls inside `email/backend/src/Services/DriveService.php` or any controller that writes to drive storage MUST be preceded by an admission check in the same function.

### I-8: No request thread executes privileged actions

`mount`, `umount`, `systemctl`, `nft` are NEVER invoked from a request thread. Only the daemon (via the helper Unix socket) may invoke them.

Enforced by the helper's `SO_PEERCRED` check, which rejects any caller whose UID/GID does not match the configured daemon user/group.

---

## State / trust invariants

### I-9: HMAC required for state consumption

Any state payload (file or Redis) must verify against the HMAC secret in `/etc/flowone/state.key` before being trusted. Unverified payloads are discarded and the next fallback source is consulted (Redis -> current file -> backup file -> hard-coded safe defaults).

Enforced in `StorageHealth::getStatus()` via `Invariants::assertSignedPayload($payload)`.

### I-10: Boot-epoch invalidates queued actions

Any queued action (`drive_tier_jobs.boot_epoch`, recovery action, in-flight restore) carrying a `boot_epoch` different from the daemon's current epoch is cancelled, NOT executed.

Enforced by:
- The job reaper (`email/backend/cron/reap-tier-jobs.php`), which cancels jobs with stale epochs every minute.
- The helper's pre-action check, which refuses any action whose embedded epoch does not match its own snapshot.

### I-11: MountLock for ALL mount-table mutations

Any `mount`, `umount`, `umount -l`, or remount operation, no matter who initiates it (daemon, helper, backup-runner, ops manual command), MUST acquire `MountLock` (flock on `/var/lock/flowone-mount.lock`) first.

Even one bypass risks racing the daemon's fingerprint probe and corrupting the published state. Operator runbooks document this explicitly. The helper refuses to execute mount operations without holding the lock.

### I-12: Freeze flag is non-bypassable for writes

While `/var/lib/flowone/freeze.flag` exists, NO write, move, or delete operation in any worker may begin. In-flight operations finish; new work refuses with a structured "frozen" response. Reads are unaffected.

Checked at:
- The top of every worker loop iteration.
- Immediately before every destructive operation (not just at startup).
- The admission controller (returns CRITICAL mode when frozen).

### I-13: Generation strictly monotonic per epoch

Within a single `boot_epoch`, the `generation` counter only ever increases. Clients reject older generations from the same epoch as stale.

New epochs reset the counter to 1.

---

## Recovery invariants

### I-14: Bounded recovery storms

No recovery action may attempt itself more than 5 times per quarantine window per action type. After 3 quarantine windows in a 24-hour period, the recovery breaker enters **permanent open**. Clearing permanent open requires `storage-ctl reset-breaker --confirm` from an operator.

No code path may auto-clear a permanent breaker.

Enforced in `CircuitBreaker::recordAttempt()` and `CircuitBreaker::canAttempt()`.

### I-15: Read breaker hard cap

The read circuit breaker may not remain open longer than 10 minutes without admin action. After that, status promotes to `quarantined` and requires explicit `storage-ctl reset-breaker --read` to resume.

This prevents the system from silently degrading to read-only forever because of a brief NAS slow-down that recovered but was never observed.

---

## Cross-references

| Invariant | Asserted in | Chaos scenario(s) |
|---|---|---|
| I-1  | `reclaim-hot-tier.php` | `flap_during_reclaim`, `silent_bit_flip`, `wrong_db_xxhash`, `hot_cold_size_mismatch` |
| I-2  | `DriveService::resolveFilePath`, integrity audit | `kill_minus_9_mid_job`, `partial_rsync_write` |
| I-3  | `DriveService::resolveFilePath` | `silent_bit_flip`, `altered_sidecar` |
| I-4  | DB trigger + all `tier_state` writers | `kill_minus_9_mid_job`, `daemon_restart` |
| I-5  | CI grep + `Invariants::assertJournaledChange` | (CI-only) |
| I-6  | `BaseController` instrumentation | `slow_reads`, `restore_storm`, `helper_rpc_timeout` |
| I-7  | CI grep + admission controller | (CI-only) + `freeze_during_load` |
| I-8  | helper `SO_PEERCRED` | `helper_rpc_timeout`, attempted-bypass test |
| I-9  | `StorageHealth::getStatus` | `redis_loss`, `json_corruption` |
| I-10 | Job reaper + helper pre-action check | `daemon_restart`, `kill_minus_9_mid_job` |
| I-11 | `MountLock`; helper refuses without lock | `stale_mount`, `readonly_remount` |
| I-12 | Worker loops + admission + destructive ops | `freeze_during_load` |
| I-13 | `StorageHealth::getStatus` | `daemon_restart`, `split_network` |
| I-14 | `CircuitBreaker` | `vpn_drop` repeated, `helper_rpc_timeout` |
| I-15 | `CircuitBreaker` read variant | `slow_reads` sustained |

---

## Stability gate

The stability gate is the set of conditions that must hold for the reclaim daemon to permit ANY hot-tier deletion. It is checked once per reclaim batch AND re-checked immediately before every individual `unlink`.

All six conditions must hold:

1. `StorageHealth::getStatus()->status === 'healthy'`
2. `StorageHealth::getStatus()->generation` unchanged for the last 60 seconds (no flap)
3. `StorageHealth::getStatus()->boot_epoch` unchanged for the last 60 seconds (no daemon restart)
4. Recovery circuit breaker is closed
5. Read circuit breaker is closed
6. `/var/lib/flowone/freeze.flag` does not exist

The gate is implemented in `Invariants::stabilityGateOpen(StorageHealth $health): bool`. The reclaim worker calls it twice per file (batch-level and per-file). Future contributors who want to add an "exception" to this gate should add a new invariant first and document why the exception is safe.

---

## How to add a new invariant

1. Append a new section to this file with the next available `I-N` number.
2. Add a matching assertion method to `FlowOne\Storage\Invariants` with the same number in its docblock.
3. Add a chaos scenario (or extend an existing one) that exercises the invariant.
4. Add a row to the cross-reference table.
5. Run `php shared/bin/storage-ctl.php invariants check` to verify the assertion/scenario mapping is complete. CI runs the same check.

## How to remove an invariant

Don't. If you genuinely need to retire one (because the underlying mechanism changed), open an issue describing the architectural shift, get a second pair of eyes on the safety argument, and only then remove the section, assertion, and scenario together as one commit.
