# Mailbox Migration Policy

Status: FROZEN business rules for Phase 2. Governs moving a mailbox between clusters (rebalancing, isolation-mode change, draining a cluster for maintenance). Companion to `MAILBOX_PLACEMENT_RULES.md`. Defines *what rules Fleet follows* for migrations.

Prerequisite: Phase 1 HA in production; Dovecot replication PoC complete (it proves the dsync mechanics this policy depends on).

---

## D4. Approval model (decision: manual approval initially)

- **At Phase 2 launch, EVERY migration requires explicit manual operator approval.** Fleet may *recommend* a migration (e.g. a cluster crossed its soft cap), but it never executes one without approval.
- **Auto-migration is a future toggle** (`auto_migrate`, OFF by default), gated behind the same brakes as HA auto-rebuild:
  - cooldown / debounce between migrations,
  - a per-window cap on number of migrations,
  - targets only HEALTHY clusters under their soft cap,
  - a global kill switch.

Manual-first is deliberate: it builds the audit trail and operator trust before any automation is enabled.

---

## When a migration is proposed

- A cluster crosses its soft cap (rebalance recommendation).
- A cluster is being drained for maintenance or decommission.
- A customer's `isolation_mode` changes (`shared` <-> `dedicated`).
- A manual operator request.

---

## Migration mechanics (reuses HA dsync)

The move uses the same replication transport proven for HA. The mailbox stays live on the source until the target is verified.

1. **Pre-flight** - target cluster HEALTHY (validator green), under soft cap, region + isolation match (per `MAILBOX_PLACEMENT_RULES.md`).
2. **Sync** - dsync/replicate the mailbox to the target while the source stays read-write.
3. **Catch-up** - incremental sync until lag ~ 0 (same mechanism as the Dovecot PoC).
4. **Cutover (atomic)** - brief lock -> final delta sync -> flip `mailboxes.cluster_id` -> push the router lookup update to the per-cluster stores -> update Postfix transport -> unlock. Routing lookup, transport, and the data move MUST flip together to avoid split delivery.
5. **Verify** - test-write / roundtrip on the target (same probe as HA validator gate 7); confirm IMAP connect and recent mail present.
6. **Decommission** - remove the source copy only AFTER verify passes.

---

## State machine

```
pending -> syncing -> cutover -> verifying -> done
                                   |
                                   +--> failed -> rollback (source stays authoritative)
```

---

## Safety rules

- **No data loss:** the source copy is retained until target verify passes. Rollback = keep the source authoritative.
- **Idempotent + resumable:** a failed migration rolls back to the source and is safe to retry.
- **Audit:** every migration writes a record - who approved, from cluster, to cluster, started/finished, result. (Manual approval exists to build this trail before automation.)
- **Rate limits:** cap concurrent migrations per cluster to protect live load.
- **Test data:** any migration test uses the `flowone_test_` prefix (per the server-side testing rule) and cleans up afterward.

---

## Out of scope (Phase 3+)

- Fully automatic rebalancing (the `auto_migrate` toggle and its brakes).
- Live cross-region migration (region-locked floating IP makes this a special case).
