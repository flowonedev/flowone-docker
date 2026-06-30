# Mailbox Placement Rules

Status: FROZEN business rules for Phase 2 multi-cluster mailbox placement. Implements the decisions referenced by `multi-cluster_mailbox_placement_543262ce.plan.md`. This document defines *what rules Fleet follows*; the plan defines *how the system is built*. Do not deviate in code without updating this doc.

Prerequisite: Phase 1 HA must be in production (each cluster is an HA cluster with the 7-gate validator green). See `fleet_ha_control_plane_395292a7.plan.md`.

---

## D1. Ownership model (decision: `mailbox -> cluster`)

The authoritative mapping is per-mailbox: `mailboxes.cluster_id` (nullable until placed). Domain and organization are NOT separate ownership models; they are placement rules layered on top:

- `domain_placement_rules`: optional pin of a domain to a specific cluster.
- Customer/organization isolation: see D7.

Resolution precedence when placing a new mailbox (first match wins):

1. Explicit per-mailbox override (rare; admin-set).
2. Domain pin (`domain_placement_rules`).
3. Customer/org isolation rule (D7).
4. Placement policy engine (D2).

---

## D2. Placement policy order (deterministic)

Evaluate candidate clusters in this EXACT order. Each step is a filter; the final step is a tie-breaker. An autonomous implementer must not reorder these.

1. **HEALTHY** - cluster's HA validator is green (all 7 gates). `degraded | rebuilding | disabled` clusters are never placement targets.
2. **Isolation match** - respects the customer's `isolation_mode` (D7). A `dedicated` customer considers only its own cluster(s); a `shared` customer considers only shared clusters.
3. **Region match** - same region as the customer's required region (if set). Required because floating-IP reassign is region-locked (see HA plan `provider-ip` gate).
4. **Below soft capacity** - cluster is under its soft cap (D3). Clusters between soft and hard cap are deprioritized (used only if nothing is under soft cap); clusters at/over hard cap are excluded.
5. **Lowest weighted load wins** - tie-breaker via the D3 score.

If no candidate exists, do NOT auto-create a cluster. Surface a `NoEligibleCluster` reason (capacity / region / isolation) to the operator. Cluster creation stays a deliberate Fleet action.

```
candidates = clusters
  .filter(isHealthy)                  // step 1
  .filter(c => isolationMatch(c, customer))  // step 2
  .filter(c => regionMatch(c, customer))     // step 3

underSoft = candidates.filter(belowSoftCap)  // step 4
pool = underSoft.nonEmpty
     ? underSoft
     : candidates.filter(belowHardCap)
if (pool.isEmpty) raise NoEligibleCluster(reason)
return pool.minBy(weightedLoadScore)         // step 5
```

---

## D3. Capacity model (decision: disk-primary)

Disk is the binding constraint for mail (mailboxes only grow). A cluster is evaluated on multiple dimensions, but the binding gate is disk, with a secondary mailbox-count cap.

Default caps (tunable per cluster via `cluster_capacity`; these are knobs, not architecture):

- Disk used % of the mail volume: soft cap 80%, hard cap 90%.
- Mailbox count: soft cap 1000, hard cap 1200.
- Concurrent users: informational only (not a placement gate at launch).

Rules:

- **"Full" (CLOSED for new placement)** = disk used >= hard cap OR mailbox count >= hard cap, whichever hits first.
- **Soft cap** = placement stops *preferring* the cluster (step 4), but it may still be used if nothing is under soft cap and it is under hard cap.
- **Failover headroom** = caps are deliberately below 100% so the surviving node can carry the full cluster load after a mirror promotion. Never let a cluster fill to the point that promotion has no slack.

Weighted load score (tie-breaker only, step 5; lower = more preferred):

```
score = 0.60 * disk_used_pct
      + 0.30 * (mailbox_count / mailbox_soft_cap)
      + 0.10 * load_factor        // normalized 1m load avg or active connections
```

Disk dominates by weight, matching the disk-primary decision. Weights are tunable, but disk must remain the largest term.

Capacity inputs come from the agent heartbeat + HA validator snapshot. Placement reads the cached per-cluster snapshot; it must NOT run a live probe on the request path.

---

## D7. Customer isolation (decision: per-customer flag)

```
customers.isolation_mode ENUM('shared','dedicated') DEFAULT 'shared'
```

- `shared`: mailboxes may land on any shared cluster per D2. This is the default for new customers (SMB tier).
- `dedicated`: customer is assigned one or more dedicated clusters; only those are placement candidates. Dedicated clusters are provisioned deliberately during enterprise onboarding, never auto-created.
- A customer is either shared or dedicated at any point in time (no mixed state). Switching modes is a migration (see `MIGRATION_POLICY.md`), not a flag flip.

This flag feeds directly into D2 step 2.

---

## D5. Router lookup storage (hot path; decision: Fleet DB + per-cluster cache)

- **Source of truth:** Fleet DB (`mailboxes.cluster_id`, cluster -> backend host mapping).
- **Serving path:** replicated to a per-cluster store (Redis or local SQL) that the Dovecot director/proxy `passdb` reads. Postfix transport routing reads the same per-cluster copy.
- **Hard constraint:** the auth and delivery paths MUST NOT make a synchronous call to Fleet. Logins and mail delivery must keep working while Fleet is down - the same control-plane/data-plane split as the HA plan. Fleet pushes updates; each cluster serves from its local copy.

---

## Schema additions (Phase 2)

- `mailboxes.cluster_id` - nullable FK to `ha_clusters` (the per-mailbox authoritative mapping; reuses the nullable ref reserved in the HA plan).
- `customers.isolation_mode` - ENUM('shared','dedicated') DEFAULT 'shared'.
- `domain_placement_rules` - optional domain -> cluster pin.
- `cluster_capacity` - per-cluster soft/hard caps (defaults in D3).

---

## Open knobs (safe to tune; NOT architecture)

- Exact cap numbers per cluster size / plan tier.
- Weighted-score weights (disk must stay dominant).
- Whether a region requirement is enforced per customer.
