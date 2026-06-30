# Storage Activation Runbook — Phase 5 / 6 / 7

Walks you from a system where everything is dormant (kill switches OFF)
through to a fully live tiered-storage + reclaim-daemon + NAS-backup
deployment. Every step is a copy-paste shell block. Re-runnable.

> **Read the whole document before you start.** Phase 5 (`destructive`)
> deletes the VPS copy of cold files after they've been mirrored to NAS.
> If the NAS is in a weird state when Phase 5 runs, you can lose bytes.
> The pre-flight test in Step 1 exists to catch that BEFORE you flip.

---

## 0. Server context

- All paths assume the live VPS layout (`/var/www/vps-email/backend`,
  `/var/www/shared/bin`, `/var/lib/flowone`, `/mnt/nas-drive`,
  `/mnt/vps-backup`).
- PHP: `/usr/local/lsws/lsphp83/bin/php`.
- The shared storage library lives at `/var/www/shared/` (symlinked from
  the `panel/shared` directory in this repo).
- The web user is `mailflow` (LSWS) — adjust if yours differs.
- Daemon user is `flowone-storage` (created during Phase 1 install).

---

## 1. One-time prerequisites (run once, then never again)

Everything in this section is **idempotent**: safe to re-run.

### 1.1 — (the original "create healthcheck files" step is now folded into
###       Section 1.4 because permissions must be set up first.)

### 1.2 Make state.dir + .env + log dir accessible to web user AND mailflow

So the panel can write flag files, the cron tier-down worker can read `.env`,
and every process can append to the journal. We add both `mailflow` (the
cron + LSWS user) and the LSWS process to the `flowone-storage` group.

```bash
# Web user joins daemon group (covers the LSWS PHP process AND cron jobs run
# as mailflow). The shell will only see the new group on next login, but
# spawned processes (PHP via cron / LSWS workers) see it after restart.
sudo usermod -a -G flowone-storage mailflow

# state.dir: where pause/freeze flags + daemon state files live.
sudo chmod 0775 /var/lib/flowone
sudo chgrp flowone-storage /var/lib/flowone

# .env: the daemon needs to read DB creds from here, BUT do NOT change the
# file's group ownership — on multi-tenant LSWS boxes the existing group
# (typically www-data) is what the per-vhost PHP workers rely on to read it.
# Use POSIX ACLs (additive) so flowone-storage gets read access WITHOUT
# disturbing whoever was reading it before.
#
# The LSWS workers serving flowone.pro almost always run as `nobody` (UID
# 65534) because the default httpd_config.conf has `user nobody` and most
# vhosts don't override with extUser/extGroup. We grant ALL three relevant
# identities explicit read so any future vhost-user change still works.
sudo setfacl -m g:flowone-storage:r /var/www/vps-email/backend/.env
sudo setfacl -m u:nobody:r          /var/www/vps-email/backend/.env
# If your flowone.pro vhost has its own suEXEC user (e.g. cosycanusa-style),
# grant it too — find it with: ps -eo user,cmd | grep lsphp | grep flowone
# Then: sudo setfacl -m u:<that-user>:r /var/www/vps-email/backend/.env

# Verify both can read
sudo getfacl /var/www/vps-email/backend/.env
sudo -u flowone-storage cat /var/www/vps-email/backend/.env | head -1
sudo -u nobody          cat /var/www/vps-email/backend/.env | head -1

# HMAC key already lives at 0640 root:flowone-storage — verify:
sudo stat -c '%a %U:%G' /etc/flowone/state.key
# Expected: 640 root:flowone-storage

# Restart LSWS + cron so child processes pick up the new group membership.
# IMPORTANT: systemctl restart lsws only restarts the master; LSPHP workers
# can persist with stale env. Force them to respawn:
sudo systemctl restart lsws cron
sudo pkill -9 lsphp || true
sleep 2

# Smoke test: bootstrap should return 401 (no auth) — NOT 500 (broken backend)
curl -s -o /dev/null -w "API health: %{http_code}\n" https://flowone.pro/api/bootstrap
# Expected: 401
```

### 1.3 Create the dispatcher queue + backup destination + log dirs

```bash
# Dispatcher queue (panel writes here; dispatcher reads + removes)
sudo install -d -o flowone-storage -g flowone-storage -m 0775 /var/lib/flowone/requests
sudo install -d -o flowone-storage -g flowone-storage -m 0775 /var/lib/flowone/requests/failed

# Backup snapshot destination subdir (the mount root is NFS-owned, so chown
# may report "Operation not permitted" — that's fine, mkdir still succeeds
# and the resulting 0777 NFS perms let flowone-storage write).
sudo install -d -o flowone-storage -g flowone-storage -m 0775 /mnt/vps-backup/drive-snapshots 2>/dev/null || true
ls -ld /mnt/vps-backup/drive-snapshots   # should exist, mode 0775 or 0777

# Log dir + pre-created log files (so cron jobs running as mailflow can
# append; without these the files would be created with wrong perms).
sudo install -d -o flowone-storage -g flowone-storage -m 0775 /var/log/flowone
sudo touch /var/log/flowone/storage-journal.jsonl /var/log/flowone/drive-tier-down.log
sudo chown flowone-storage:flowone-storage /var/log/flowone/storage-journal.jsonl /var/log/flowone/drive-tier-down.log
sudo chmod 0664 /var/log/flowone/storage-journal.jsonl /var/log/flowone/drive-tier-down.log
```

### 1.4 Healthcheck files (one-time)

```bash
sudo touch /mnt/nas-drive/.healthcheck /mnt/vps-backup/.healthcheck
sudo chmod 0644 /mnt/nas-drive/.healthcheck /mnt/vps-backup/.healthcheck
```

### 1.5 Install the dispatcher cron

```bash
sudo tee /etc/cron.d/flowone-storage <<'CRON'
# FlowOne storage dispatcher — picks up panel-queued snapshot/verify/drill requests.
* * * * * flowone-storage /usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/storage-request-dispatcher.php >> /var/log/flowone/dispatcher.log 2>&1
CRON
sudo chmod 0644 /etc/cron.d/flowone-storage
sudo systemctl restart cron
```

### 1.5 Run the pre-flight test

This is the single check that proves the box is ready. **Do not flip
any phase flag if this fails.**

```bash
/usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/storage-activation-test.php --verbose
```

You should see green PASS lines under every category and `failed: 0`
in the summary. If anything is RED, fix it before going further.

---

## 2. Phase 7 — NAS backup (lowest risk, do first)

Phase 7 is read-only on the source. It rsync's `/mnt/nas-drive` to
`/mnt/vps-backup` and writes signed manifests. Nothing is deleted from
either side until retention kicks in (which only ever removes from the
backup volume, never from NAS).

### 2.1 Flip the kill switch

The phase flags live in `panel/shared/config/storage.php`. The easiest
override mechanism is `/etc/flowone/storage.local.php`, which is merged
on top of the base config.

```bash
sudo install -d -m 0755 /etc/flowone
sudo tee /etc/flowone/storage.local.php <<'PHP'
<?php
return [
    'phases' => [
        'phase7_nas_backup' => true,
    ],
];
PHP
sudo chmod 0644 /etc/flowone/storage.local.php

# Confirm it's loaded.
sudo -u flowone-storage /usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/storage-ctl.php backup status
# Expected: "kill switch: ON"
```

### 2.2 Run the first snapshot manually (one-shot, foreground)

This bypasses the dispatcher so you can watch it run. It may take
hours depending on data size — that's why the runner has a
4-hour default cap.

```bash
sudo -u flowone-storage /usr/local/lsws/lsphp83/bin/php \
    /var/www/shared/bin/nas-backup.php snapshot --apply
```

### 2.3 Verify the snapshot

```bash
sudo -u flowone-storage /usr/local/lsws/lsphp83/bin/php \
    /var/www/shared/bin/nas-backup.php verify --date=$(date -u +%F)
```

### 2.4 Run a restore drill

```bash
sudo -u flowone-storage /usr/local/lsws/lsphp83/bin/php \
    /var/www/shared/bin/nas-backup.php drill
```

### 2.5 Schedule daily snapshots + retain + drill

```bash
sudo tee /etc/cron.d/flowone-nas-backup <<'CRON'
# FlowOne NAS backup pipeline — daily snapshot + retention + restore drill.
0  4 * * * flowone-storage /usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/nas-backup.php snapshot --apply >> /var/log/flowone/backup.log 2>&1
0  6 * * * flowone-storage /usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/nas-backup.php retain   --apply >> /var/log/flowone/backup.log 2>&1
30 7 * * 1 flowone-storage /usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/nas-backup.php drill            >> /var/log/flowone/backup.log 2>&1
CRON
sudo chmod 0644 /etc/cron.d/flowone-nas-backup
sudo systemctl restart cron
```

### 2.6 Verify from the panel

Open `https://flowone.pro/settings?tab=storage-admin`. The Backup
Pipeline card should now show:

- `kill switch: ON` (no red badge)
- A `Last snapshot` row with today's date
- A `Last verify: OK` row
- A `Last drill: OK` row

Click **Verify** in the Backup card → confirm modal → check the
toast. Within ~60s the dispatcher will pick it up and the card will
update with a fresh `Last verify` timestamp.

---

## 3. Phase 6c — Reclaim daemon (safe, but starts moving bytes)

The reclaim daemon proactively tier-down's old files when budget
pressure crosses WM_HIGH. As long as Phase 5c is OFF, this is
**non-destructive** — files are COPIED to NAS and marked `cold`, but
the VPS copy is left in place.

### 3.1 Install the systemd unit

```bash
sudo tee /etc/systemd/system/flowone-reclaim-daemon.service <<'UNIT'
[Unit]
Description=FlowOne reclaim daemon (storage tier-down under pressure)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=flowone-storage
Group=flowone-storage
ExecStart=/usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/reclaim-daemon.php
Restart=on-failure
RestartSec=10
StandardOutput=append:/var/log/flowone/reclaim-daemon.log
StandardError=append:/var/log/flowone/reclaim-daemon.log

[Install]
WantedBy=multi-user.target
UNIT
sudo systemctl daemon-reload
```

### 3.2 Flip Phase 6c ON

```bash
sudo tee /etc/flowone/storage.local.php <<'PHP'
<?php
return [
    'phases' => [
        'phase7_nas_backup'      => true,
        'phase6c_reclaim_daemon' => true,
    ],
];
PHP
```

### 3.3 Start the daemon

```bash
sudo systemctl enable --now flowone-reclaim-daemon
sleep 5
sudo systemctl status flowone-reclaim-daemon --no-pager
sudo -u flowone-storage /usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/storage-ctl.php reclaim status
```

Within ~60 seconds the panel's **Reclaim Daemon** card should flip
from `No state published yet` to a live `state` badge.

---

## 4. Phase 5a + 5b — Tier-down (shadow mode, safe)

These are already ON in the base config (`phase5_tier_down_shadow=true`).
Verify with the pre-flight test category `phases`. No action required
unless you've explicitly turned them off.

To activate Phase 5b (drive recall) so the user-facing download flow
recalls cold files automatically:

```bash
sudo tee /etc/flowone/storage.local.php <<'PHP'
<?php
return [
    'phases' => [
        'phase7_nas_backup'      => true,
        'phase6c_reclaim_daemon' => true,
        'phase5b_drive_recall'   => true,
    ],
];
PHP
```

---

## 5. Phase 6d — LRU-aware selection (safe)

Reorders tier-down candidate selection to prefer least-recently-read
files. Requires migration 168 (run automatically by `MigrationService`
on next API request, or kick it manually). Safe to flip the moment 168
has run.

```bash
# Verify migration 168 is applied:
mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' devc_vps_dash \
    -e "SELECT name FROM migrations WHERE name LIKE '168%'"

# Flip on:
sudo tee /etc/flowone/storage.local.php <<'PHP'
<?php
return [
    'phases' => [
        'phase7_nas_backup'      => true,
        'phase6c_reclaim_daemon' => true,
        'phase5b_drive_recall'   => true,
        'phase6d_lru_selection'  => true,
    ],
];
PHP
```

---

## 6. Phase 6b — Admission control (returns 503 under pressure)

Rejects new Drive uploads with HTTP 503 + Retry-After when the
storage budget is critical. **Wait until you've watched the budget
numbers for at least 48 hours** before flipping this — it has user-
visible behaviour (uploads can fail).

```bash
sudo tee /etc/flowone/storage.local.php <<'PHP'
<?php
return [
    'phases' => [
        'phase7_nas_backup'         => true,
        'phase6c_reclaim_daemon'    => true,
        'phase5b_drive_recall'      => true,
        'phase6d_lru_selection'     => true,
        'phase6b_admission_control' => true,
    ],
];
PHP
```

---

## 7. Phase 5c — Destructive tier-down (DELETES VPS BYTES)

> **This is the only irreversible phase.** After this is ON, the
> nightly tier-down worker will `unlink()` the VPS copy of files that
> have been in `cold` state longer than
> `tier.destructive_grace_hours` (default 24). The bytes still exist
> on NAS — but if NAS is broken, you can't get them back. Period.

**Do not flip Phase 5c until ALL of the following are true:**

1. Phase 7 has been running for at least **two weeks** with zero
   `last_snapshot_failed` entries in the backup state.
2. At least **three successful restore drills** have run (check
   `backup.state.drill_history`).
3. The most recent verify (`Verify` button in the panel) reports
   100% match.
4. You have a tested off-site backup of the NAS itself (Synology
   Hyper Backup / rsync to a second box).
5. The reclaim daemon (Phase 6c) has been ON for at least a week
   with zero `tier_failed` counters.

When ready:

```bash
sudo tee /etc/flowone/storage.local.php <<'PHP'
<?php
return [
    'phases' => [
        'phase7_nas_backup'             => true,
        'phase6c_reclaim_daemon'        => true,
        'phase5b_drive_recall'          => true,
        'phase6d_lru_selection'         => true,
        'phase6b_admission_control'     => true,
        'phase5_tier_down_destructive'  => true,
    ],
];
PHP
```

After flipping, re-run the pre-flight test and watch
`/var/log/flowone/reclaim-daemon.log` and the panel's Reclaim Daemon
card for the first 24 hours. If anything looks wrong, hit the
**Freeze** button in the dashboard immediately.

---

## 8. Emergency procedures

### 8.1 Freeze everything (from the panel)

The dashboard header has a red **Freeze** button. One click +
type `FREEZE` to confirm. This writes `/var/lib/flowone/freeze.flag`
which every subsystem checks before any NAS write.

### 8.2 Freeze from CLI (if the panel is down)

```bash
sudo -u flowone-storage /usr/local/lsws/lsphp83/bin/php \
    /var/www/shared/bin/storage-ctl.php freeze --reason="manual incident response"
```

### 8.3 Stop the reclaim daemon

```bash
sudo systemctl stop flowone-reclaim-daemon
```

### 8.4 Pause backups (next cron tick will skip)

```bash
sudo -u flowone-storage /usr/local/lsws/lsphp83/bin/php \
    /var/www/shared/bin/storage-ctl.php backup pause --reason="..."
```

### 8.5 Roll back a phase

Edit `/etc/flowone/storage.local.php` and set the flag back to
`false`. Save, then re-run the activation test:

```bash
/usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/storage-activation-test.php --verbose
```

Daemons re-read config on next tick (Phase 6c: 60s; cron: 1 minute).

---

## 9. Verifying everything from the panel

Open `https://flowone.pro/settings?tab=storage-admin`. You should see:

- **Infrastructure card**: NAS mount green, Backup mount green, VPN
  green, DDNS resolved, Dispatcher queue 0 pending.
- **Storage Budget**: watermark `clear` (green badge).
- **Reclaim Daemon**: `state: idle` or `cooldown`, no `unverified` pill.
- **Backup Pipeline**: `Last snapshot` recent, `Last verify: OK`,
  `Last drill: OK`, no `unverified` pill.
- **Files by Tier State**: hot rows present; cold rows appearing only
  after Phase 6c starts moving things.
- **Phase Flags**: every flag you flipped shows ✓.

The action buttons live in the Reclaim and Backup cards. Pause/resume
are synchronous (200ms); snapshot/verify/drill are queued and run
within ~60s.

---

## 10. Real-world gotchas (battle-tested 2026-05-18)

These were the issues we hit on the first live activation. The runbook
above already incorporates the fixes, but they're documented here in
case the same symptoms appear on another box.

### `vpn/interface_up` FAILs even though NFS works

`tun0` reports `operstate: unknown` because Linux can't sense carrier on
virtual point-to-point devices. The fix is in `storage-activation-test.php`
and `StorageController::probeVpn()`: fall back to reading
`/sys/class/net/<iface>/flags` and checking bit `0x1` (IFF_UP). If you see
this failure with an older test script, redeploy.

### `[reclaim-daemon] email config missing db.database / db.user`

Two bugs combined:
- The email config exposes `db.name` / `db.pass`, not `db.database` / `db.password`.
- The daemon (systemd) doesn't have `.env` loaded.

Both are fixed in `reclaim-daemon.php` — it now loads `.env` via
`loadDotEnv()` and checks both spellings. The daemon process also needs
read access to `.env`, which Section 1.2 grants via `chgrp flowone-storage
+ chmod 0640`.

### `chown: invalid group: 'mailflow:mailflow'`

`mailflow` is the user; its primary group on this box is `sftpusers`. We
don't actually need that chown — pre-create files as
`flowone-storage:flowone-storage` mode `0664` and add `mailflow` to the
`flowone-storage` group. Section 1.3 does this.

### `[OperationJournal] cannot open /var/log/flowone/storage-journal.jsonl`

Same root cause as above: `mailflow` wasn't in `flowone-storage`. After
running Section 1.2 + 1.3 and restarting cron, the journal opens cleanly.

### `backup/destination_root must exist`

The mount root `/mnt/vps-backup` exists but the snapshot subdir
`/mnt/vps-backup/drive-snapshots` doesn't. Section 1.3 creates it.
`chown` may report "Operation not permitted" on the NFS mount — that's
expected (NFS root_squash); the resulting `0777` perms are wide enough.

### Drill says `FAIL: manifest_empty` on a fresh install

The snapshot ran against an empty `/mnt/nas-drive/drive/` directory.
Drop a canary file (`flowone-canary-YYYYMMDD.txt`) and re-snapshot. The
canary is the test described in the original "Step 3" walkthrough above.
Once Phase 5/6 start tiering real files, the canary becomes redundant.

### `drive_files.file_path` column doesn't exist

The real schema is `filename` + `nas_relative_path`. The VPS shadow
lives at `{storage_path}/{user_hash}/{filename}` (e.g.
`/var/www/vps-email/storage/drive/c015c.../be9be.....png`). When
inspecting a tiered file, use:

```sql
SELECT id, user_email, filename, original_name, size, tier_state,
       storage_location, nas_relative_path, tier_changed_at, checksum
FROM drive_files WHERE id = <ID>\G
```

### NEVER `chgrp` .env — use ACLs

This one bit us hard. On a multi-tenant LSWS box the email backend `.env`
is read by multiple identities at runtime:

  - `nobody` (UID 65534) — the default LSWS PHP worker user serving
    flowone.pro vhost
  - `www-data` (UID 33) — the docroot owner (and group of `.env`)
  - per-vhost suEXEC users (e.g. `cosycanusa` for cosycanusa.com) IF you
    configured the vhost with `extUser`
  - `mailflow` — cron jobs (process-scheduled-emails.php etc.)
  - `flowone-storage` — reclaim daemon + dispatcher (NEW, what we added)

If you `chgrp flowone-storage .env`, you steal the `www-data` group
membership from whatever WAS using it (typically the LSWS workers
running as `nobody`). The result is OAuth canary fails in
`public/index.php` line 230, every API request returns 500 with
`Server misconfiguration (OAuth encryption)`, and you scramble for an
hour to figure out why.

**Always use POSIX ACLs to grant access.** They are additive — you can
grant any number of users/groups read without disturbing existing
permissions:

```bash
sudo setfacl -m g:flowone-storage:r /var/www/vps-email/backend/.env
sudo setfacl -m u:nobody:r          /var/www/vps-email/backend/.env
```

Note that ACLs survive reboots and most rsync deploys (rsync needs
`-A` / `--acls` to copy them; check your deploy script). If your
deploy script overwrites `.env` from scratch, re-run the setfacl
commands after every deploy that touches `.env`.

### `systemctl restart lsws` is not enough — pkill the LSPHP workers too

The LSWS master process re-reads its own config on restart, but LSPHP
child processes can persist with cached env and config. When you change
file perms or .env contents, do BOTH:

```bash
sudo systemctl restart lsws
sudo pkill -9 lsphp
```

The next request will spawn fresh workers that read current state.

### How to PROVE Phase 5a is working

After running tier-down on one file, the three-way md5 check is the
authoritative proof:

```bash
FILE_ID=<id>
RELPATH=$(mysql -u vpsadmin -p'...' devc_vps_dash -N -e "SELECT nas_relative_path FROM drive_files WHERE id=${FILE_ID}")
FILENAME=$(mysql -u vpsadmin -p'...' devc_vps_dash -N -e "SELECT filename FROM drive_files WHERE id=${FILE_ID}")
CHECKSUM=$(mysql -u vpsadmin -p'...' devc_vps_dash -N -e "SELECT checksum  FROM drive_files WHERE id=${FILE_ID}")
VPS=$(sudo find /var/www/vps-email/storage -name "${FILENAME}" -type f | head -1)
NAS="/mnt/nas-drive/${RELPATH}"

echo "VPS md5:    $(sudo md5sum "$VPS" | awk '{print $1}')"
echo "NAS md5:    $(sudo md5sum "$NAS" | awk '{print $1}')"
echo "DB checksum: $CHECKSUM"
```

All three must be identical. If any diverge with Phase 5c OFF, freeze
immediately.
