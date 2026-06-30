# Runbook: ALL_MAIL troubleshooting

This runbook covers the day-to-day procedures for diagnosing the
"messages missing from All Mail" class of bug after the Wave 1 hot-fix.
The tooling assumes the production layout under
`/var/www/vps-email/`. Adjust paths if running locally.

## At a glance

| Symptom | First action |
|---|---|
| User reports "I can't find email X in All Mail" | Run the diagnostic test for that account |
| Banner shows "X folders could not be fully read" | Click Details, copy the request_id, grep error log |
| Sidebar group is missing folders after rename | Confirm folder_id is populated; check rename detection log |
| `evt=allmail_invariant_violation` in the log | Treat as P1; run the diagnostic test, audit folder list |

## 1. Run the diagnostic test

```
/usr/local/lsws/lsphp83/bin/php \
  /var/www/vps-email/backend/tests/all-mail-coverage-test.php \
  --email=user@flowone.pro --password=PASS --verbose
```

Useful flags:

* `--smoke` -- DB + Redis + IMAP connect, no per-folder scan
* `--only=preflight,breaker` -- skip coverage, just validate plumbing
* `--per-folder-timeout=15` -- shorter timeout per folder
* `--json` -- machine-readable output to stdout
* `--skip-imap` -- offline mode for plumbing-only checks

Exit code 0 = all PASS; 1 = at least one FAIL. Log file path is shown
in the header and the summary footer.

## 2. Read structured logs

Every `ALL_MAIL` event is one JSON object on a line tagged with
`[ALLMAIL]`. Useful greps against
`/var/www/vps-email/backend/logs/php_errors.log`:

```
# All events for one request:
grep -F '"request_id":"req_<id>"' php_errors.log

# Where did we drop into a fallback tier?
grep -F '"evt":"allmail_fallback"' php_errors.log

# Folders that disappeared from imap_list:
grep -F '"to_state":"deleted"' php_errors.log

# Truncation events (memory pressure hit a cap):
grep -F '"evt":"truncation"' php_errors.log

# Invariant violations (treat as P1):
grep -F '"evt":"allmail_invariant_violation"' php_errors.log
```

Canonical keys (always emitted in order):

* `evt`               event name
* `request_id`        ULID prefixed with `req_`
* `account_id`        user_email
* `folder_id`         UUIDv7 (Wave 2+)
* `folder_path`       canonical path
* `scan_mode`         all_mail | folder_view
* `fallback_stage`    full_range | binary_split | chunk_50 | per_uid
* `duration_ms`       integer milliseconds
* `from_state` / `to_state` state machine transitions
* `reason`            human-readable string

## 3. degraded_folders[] payload

Field reference (sent on every `GET /mailbox/search?all_folders=true` response):

```
folder_path             string   IMAP path (UTF-8)
folder_display          string   user-friendly name
folder_id               string?  UUIDv7 (null in Wave 1 dual-write)
state                   string   degraded | quarantined
total                   int      imap_num_msg result
retrieved               int      number of UIDs we managed to parse
bad_uids                int[]    first <=50 unparseable UIDs
bad_uids_truncated_count int     remaining count past the cap
last_attempt_at         string   ISO-8601 UTC
retry_after             string?  ISO-8601 UTC when breaker is open
failure_reason          string?  human description
fallback_stage          string?  tier that supplied the data
request_id              string   ULID for log correlation
```

## 4. Run the daily coverage cron manually

```
/usr/local/lsws/lsphp83/bin/php \
  /var/www/vps-email/backend/cron/all-mail-coverage-report.php
```

Writes a single line to
`/var/www/vps-email/backend/storage/logs/all-mail-coverage-report.log`
and emails the admin only when a degrade occurred in the last 24 hours.

## 5. Check the dual-write readiness gate (Wave 2 cutover)

```
/usr/local/lsws/lsphp83/bin/php \
  /var/www/vps-email/backend/cron/dual-write-readiness.php
```

State file:
`/var/www/vps-email/backend/storage/logs/dual-write-readiness.json`.
Cutover is safe only when `streak >= 7` AND `cutover_safe = true`.

## 6. Roll back the canonical-routing flag (Wave 3)

If `ff_canonical_folder_routing` causes a regression in compare or on
mode, switch it back to `off`:

```
# In a browser DevTools console for the affected user:
localStorage.setItem('ff_canonical_folder_routing', 'off');
```

For a global rollback, deploy a build that pins the flag to `off` in
`mailRouteService.canonicalFolderRoutingMode()` until the underlying
issue is resolved.

## 7. Manually quarantine / un-quarantine a folder

Operator tooling lives in the `FolderStateMachine` service. Use the
PHP REPL or a one-off script:

```php
$config = require '/var/www/vps-email/backend/src/config.php';
$redis = new \Webmail\Services\RedisCacheService($config);
$sm = new \Webmail\Services\FolderStateMachine($redis);
$accountKey = $redis->getUserHash('user@flowone.pro');
$sm->forceSet($accountKey, 'INBOX.Work.WhiteRabbit',
              \Webmail\Services\FolderStateMachine::IGNORED);
```

The five legal states are documented in
`backend/src/Services/FolderStateMachine.php`. `ignored` skips the
folder in ALL_MAIL until manually cleared.

## 8. Common failure modes

| Pattern | Diagnosis |
|---|---|
| One folder always falls to `per_uid` | Persistent corrupt header on a specific UID; investigate that UID with `imap_fetchheader` |
| Many folders trip the breaker simultaneously | IMAP server is overloaded or being restarted; watch for log spam, breaker jitter handles the recovery |
| `truncation` events keep firing | Mailbox is larger than `SCAN_MAX_UID_TRACK` (100k); review whether to bump or to add chunked pagination |
| Folder count mismatch (`allmail_invariant_violation`) | Treat as P1: a folder with messages produced no entries and no degraded meta |

## 9. Sources of truth

* Plan: `.cursor/plans/mailbox_folder_system_v2_a283e845.plan.md`
* Spec: `email-life.md` section 7
* Tests: `backend/tests/all-mail-coverage-test.php`
* Backend services:
  - `backend/src/Services/ImapService.php` (fetch ladder)
  - `backend/src/Services/CircuitBreaker.php`
  - `backend/src/Services/FolderStateMachine.php`
  - `backend/src/Services/CorrelationId.php`
  - `backend/src/Services/StructuredLog.php`
  - `backend/src/Services/FolderIndexService.php` (folder identity)
  - `backend/src/Services/FolderCacheInvalidator.php`
* Frontend services:
  - `frontend/src/services/folderIdentityService.js`
  - `frontend/src/services/mailRouteService.js`
  - `frontend/src/services/folderGroupingService.js`
  - `frontend/src/components/AllMailDegradedBanner.vue`
