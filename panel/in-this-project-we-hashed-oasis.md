# Panel — Industry-Standard Site Provisioning (NEW SITES ONLY)

> Definitive plan for adding a cPanel/Plesk-grade, DB-driven, async, idempotent, reconciling provisioning system **for new sites going forward**. Existing sites are NOT migrated and NOT touched by this work. Once the new flow proves bulletproof on new creations, a future workstream may migrate existing sites — but that is explicitly out of scope here.

---

## 0. Hard scope boundary (read this first)

**This rebuild applies ONLY to sites created AFTER the new code goes live.**

- Every site that exists on flowone.pro today stays exactly as it is.
- No reconciler runs against them. No verify(). No template re-render. No FPM migration. No automated cleanup. Nothing.
- The new `sites` table will contain a registry row for each existing site (so the API can list them), but every existing-site row is created with `frozen = 1`, which means: reconciler skips, reprovision blocked, no automated work ever runs against it.
- The legacy code path (current `VhostAction::actionCreate / actionDelete / validate / fix / fix-deletion`) **is preserved indefinitely** — existing sites continue to use it for delete / validate / fix operations until a future migration phase decides otherwise.
- The user-facing dashboard treats existing sites and new sites slightly differently: existing sites get the legacy flow; new sites get the new job-based flow.

**Why this scope:** the user's directive is to prove the new flow creates sites perfectly every time before risking any change to live infrastructure. Migration of legacy sites is a separate future project, dependent on this one's success.

---

## 1. Why this rebuild

### 1.1 Current state (problems)

| # | Symptom | Root cause |
|---|---|---|
| 1 | "Sometimes site creation works, sometimes it errors at preflight" | 12 preflight checks join their failures into one string; user can't see which check actually failed |
| 2 | Cleanup ("fix-deletion") doesn't fully clean up | `actionFixDeletion` stops at the first fixer that throws; remaining issues are not attempted |
| 3 | OLS restart can fail silently and report success | After 3 retries, only logs `ols_restart_failed=true` and still returns success |
| 4 | `mysqldump` failure can cause data loss | Backup runs before `DROP DATABASE`, but its exit code is not checked |
| 5 | Re-creating the same domain after a failed attempt is blocked by orphans | Most steps error on "already exists" instead of converging |
| 6 | Frontend hangs for up to 120 s with no progress | Synchronous HTTP call to `agent.execute('vhost.create')` |
| 7 | No way to know if a site is actually healthy without manually clicking "validate" | No reconciler |
| 8 | PHP runs as `www-data` for sites without an SFTP user | Shared user = no multi-tenant security boundary |
| 9 | Configs are built by string concatenation in PHP | Hard to audit, version, or template |
| 10 | After a job runs, you only get one rolling agent log | No per-job transcript |

### 1.2 Architectural gaps (the deeper problems)

1. **No source of truth** — `sites` only exist on the filesystem. The DB has `user_sites` (auth) and `client_domains` (billing) but no record of "what sites exist and what state are they in."
2. **No async job model** — every action is a 120-s synchronous HTTP call.
3. **No idempotency** — running provision twice errors instead of converging.
4. **No reconciliation** — drift between DB and filesystem is invisible until something visibly breaks.
5. **No templates** — config edits are inline string operations.
6. **No PHP isolation** — single shared `www-data` user when SFTP user isn't created.
7. **No per-job audit trail** — single rolling log file.

### 1.3 Goals (what success looks like — **for NEW sites only**)

- Creating a NEW site is an enqueued background job with real-time per-step progress in the UI.
- Running provision on the same NEW domain twice is a no-op the second time. Same for delete.
- A reconciler runs every 15 min and flags / repairs drift on NEW sites only (existing sites are `frozen=1`, skipped).
- Every shell command, SQL statement, and file write during a job is recorded in a per-job transcript file viewable from the panel UI.
- PHP for new `siteA.com` runs as `siteA` user, isolated from new `siteB.com`. (Existing sites stay on shared lsphp / www-data; not touched.)
- All configs for new sites render from versioned templates. (Existing sites keep their current config files untouched.)
- SSL is allowed to fail on first attempt (DNS pending) without marking the site as failed; reconciler retries SSL only on new sites.
- Cleanup of NEW sites runs every fixer regardless of individual failures, then re-validates.
- **Existing sites remain on the legacy flow until a future migration project says otherwise.**

### 1.4 What you already have (integrates with the rebuild — NOT to be rebuilt)

Audit of the existing codebase confirmed these capabilities already exist and the new provisioner must continue to support them, not replace them:

- **Multi-domain per user** — `user_sites` (many-to-many on `user_id` + `domain`) already supports one panel user owning N sites. The new `sites` table joins to `user_sites.domain`, no schema break.
- **Subdomains as first-class** — `DnsAction::findParentZone` (lines 1564–1587) + `addSubdomainRecords` already detect when the requested domain is a subdomain of an existing zone and add records to the parent zone instead of creating a new zone. `DnsZoneStep::provision` will delegate to this same logic.
- **Mail quotas** — `mail_quotas` table (`migrate_phase6_enhancements.sql:81`) tracks `quota_mb` / `used_mb` per mailbox. `MailDomainStep::provision` will seed default quota rows here.
- **Mail sending limits** — `mail_sending_limits` table tracks hourly/daily caps per domain. Same step seeds defaults.
- **ModSec per-site** — `panel/agent/Actions/ModsecAction.php` exists. The new step set will include `ModsecStep` (or fold it into `VhostStep::verify`) so per-vhost ModSec rules are part of drift checks. **Add this to component inventory.**
- **Site cloning** — `site_clone_history` table tracks source→target clones. Becomes a new `provisioning_jobs.action = 'clone'` value; reuses the existing clone flow, just queue-driven. **Add this to API surface.**
- **Webmail / email autoconfig / autodiscover** — owned by the separate `email/` project (your own mail product), out of panel's scope and not duplicated here.

### 1.5 Actual non-goals

- Quotas / **hosting** packages (per-site disk, DB count, bandwidth caps — distinct from existing mail quotas).
- Cluster DNS replication (single PowerDNS is fine).
- Worker concurrency (single worker process is enough).
- DDoS / CDN / WAF integration beyond existing ModSec.
- AWStats / per-site visitor analytics.
- Reseller hierarchies.
- Migration tooling for sites originally created on the old CyberPanel install.

---

## 2. Target architecture

### 2.1 Component diagram

```
┌──────────────┐   POST /api/sites      ┌──────────────────┐  enqueue  ┌─────────────────────┐
│  Dashboard   │ ─────────────────────► │  SiteController  │ ────────► │ provisioning_jobs   │
│  (Vue 3)     │ ◄───────────────────── │  returns job_id  │           │ (panel DB)          │
└──────┬───────┘   { job_id, site_id }  └──────────────────┘           └────────┬────────────┘
       │                                                                        │ FOR UPDATE
       │ poll GET /api/jobs/{job_id}                                            │ SKIP LOCKED
       ▼                                                                        ▼
┌──────────────┐                                                  ┌─────────────────────┐
│ Job Progress │                                                  │ Provisioning Worker │
│ Drawer       │ ◄─── progress JSON  ───────────────────────────  │ (systemd)           │
│ + Transcript │                                                  └────────┬────────────┘
└──────────────┘                                                           │
                                                                           │ unix socket
                                                                           ▼
                                                                  ┌─────────────────────┐
                                                                  │  Agent (root)       │
                                                                  │  Step actions only  │
                                                                  └─────────────────────┘
                                                                           │
                                                                           ▼
                                                                  filesystem + MariaDB
                                                                  + PowerDNS + Postfix
                                                                  + Dovecot + OpenDKIM
                                                                  + OpenLiteSpeed

┌─────────────────────┐  every 15 min   ┌─────────────────────┐  enqueue reconcile job
│ Reconcile Worker    │ ──────────────► │ provisioning_jobs   │ for any drifted site
│ (cron.d)            │                 └─────────────────────┘
└─────────────────────┘
```

### 2.2 Five new primitives

| Primitive | Lives in | Purpose |
|---|---|---|
| `sites` table | panel DB | Source of truth: state machine + per-step provisioning timestamps |
| `provisioning_jobs` table | panel DB | Async job queue |
| `JobService` | API | Enqueue / get / list jobs |
| `ProvisioningWorker` | systemd daemon | Drains the queue, runs `SiteProvisioner` against each site |
| Templates (`vhost.conf.tmpl`, `fpm-pool.conf.tmpl`, `dns-zone-seed.json`) | agent | Pure rendering of config files |

### 2.3 Step-based provisioner (the core abstraction)

Every aspect of a site is a `Step` with a uniform contract:

```php
interface ProvisioningStep
{
    public function name(): string;                                  // e.g. "vhost"
    public function provision(SiteContext $ctx): StepResult;         // idempotent: skip if already done
    public function deprovision(SiteContext $ctx): StepResult;       // idempotent: skip if already gone
    public function verify(SiteContext $ctx): DriftReport;           // returns list of drift items, empty = ok
    public function dependsOn(): array;                              // step names this one needs first
}
```

Steps:

1. `HomeDirStep` — `/home/{domain}` + subdirs + perms
2. `SftpUserStep` — Linux user, SSH key, jail
3. `FpmPoolStep` — per-user PHP-FPM pool (NEW)
4. `VhostStep` — OLS vhost dir + vhost.conf + main config include + reload
5. `DatabaseStep` — MariaDB db + user + grant
6. `DnsZoneStep` — PowerDNS zone + records (SOA, NS, A, www CNAME)
7. `MailDomainStep` — Postfix virtual entry + mail dir + DKIM keygen + DNS records (MX, SPF, DMARC, DKIM)
8. `SslStep` — certbot ACME (allowed to fail; reconciler retries)

`SiteProvisioner` walks them in dependency order, reading each step's `provisioned_at` timestamp from the `sites` row to decide skip-or-run.

`SiteDeprovisioner` walks them in reverse order, only undoing steps with non-NULL timestamps.

`SiteReconciler` walks each step's `verify()` and collects drift items into `sites.reconcile_drift`.

---

## 3. Data model

### 3.1 `sites` table

```sql
CREATE TABLE sites (
  id                          INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  domain                      VARCHAR(253) NOT NULL UNIQUE,
  state                       ENUM('provisioning','active','degraded','suspending','suspended',
                                   'deleting','deleted','failed_provisioning','failed_deletion')
                              NOT NULL DEFAULT 'provisioning',
  desired_state               ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  site_user                   VARCHAR(32) NULL,
  document_root               VARCHAR(255) NULL,
  php_lsapi                   VARCHAR(20) DEFAULT 'lsphp83',
  -- Per-step idempotency markers
  vhost_provisioned_at        TIMESTAMP NULL,
  home_dir_provisioned_at     TIMESTAMP NULL,
  sftp_user_provisioned_at    TIMESTAMP NULL,
  fpm_pool_provisioned_at     TIMESTAMP NULL,
  database_provisioned_at     TIMESTAMP NULL,
  dns_provisioned_at          TIMESTAMP NULL,
  mail_provisioned_at         TIMESTAMP NULL,
  ssl_provisioned_at          TIMESTAMP NULL,
  -- Reconciler
  last_reconciled_at          TIMESTAMP NULL,
  reconcile_drift             JSON NULL,
  provisioning_params         JSON NULL,                 -- params from original create call
  -- Live-safety opt-out: when 1, reconciler skips this site, reprovision is blocked.
  -- Use for sensitive production sites you don't want the rebuild touching.
  frozen                      TINYINT(1) NOT NULL DEFAULT 0,
  created_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_state (state),
  INDEX idx_desired_state (desired_state),
  INDEX idx_last_reconciled (last_reconciled_at),
  INDEX idx_frozen (frozen)
);
```

### 3.2 `provisioning_jobs` table

```sql
CREATE TABLE provisioning_jobs (
  id                  CHAR(36) PRIMARY KEY,                    -- UUID v4
  site_id             INT UNSIGNED NOT NULL,
  action              ENUM('provision','reprovision','delete','suspend','resume','reconcile','clone') NOT NULL,
  status              ENUM('queued','running','succeeded','failed','cancelled') NOT NULL DEFAULT 'queued',
  params              JSON NOT NULL,
  progress            JSON NULL,        -- [{step,status,started_at,finished_at,error?,output_lines?}, ...]
  transcript_path     VARCHAR(500) NULL,
  attempt             INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts        INT UNSIGNED NOT NULL DEFAULT 3,
  claimed_by          VARCHAR(64) NULL,                        -- worker_pid@hostname
  claimed_at          TIMESTAMP NULL,
  enqueued_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  started_at          TIMESTAMP NULL,
  finished_at         TIMESTAMP NULL,
  error               TEXT NULL,
  actor               VARCHAR(50) NOT NULL,
  INDEX idx_status_enqueued (status, enqueued_at),
  INDEX idx_site (site_id),
  INDEX idx_action (action),
  INDEX idx_finished (finished_at),
  CONSTRAINT fk_jobs_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
);
```

### 3.3 State machine (sites.state)

```
                  ┌──────── reprovision ────────┐
                  ▼                             │
  (new) → provisioning ──ok──► active ──reconfigure──┐
            │                   ▲ │                  │
            fail                │ ▼                  │
            ▼                   │ degraded ◄──drift──┘
   failed_provisioning ─────────┘    │
            │                        │ reprovision
            └──── reprovision ───────┘
                                                   ┌──── resume ────┐
                                                   ▼                │
            active/degraded ─── suspend ──► suspending ──ok──► suspended
                                                                    │
                                                                    │ delete
                                                                    ▼
                                              deleting ──ok──► deleted
                                                  │
                                                  fail
                                                  ▼
                                           failed_deletion ──fix-deletion──► deleted
```

Allowed transitions are enforced by `SiteService::transition($id, $newState, $reason)`. Anything else throws.

### 3.4 Idempotency contract (per step)

Every `provision()` follows the same contract:

1. Read `sites.{step}_provisioned_at`. If non-NULL **and** `verify()` returns no drift → return `skipped: already_provisioned`.
2. Run the actual work.
3. Re-run `verify()`. If drift remains → throw.
4. `UPDATE sites SET {step}_provisioned_at = NOW() WHERE id = ?` only on success.

Every `deprovision()`:

1. Read `sites.{step}_provisioned_at`. If NULL → return `skipped: nothing_to_do`.
2. Run undo work.
3. `UPDATE sites SET {step}_provisioned_at = NULL WHERE id = ?` only on success.

This is what makes everything safe to retry.

---

## 4. Component inventory

### 4.1 Files to create

#### Database

| Path | Purpose | LOC est. |
|---|---|---|
| `panel/database/migrate_sites_state.sql` | `sites` + `provisioning_jobs` schema | 80 |
| `panel/database/migrate_sites_state.php` | Runner: applies SQL, then walks `/usr/local/lsws/conf/vhosts/` and seeds one `sites` row per existing vhost (state=`active`, all `*_provisioned_at` = NOW(); reconciler corrects on first pass) | 150 |

#### API services

| Path | Purpose | LOC est. |
|---|---|---|
| `panel/api/src/Services/SiteService.php` | DB-first site CRUD: `getOrCreate`, `findByDomain`, `transition`, `markStepComplete`, `markStepIncomplete`, `setDrift`, `list` | 250 |
| `panel/api/src/Services/JobService.php` | `enqueue($action, $siteId, $params, $actor)`, `get($jobId)`, `list($siteId)`, `cancel($jobId)`, `updateProgress`, `claim`, `release` | 200 |

#### API controllers

| Path | Purpose | LOC est. |
|---|---|---|
| `panel/api/src/Controllers/JobController.php` | `GET /api/jobs/{id}`, `GET /api/jobs/{id}/transcript`, `GET /api/sites/{domain}/jobs`, `POST /api/jobs/{id}/cancel` | 130 |

#### Agent core

| Path | Purpose | LOC est. |
|---|---|---|
| `panel/agent/Workers/ProvisioningWorker.php` | systemd-managed daemon. Loop: claim job → run `SiteProvisioner` or `SiteDeprovisioner` → update progress → release | 250 |
| `panel/agent/Workers/ReconcileWorker.php` | cron-driven. For each site in `active`/`degraded`, run all `verify()`s, set drift, optionally enqueue auto-fix | 180 |
| `panel/agent/Lib/SiteContext.php` | DTO holding `Site` row + computed paths + transcript handle + logger | 80 |
| `panel/agent/Lib/StepResult.php` | `{status: ok|skipped|failed, message, output_lines, took_ms}` | 40 |
| `panel/agent/Lib/DriftReport.php` | List of `{step, item, expected, actual, fixable}` entries | 60 |
| `panel/agent/Lib/Transcript.php` | Per-job log file at `/var/www/vps-admin/logs/transcripts/{job_id}.log`. Wraps every shell exec / SQL / file write | 150 |
| `panel/agent/Lib/TemplateRenderer.php` | Pure variable substitution + simple `{{#if}}` / `{{#foreach}}` | 100 |
| `panel/agent/Lib/Hooks.php` | Run-parts style: `/etc/vps-admin/hooks.d/{pre,post}-{action}/*` (numbered scripts, JSON params on stdin) | 80 |
| `panel/agent/Lib/OlsConfigEditor.php` | Safe edit + backup + `lswsctrl test` of `httpd_config.conf`; revert on failure | 200 |

#### Provisioner orchestrators

| Path | Purpose | LOC est. |
|---|---|---|
| `panel/agent/Provisioners/SiteProvisioner.php` | Walks steps in dependency order, skips done steps, transitions site state | 200 |
| `panel/agent/Provisioners/SiteDeprovisioner.php` | Walks steps in reverse, only undoes done steps | 150 |
| `panel/agent/Provisioners/SiteReconciler.php` | Runs all `verify()`s, builds drift report, optionally enqueues fix | 130 |
| `panel/agent/Provisioners/StepRegistry.php` | Lists all steps; resolves dependency order | 60 |

#### Steps (one file each)

| Path | LOC est. |
|---|---|
| `panel/agent/Provisioners/Steps/HomeDirStep.php` | 120 |
| `panel/agent/Provisioners/Steps/SftpUserStep.php` | 200 |
| `panel/agent/Provisioners/Steps/FpmPoolStep.php` (new capability) | 180 |
| `panel/agent/Provisioners/Steps/VhostStep.php` | 220 |
| `panel/agent/Provisioners/Steps/DatabaseStep.php` | 180 |
| `panel/agent/Provisioners/Steps/DnsZoneStep.php` | 200 |
| `panel/agent/Provisioners/Steps/MailDomainStep.php` | 250 |
| `panel/agent/Provisioners/Steps/ModsecStep.php` (wraps existing `ModsecAction`) | 100 |
| `panel/agent/Provisioners/Steps/SslStep.php` | 150 |

The mail step seeds defaults into the existing `mail_quotas` and `mail_sending_limits` tables; the DNS step delegates to the existing `DnsAction::findParentZone` for subdomains; the ModSec step wraps the existing `ModsecAction` so its drift becomes visible in reconciliation.

#### Templates

| Path | Purpose |
|---|---|
| `panel/agent/Templates/vhost.conf.tmpl` | OLS vhost config (extProcessor pointing at per-site FPM socket) |
| `panel/agent/Templates/fpm-pool.conf.tmpl` | Per-user PHP-FPM pool |
| `panel/agent/Templates/dns-zone-seed.json` | Default zone records |
| `panel/agent/Templates/index.placeholder.html` | Default landing page |
| `panel/agent/Templates/postfix-virtual.tmpl` | Postfix virtual entry block |

#### Frontend

| Path | Purpose |
|---|---|
| `panel/dashboard/src/composables/useJob.js` | Polls `GET /api/jobs/{id}` every 1.5 s, exposes reactive job state |
| `panel/dashboard/src/composables/useSiteJobs.js` | Lists all jobs for one site (history) |
| `panel/dashboard/src/components/sites/JobProgressDrawer.vue` | Right-side drawer showing per-step status |
| `panel/dashboard/src/components/sites/JobTranscriptViewer.vue` | Streams transcript file contents (tail-style) |
| `panel/dashboard/src/components/sites/SiteDriftBanner.vue` | Top banner on site detail page when `state='degraded'` |

#### Deploy artifacts

| Path | Purpose |
|---|---|
| `panel/agent/deploy/vps-admin-worker.service` | systemd unit for ProvisioningWorker |
| `panel/agent/deploy/vps-admin-reconcile.cron` | cron.d entry for ReconcileWorker |
| `panel/agent/deploy/install-worker.sh` | Idempotent installer that copies the unit file, enables + starts the worker |

### 4.2 Files to modify

| Path | Lines | Change |
|---|---|---|
| `panel/api/src/Controllers/SiteController.php` | 110-258 | `create()` and `delete()` become thin: validate → `SiteService::getOrCreate` → `JobService::enqueue` → return `{job_id, site_id}`. Delete the synchronous `agent->execute('vhost.create')` call entirely |
| `panel/api/routes.php` | 101-123 | Add `GET /api/jobs/{id}`, `GET /api/jobs/{id}/transcript`, `GET /api/sites/{domain}/jobs`, `POST /api/jobs/{id}/cancel`, `POST /api/sites/{domain}/reprovision`, `POST /api/sites/{domain}/reconcile` |
| `panel/api/public/index.php` | bootstrap | Run `migrate_sites_state.php` as part of the existing migration sequence |
| `panel/agent/agent.php` | 297-346 | Heavy-action fork code goes away (long ops live in worker now). Agent stays for short atomic ops |
| `panel/agent/Actions/VhostAction.php` | 6544 lines | Extract: creation logic → `Steps/VhostStep.php`. Keep small wrappers for `actionList`, `actionGet`, `actionGetSshKeys`, etc. (read-only ops). Remove `actionCreate`, `actionDelete`, `doCreate`, `doDelete`, `preflightCreateChecks`, `preflightDeleteChecks`, rollback paths |
| `panel/agent/Actions/DatabaseAction.php` | 230-347 | `actionCreate` becomes `CREATE … IF NOT EXISTS` style (idempotent). `actionDelete` verifies `mysqldump` exit code AND `filesize($backup) > 0` before `DROP DATABASE` |
| `panel/agent/Actions/DnsAction.php` | 368-498 | `actionCreateZone` becomes `INSERT IGNORE` + `INSERT … ON DUPLICATE KEY UPDATE` for records (idempotent) |
| `panel/agent/Actions/MailAction.php` | 323-440 | `actionAddDomain` idempotent; DKIM keygen skips if key file already present |
| `panel/agent/Lib/Logger.php` | all | Accept optional `Transcript $transcript` so every log line is mirrored into the per-job transcript |
| `panel/agent/Lib/BaseAction.php` | 112-194 | `execCommand` records every invocation through `Transcript` if one is bound to the call |
| `panel/dashboard/src/views/SitesView.vue` | 397-541 | Replace inline result modal with `JobProgressDrawer`; delete WordPress-install second call (becomes another step or post-hook) |
| `panel/dashboard/src/views/SiteDetailView.vue` | end | Add `SiteDriftBanner` + jobs history tab |

### 4.3 Files to delete (after migration)

After Phase 5 ships and one safe-period (~2 weeks) passes:

- `VhostAction.php` methods: `doCreate`, `doDelete`, `preflightCreateChecks`, `preflightDeleteChecks`, `createSftpUser` (moves to step), `addVhostToMainConfig`, `removeVhostFromMainConfig` (move to `OlsConfigEditor`), all rollback helpers — anything no longer called.
- `panel/dashboard/src/views/SitesView.vue:544-604` retry-anyway cleanup retry logic.

---

## 5. API surface

### 5.1 New endpoints

| Method | Path | Purpose | Returns |
|---|---|---|---|
| POST | `/api/sites` | Enqueue site creation | `{job_id, site_id}` |
| DELETE | `/api/sites/{domain}` | Enqueue site deletion | `{job_id, site_id}` |
| POST | `/api/sites/{domain}/reprovision` | Enqueue reprovision (idempotent re-run of all steps) | `{job_id, site_id}` |
| POST | `/api/sites/{domain}/reconcile` | Enqueue immediate reconciler pass for one site | `{job_id, site_id}` |
| GET | `/api/jobs/{id}` | Job status + progress JSON | `{id, status, action, progress, started_at, finished_at, error}` |
| GET | `/api/jobs/{id}/transcript` | Stream/tail transcript file | text/plain |
| POST | `/api/jobs/{id}/cancel` | Cancel a queued (not running) job | `{cancelled: true}` |
| GET | `/api/sites/{domain}/jobs` | Job history for one site | `[{...}, ...]` |
| POST | `/api/sites/{domain}/clone` | Enqueue clone job (reuses existing clone flow, surfaces via job progress) | `{job_id, site_id}` |

### 5.2 Changed semantics

- `POST /api/sites` no longer blocks for 120 s. Returns 202 Accepted with `{job_id, site_id}` immediately.
- `DELETE /api/sites/{domain}` likewise returns 202 with a job id.
- `GET /api/sites/{domain}` now reads from the `sites` table (DB-backed), augmented with filesystem facts only where needed for legacy fields.

### 5.3 Removed endpoints (after Phase 3)

- `POST /api/sites/{domain}/fix` — replaced by `POST /api/sites/{domain}/reprovision`
- `POST /api/sites/{domain}/fix-issue` — same
- `GET /api/sites/{domain}/validate` — replaced by `sites.last_reconciled_at` + `sites.reconcile_drift` (already on the model)
- `GET /api/sites/{domain}/validate-deletion` — replaced by `GET /api/jobs/{id}` for the deletion job
- `POST /api/sites/{domain}/fix-deletion` — replaced by `POST /api/sites/{domain}/reprovision` against the `deleting` state

These are kept as deprecation shims for two phases (Phases 3 and 4) and removed in Phase 5.

---

## 6. Phased implementation

Each phase ships independently. **Do not start phase N+1 until N is verified on flowone.pro.**

### Phase 1 — Foundation (DB + services, no behavior change)

**Deliverables**

1. `panel/database/migrate_sites_state.sql`
2. `panel/database/migrate_sites_state.php` (with filesystem backfill)
3. `panel/api/src/Services/SiteService.php`
4. `panel/api/src/Services/JobService.php`
5. `panel/api/src/Controllers/JobController.php`
6. `panel/api/routes.php` — add job endpoints (provision/delete still go through old path)

**Behavior change**: none. Endpoints exist but no enqueueing yet.

**Verification**

- Apply migration on flowone.pro DB.
- `mysql vpsadmin -e "SELECT COUNT(*) FROM sites; SELECT state, COUNT(*) FROM sites GROUP BY state"` — count matches `ls /usr/local/lsws/conf/vhosts/ | wc -l`, all 'active'.
- `curl https://flowone.pro/api/sites/{existing}/jobs` returns `[]`.
- Old site creation flow still works unchanged.

**Rollback**: `DROP TABLE provisioning_jobs; DROP TABLE sites;` — nothing depends on them yet.

**Estimated time**: 1–2 days.

---

### Phase 2 — Worker, idempotent steps, templates

**Deliverables**

1. `Lib/Transcript`, `Lib/SiteContext`, `Lib/StepResult`, `Lib/DriftReport`, `Lib/TemplateRenderer`, `Lib/OlsConfigEditor`.
2. All `Provisioners/Steps/*.php` (one per primitive) — except `FpmPoolStep` which lands in Phase 4.
3. `Provisioners/SiteProvisioner.php` and `SiteDeprovisioner.php`.
4. `Workers/ProvisioningWorker.php` + systemd unit + installer.
5. Templates: `vhost.conf.tmpl`, `dns-zone-seed.json`, `index.placeholder.html`, `postfix-virtual.tmpl`.
6. `SiteController::create` and `delete` switch to enqueueing jobs.
7. `JobProgressDrawer.vue` + `useJob.js` + `JobTranscriptViewer.vue`.
8. Frontend: replace inline modal with drawer.

**Behavior change**: site creation is now async. UI shows live per-step progress. Idempotent retries work.

**Verification**

- Create `t1.flowone.pro` with all components. Job completes in drawer in < 30 s. SSL step shows "failed: DNS not propagated" — site still goes to `active` state.
- `mysql vpsadmin -e "SELECT * FROM sites WHERE domain='t1.flowone.pro'\G"` — every `*_provisioned_at` non-NULL except `ssl_provisioned_at`.
- `cat /var/www/vps-admin/logs/transcripts/{job_id}.log` — every shell command + SQL captured.
- Re-trigger creation on the same domain via API → returns existing site_id, every step logs "skipped: already_provisioned". `t1.flowone.pro` undisturbed.
- Delete `t1.flowone.pro` with all delete options. Reverse walk runs. Site row state = `deleted`. No leftover dirs / DBs / DNS rows.
- Forced failure: corrupt `httpd_config.conf` → provision `t2.flowone.pro` → job `failed_provisioning` → `OlsConfigEditor` reverted backup → no orphan home dir on filesystem.

**Rollback**: Stop worker. Revert `SiteController::create` to old synchronous path. Old `actionCreate` still exists (we kept it).

**Estimated time**: 5–8 days.

---

### Phase 3 — Reconciler + drift handling

**Deliverables**

1. `Workers/ReconcileWorker.php` + cron.d entry.
2. `Provisioners/SiteReconciler.php`.
3. Each step's `verify()` method (extracted from existing `actionValidateSite` + `actionValidateDeletion`).
4. `POST /api/sites/{domain}/reconcile` route.
5. `SiteDriftBanner.vue` and a "Reconcile now" button on the site detail page.
6. `SitesView.vue` lists drifted sites with badges.

**Behavior change**: every 15 min, every `active` site is verified. Drift surfaces in UI. One-click "fix drift" enqueues a `reprovision` job (only re-runs steps whose `verify()` reported drift, since the others have non-NULL timestamps and pass verify).

**Verification**

- Manually `rm -rf /home/t1.flowone.pro/logs`. Wait for next reconciler tick (or trigger via API). Site → `degraded`, `reconcile_drift` shows `home_dir: missing logs/`.
- Click "Reconcile now": within seconds drift updates.
- Click "Fix drift": reprovision job runs, only `HomeDirStep::provision` actually does work (log shows other steps as skipped). State → `active`.
- Manually `DROP DATABASE` for a site → drift banner appears → fix-drift creates the DB cleanly.

**Rollback**: Disable cron entry. Worker code is unused but harmless.

**Estimated time**: 3–5 days.

---

### Phase 4 — Per-site PHP-FPM for NEW sites (security boundary)

**Deliverables**

1. `Provisioners/Steps/FpmPoolStep.php`.
2. `Templates/fpm-pool.conf.tmpl`.
3. `vhost.conf.tmpl` updated so NEW vhosts point `extProcessor` at the per-site FPM socket.

**Behavior change**: every NEW site gets its own FPM pool running as its own user. Existing sites are unchanged — they continue using whatever lsphp setup they had before.

**Verification**

- Provision a new site `t-fpm.flowone.pro`. `ps -o user,cmd | grep lsphp` shows a process running as the `t-fpm` user.
- SSH as `t-fpm`, `ls /home/{any-existing-site}/public_html` → permission denied.
- Drop a `<?php echo file_get_contents('/home/{existing-site}/public_html/index.html'); ?>` into the new site: serving it returns empty / open_basedir warning.
- Confirm `ps aux | grep lsphp | grep www-data` still shows the existing-sites lsphp processes — they're untouched.

**Rollback**: revert `vhost.conf.tmpl` to the shared extProcessor for new sites going forward. Sites already provisioned with per-site FPM stay that way (or you reprovision them).

**Estimated time**: 2–3 days (smaller than before because no existing-site migration).

---

### Phase 5 — Hooks + UI polish (no removals)

**Deliverables**

1. `Lib/Hooks.php` runner.
2. `/etc/vps-admin/hooks.d/{pre,post}-{action}/` directory convention.
3. Sample hook: `post-provision/10-audit-log.sh` (writes a structured event to `audit_logs`).
4. Transcript tab in site detail page (for new sites only — legacy sites show their old details).
5. Job history tab (only populated for new sites).
6. Dashboard distinguishes "new sites" (job-based UI) from "legacy sites" (existing UI), with a clear badge.

**Explicitly NOT in this phase**:
- Removing deprecated endpoints (`/api/sites/{domain}/validate`, `/fix`, `/fix-issue`, `/validate-deletion`, `/fix-deletion`) — they stay because legacy sites still depend on them.
- Removing old `VhostAction::actionCreate / actionDelete / preflightCreateChecks / preflightDeleteChecks / rollback helpers` — they stay because legacy sites still depend on them, AND because Phase 2's controller flip can fall back to them if needed.

**Behavior change**: extension points exist; UI distinguishes new vs legacy sites.

**Verification**

- Drop `/etc/vps-admin/hooks.d/post-provision/99-test.sh` that writes to `/tmp/hook-fired`. Provision a NEW site. Confirm file appears with JSON params on stdin.
- Provision a NEW site → legacy endpoints `validate`/`fix` not called.
- Open a LEGACY site in the dashboard → still uses old controller methods, still works.
- `git grep actionCreate panel/agent/` returns hits — confirmed, kept on purpose.

**Rollback**: revert hook runner if it misbehaves. Legacy paths untouched, no risk to existing sites.

**Estimated time**: 2 days.

---

## 6.5 Live environment safety (the new scope makes this trivial)

> **Existing sites and the live email system are NOT touched by this rebuild.** Period. The new flow only governs new sites. This section documents how that boundary is enforced.

### 6.5.1 The single rule

**A site row with `frozen = 1` is invisible to the new system.**
- Reconciler skips it (no drift checks, no verify() runs).
- Reprovision endpoint returns 409 "site is frozen, manage via legacy flow".
- Job worker refuses to claim a job whose target is frozen.
- Admin UI shows it in the sites list with a "legacy" badge; clicking it routes to the old detail/management views.

**Backfill sets `frozen = 1` on every existing site row** unconditionally. This is enforced at the SQL level — backfill cannot un-freeze.

The only way to un-freeze a site is **manual** SQL update by the user, after a future migration tool exists and proves itself on a test site.

### 6.5.2 Per-phase impact on live traffic

| Phase | Touches existing sites? | Touches existing email? | Outage window |
|---|---|---|---|
| 1 (DB + backfill) | Reads filesystem to register them with `frozen=1`. **No filesystem writes.** No config changes. No restarts. | No | Zero |
| 2 (worker + steps) | Worker rejects any job targeting `frozen=1`. Existing sites continue using legacy `actionCreate/Delete/validate/fix` paths. | No | Zero |
| 3 (reconciler) | Skips `frozen=1` rows entirely. Cron query is `WHERE frozen = 0`. | No | Zero |
| 4 (per-site FPM, NEW sites only) | Existing sites keep their current shared lsphp / www-data setup. No migration. | No | Zero |
| 5 (cleanup) | Legacy endpoints (`validate`, `fix`, `fix-issue`, `validate-deletion`, `fix-deletion`) **kept indefinitely** because existing sites still use them. Only dashboard internals change. | No | Zero |

**There is no Phase 4 outage.** Existing sites are not migrated, so no OLS reloads, no FPM pool migrations, no `lswsctrl restart` cascades.

### 6.5.3 Hard guarantees (what the new code physically cannot do)

1. **Backfill is read-only on the filesystem.** It only inspects `/usr/local/lsws/conf/vhosts/`, `/etc/letsencrypt/live/`, etc. It writes only to the new `sites` table. It cannot modify any vhost config, home dir, DNS row, or mail config.
2. **Backfill writes nothing on first run by default.** `migrate_sites_state.php --dry-run` is the default. Prints what it would insert into `sites`. `--apply` actually writes (still only to the `sites` table).
3. **Backfill always sets `frozen = 1`.** Hardcoded in the runner. There is no flag to disable this.
4. **Reconciler query is `WHERE frozen = 0`.** Existing sites are physically unreachable to the reconciler.
5. **Worker query is `WHERE NOT EXISTS (... frozen=1 ...)`.** A misuse of the API that targets a frozen site fails at SQL level, not at runtime check.
6. **Phase 2 controller flip is gated by a feature flag.** `'site_creation' => ['use_worker' => false]` until you flip it. Until then, **all** site creates (new and existing) use the old synchronous path.
7. **Once flipped, the controller routes by site state**: if a request targets an existing-and-frozen site, it falls through to the legacy path. If it's a brand-new domain, it goes to the worker. **The flip never affects existing sites, regardless.**
8. **Legacy endpoints stay alive.** `/api/sites/{domain}/validate`, `/fix`, `/fix-issue`, `/validate-deletion`, `/fix-deletion` are preserved permanently. They're how existing sites are still managed.

### 6.5.4 What the rebuild explicitly does NOT touch

- `email/` project (your standalone email product) — completely untouched.
- Existing `mail_domains` / `mail_accounts` / `mail_forwards` rows — `MailDomainStep` only inserts when adding a NEW domain.
- `roundcube` / `postfixadmin` databases — protected list, untouched.
- `/home/vmail/` for any existing domain — never touched.
- `/etc/opendkim/keys/{any-existing-domain}/` — never touched.
- `/etc/letsencrypt/live/{any-existing-domain}/` — never touched.
- Existing `dns_domains` / `dns_records` rows — never modified.
- Existing `/usr/local/lsws/conf/vhosts/{any-existing-domain}/` directories — never modified.
- `/usr/local/lsws/conf/httpd_config.conf` — only modified when CREATING a new vhost via the new flow. `OlsConfigEditor` always backs up before any edit; `lswsctrl test` validates; revert on failure.

### 6.5.5 Pre-flight checklist (before each phase deploy)

Before flipping each phase live on flowone.pro:

| Check | Command | Pass criteria |
|---|---|---|
| All existing sites respond | `for d in $(ls /usr/local/lsws/conf/vhosts/); do curl -skI https://$d \| head -1; done` | All 2xx/3xx |
| All mail domains accept mail | `swaks --to postmaster@{domain} --server localhost` per domain | All 2xx |
| Email product healthy | `curl -fs https://flowone.pro/email/api/health` | `ok` |
| Panel DB backup from today | `ls /var/www/vps-admin/backups/db/$(date +%Y-%m-%d)*.sql.gz` | ≥1 file |
| Worker idle (no active jobs) | `mysql vpsadmin -e "SELECT COUNT(*) FROM provisioning_jobs WHERE status IN ('queued','running')"` | `0` |

After Phase 1: also confirm `SELECT COUNT(*) FROM sites WHERE frozen = 0` matches the count of sites created via the NEW flow (zero immediately after Phase 1; grows with each new site after Phase 2).

If any check fails, **abort the phase deploy** and investigate.

### 6.5.6 Canary domain: prove new flow before trusting it

Phase 2's verification is a single throwaway domain (e.g., `canary1.flowone.pro`):
1. Create via new flow.
2. Verify all 6 components (vhost, db, dns, mail, ssl-pending, fpm).
3. Watch the site for 48 h.
4. Delete via new flow.
5. Verify cleanup is complete.

Then create `canary2`, repeat. After 5+ successful canary cycles with **zero issues**, the new flow is considered proven for general use.

The user's directive: "once we can prove and create new sites perfectly every single time, then we can make a migration script eventually if needed" — that proof is exactly these canary cycles.

### 6.5.7 Future migration project (out of scope here)

Once the new flow is proven for ≥1 month of new-site creation with no defects, a future workstream can:

1. Pick one low-risk existing site (e.g., a personal/test domain).
2. Manually `UPDATE sites SET frozen = 0 WHERE domain = '...';` for that one site.
3. Run reconciler against it; review reported drift.
4. Iterate the new code to handle the drift gracefully.
5. If drift surfaces issues, fix in code; never let auto-fix run on a real site without explicit approval.
6. Eventually build a `migrate-existing-site.php` runner with full backup, dry-run, and step-by-step opt-in.

**That is a separate plan, written when this one is complete and proven. It is not part of this work.**

---

## 7. Backfill of existing sites (registry only — they are NOT managed)

The VPS has running sites from before this conversion. The Phase 1 backfill creates registry rows so the API can list and reference them, but **every backfilled row is `frozen = 1`** — meaning the new system never touches them.

### 7.1 Strategy

`migrate_sites_state.php`:

1. Lists every directory under `/usr/local/lsws/conf/vhosts/` whose name matches a domain pattern.
2. For each, parses `vhost.conf` to extract `docRoot`, `extUser`, `php_lsapi` (best-effort; fall back to `NULL` on parse failure).
3. Inserts a row in `sites` with:
   - `domain = <dirname>`
   - `state = 'active'`
   - `desired_state = 'active'`
   - `site_user = <extUser>` (or NULL)
   - `document_root = <docRoot>` (or NULL)
   - **`frozen = 1`** (hardcoded in the runner, no flag to disable)
   - All `*_provisioned_at` timestamps stay `NULL` (the new system isn't managing this site, so the markers are irrelevant)
4. Default behavior: `--dry-run` (prints planned inserts, writes nothing). Only `--apply` actually writes.
5. Idempotent: re-running uses `INSERT IGNORE` on `domain`.

### 7.2 What backfill does NOT do

- Does not modify any file on disk.
- Does not modify any existing DB row outside the new `sites` table.
- Does not call `lswsctrl`, `systemctl`, `postfix reload`, or anything that affects running services.
- Does not validate or "verify" anything about the existing site — its registry row is opaque.

### 7.3 Why backfill at all then?

So the API's `GET /api/sites` returns a unified list. The dashboard shows existing sites with a "legacy" badge and routes their detail/management views to the existing controller methods. Without the backfill, the new sites table would only contain new sites, and the dashboard would have to query two sources.

### 7.4 Future un-freeze (out of scope here)

When a future migration project decides to bring an existing site under the new system, that project will:
1. Run a (yet-to-be-written) `migrate-existing-site.php {domain}` tool with backups + dry-run.
2. The tool sets all relevant `*_provisioned_at` timestamps based on observed filesystem state.
3. The tool flips `frozen = 0` only at the very end, after verification passes.

**That tool does not exist yet and is not part of this plan.**

---

## 8. Operational concerns

### 8.1 systemd unit (provisioning worker)

`vps-admin-worker.service`:
- Runs as `root`.
- `ExecStart=/usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/agent/Workers/ProvisioningWorker.php`.
- `Restart=on-failure`, `RestartSec=5`.
- `StandardOutput=append:/var/www/vps-admin/logs/worker.log`.
- One process. No concurrency for now (claim with `FOR UPDATE SKIP LOCKED` already supports adding more later).

### 8.2 cron (reconciler)

`/etc/cron.d/vps-admin-reconcile`:
```
*/15 * * * * root /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/agent/Workers/ReconcileWorker.php >> /var/www/vps-admin/logs/reconcile.log 2>&1
```

### 8.3 Logs

| Log | Path | Purpose |
|---|---|---|
| Agent (existing) | `/var/www/vps-admin/logs/agent.log` | Short atomic ops (unchanged) |
| Worker | `/var/www/vps-admin/logs/worker.log` | Worker lifecycle (start/stop/claim/fail) |
| Reconcile | `/var/www/vps-admin/logs/reconcile.log` | Reconciler tick output |
| Per-job transcript | `/var/www/vps-admin/logs/transcripts/{job_id}.log` | Every command/SQL/file write during one job |
| API (existing) | PHP-FPM error log | Unchanged |

Transcript retention: `find /var/www/vps-admin/logs/transcripts/ -mtime +30 -delete` via cron.

### 8.4 Stuck job recovery

Worker crashes mid-job → row stays `status='running'`, `claimed_by='dead-pid'`. Next worker startup reclaims jobs claimed by a non-existent PID, increments `attempt`, and either retries (if `attempt < max_attempts`) or marks `failed`.

### 8.5 Monitoring (informational, not built in this rebuild)

- Count of jobs stuck in `running` > 5 min → alert.
- Count of sites in `degraded` state → dashboard widget.
- Count of jobs `failed` in last hour → alert.

---

## 9. Testing strategy

### 9.1 Per-phase smoke tests on flowone.pro

Each phase has its dedicated verification section above. Run in order. Must pass before merging next phase.

### 9.2 Stress / chaos tests (Phase 2 + 3)

- Provision 5 sites concurrently — all complete; no DB deadlock; FOR UPDATE SKIP LOCKED proven.
- Kill the worker mid-provision (`kill -9`) — site row stays `provisioning`, restart worker, job picked up, completes.
- Manually corrupt OLS config mid-provision — `OlsConfigEditor` reverts backup, job fails cleanly, no orphans.
- `chmod 000 /var/www/vps-admin/backups/databases` then delete a site — `DatabaseStep::deprovision` fails with "backup failed", database NOT dropped, no data loss.

### 9.3 Idempotency tests

- Provision site, then reprovision: every step skipped. Status `active`, no shell commands run beyond `verify()`s.
- Provision site, manually delete `/home/{domain}/logs`, reprovision: only `HomeDirStep::provision` runs. Other steps skipped.
- Provision, delete, provision again with same domain: works. (This is the original bug.)

### 9.4 Backfill tests

- Snapshot existing `sites` table, drop, re-run migration: same row count, same domains.
- Manually delete a site directory, re-run migration: that site missing — confirmed (skipped).

---

## 10. Risk register

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R1 | Backfill false-positives (timestamps set but artifact missing) | Medium | Low (drift banner appears, no data loss) | Reconciler catches within 15 min |
| R2 | Worker process dies and claimed jobs are stuck | Low | Medium | Stale-claim reclamation on worker startup |
| R3 | OlsConfigEditor revert fails (filesystem fault) | Very low | High | Backup file kept; manual restore documented |
| R4 | DB migration locks `sites` table during ALTERs in future phases | Low | Low | Use `pt-online-schema-change` if any future ALTER is heavy; for now CREATE TABLE only |
| R5 | Per-site FPM pools exhaust kernel resources at scale | Low (we have <50 sites) | Medium | Cap pm.max_children to 5 per pool; tune later if scale demands |
| R6 | Hook script hangs the worker | Medium | Medium | 30 s timeout per hook; killed and logged as warning |
| R7 | Frontend polling at 1.5 s overloads API | Low | Low | Polling stops once `status in (succeeded, failed, cancelled)`; cache-friendly response |
| R8 | Job queue grows unboundedly | Low | Low | Cron purges `succeeded` jobs older than 30 days |
| R9 | Idempotency contract violation (step does work twice) | Medium | High | `verify()` after `provision()` is mandatory; CI test enforces presence |
| R10 | Phase 2 deploy breaks live sites mid-deploy | Medium | High | Old `actionCreate` still callable in Phase 2; flip the controller switch only after worker proven |

---

## 11. Rollback plan

Per-phase rollback summarized; full procedure:

### Phase 1
- `DROP TABLE provisioning_jobs; DROP TABLE sites;`
- Revert `routes.php`, `index.php`, controller files via git.

### Phase 2
- Revert `SiteController::create / delete` to old synchronous calls.
- Stop and disable `vps-admin-worker.service`.
- Old `actionCreate / actionDelete` are still callable (kept until Phase 5).

### Phase 3
- Disable cron entry.
- Worker code remains but reconciler stops running. Drift columns remain empty.

### Phase 4
- Set `fpm_pool_provisioned_at = NULL` for all sites.
- Restore old `vhost.conf.tmpl` (extProcessor=lsphp83 shared).
- Reprovision all sites to push the old vhost config.

### Phase 5
- Restore deprecated endpoints from git tag `pre-phase-5`.
- Restore old `VhostAction::actionCreate / actionDelete` from git.

### Full backout
- Each phase tagged in git: `phase-1-shipped`, `phase-2-shipped`, etc.
- `git revert` the phase commit, push, deploy. Worker stays running (idempotent steps don't break old flow).

---

## 12. Coding conventions

- All new PHP namespaces follow existing pattern: `VpsAdmin\Api\…` and `VpsAdmin\Agent\…`.
- All new code uses strict types: `declare(strict_types=1);`.
- All new code passes existing PHP-CS-Fixer config if present.
- All new SQL files end with a `-- =====` separator block matching existing migrations style.
- All Vue components use `<script setup>` Composition API consistent with `SitesView.vue`.
- Templates use `{{ var }}` substitution; no PHP eval.
- Step `provision()` and `deprovision()` MUST NOT throw on "already done" — they return `StepResult::skipped()`.
- Step `verify()` MUST NOT mutate state.

---

## 13. Out of scope (future workstreams)

- **Migration of existing sites to the new managed model** — the largest deferred item. Backfill registers them as `frozen=1`. A future project will write `migrate-existing-site.php` once the new flow is proven.
- Hosting quotas / packages (per-site disk, DB count, bandwidth caps — distinct from existing mail quotas).
- Cluster DNS replication (multi-PowerDNS).
- Worker concurrency (multiple processes).
- WAF / fail2ban (ModSec per-site already exists and is integrated via `ModsecStep`).
- Backup integration (Borg / restic snapshot before destructive ops).
- Site-level analytics (visitor stats, bandwidth) / AWStats.
- Reseller hierarchies.
- WordPress / Laravel auto-installer rework (today: separate `apps.install` action; can become a `step` later).
- Migration tooling for existing CyberPanel sites (irrelevant — CyberPanel was purged).
- Email autoconfig / webmail (lives in your separate `email/` project).

---

## 14. Open questions for the user

The new-sites-only scope eliminates most prior questions. Remaining:

1. **Per-site PHP-FPM for new sites** — confirm OK with one lsphp worker process per new site (well within RAM headroom)?
2. **Job retention** — keep succeeded jobs 30 days, failed jobs 90 days — OK?
3. **Hook directory** — `/etc/vps-admin/hooks.d/` OK, or prefer in-repo at `panel/hooks/`?
4. **Reconciler cadence** — 15 min default — change to 5 / 10 / 30 / 60?
5. **Reconciler auto-fix on new sites** — when reconciler detects drift on a NEW (non-frozen) site, should it auto-enqueue a `reprovision` job, or only flag for manual review?
6. **SSL retry cadence** — reconciler retries SSL on every pass while `ssl_provisioned_at IS NULL`; cap at how many attempts before requiring manual intervention?
7. **Canary success threshold** — how many successful canary cycles (create + 48 h + delete + verify) before declaring the new flow "proven"? Plan suggests 5; you might want 10 or 20.
8. **Existing partial files** — `panel/database/migrate_sites_state.sql` was started in the earlier run. Keep as Phase 1 starting point, or delete and rewrite?

---

## 15. Estimated total effort

| Phase | Days |
|---|---|
| 1 | 1–2 |
| 2 | 5–8 |
| 3 | 3–5 |
| 4 | 3–4 |
| 5 | 2–3 |
| **Total** | **14–22 days** of focused work |

Allow 1.5x for unknown-unknowns and live-VPS debugging → **~3–4 weeks calendar**.
