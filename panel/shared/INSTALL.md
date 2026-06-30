# FlowOne Storage Phase 1 — Install Guide

Deploy steps for the foundation layer (shared library, sandboxed helper,
unprivileged monitor daemon, HMAC-signed authoritative state, operation
journal, operator CLI, chaos harness scaffolding).

Server: VPS at `flowone.pro`. Web stack: OpenLiteSpeed + lsphp83.

Local repo layout (the `shared/` library ships under `panel/`):

```
~~~~~FLOWONE~~~~~/
  email/
  panel/
    shared/          # this library, deployed via copy-panel.sh
```

Production layout (siblings under `/var/www/`):

```
/var/www/vps-email/   # email frontend + backend (deployed by copy-email.sh)
/var/www/vps-admin/   # panel dashboard + api + agent (deployed by copy-panel.sh)
/var/www/shared/      # this shared library (deployed alongside panel)
```

The composer autoloaders in `vps-email/backend/composer.json` and
`vps-admin/api/composer.json` use `../../shared/src/Storage/`, which
resolves to `/var/www/shared/` only because of this sibling layout.
The panel agent's `spl_autoload_register` resolves the same way.

---

## 1. Upload the shared/ tree

SFTP-upload your local `panel/shared/` directory to the panel staging
area:

```
/home/panel.devcon1.hu/public_html/shared/
```

Then run the panel deploy script on the server. It detects `shared/` in
staging and invokes `copy-shared.sh` automatically (which rsyncs to
`/var/www/shared/`, fixes ownership, regenerates both backend
autoloaders, and restarts the daemons):

```bash
sudo bash /home/panel.devcon1.hu/public_html/copy-panel.sh
```

Verify the structure is complete:

```bash
ls -la /var/www/shared/
# Expected: bin/  composer.json  config/  copy-shared.sh  docs/  INSTALL.md  src/  systemd/  tests/
```

(You can also deploy shared/ standalone with
`sudo bash /home/panel.devcon1.hu/public_html/shared/copy-shared.sh`
if you don't want to redeploy the whole panel.)

## 2. Create the unprivileged daemon user

The monitor daemon runs as `flowone-storage:flowone-storage`. The helper
runs as root but accepts socket connections only from the
`flowone-storage` UID via SO_PEERCRED.

We deliberately do NOT reuse any pre-existing `flowone` account on the
VPS. Older `flowone` users on this server were created for unrelated
purposes (FTP, www-data sharing) and carry supplementary group
memberships that would widen the daemon's blast radius if compromised.
Use a clean, single-purpose user.

```bash
sudo useradd --system --no-create-home --shell /usr/sbin/nologin flowone-storage
id flowone-storage
# expected: uid=NNN(flowone-storage) gid=NNN(flowone-storage) groups=NNN(flowone-storage)
```

If the command says the user already exists from a prior attempt:

```bash
# Confirm the existing user is clean (only its own primary group):
id flowone-storage
# If extra groups are present, remove them:
# sudo gpasswd -d flowone-storage <group>
```

## 3. Generate the HMAC secret

This key signs and verifies all state payloads. Treat it like any other
cryptographic key (do not commit, do not log, do not paste in chat).

```bash
sudo mkdir -p /etc/flowone
sudo chgrp flowone-storage /etc/flowone
sudo chmod 0750 /etc/flowone

sudo install -m 0640 -o root -g flowone-storage /dev/null /etc/flowone/state.key
openssl rand -hex 32 | sudo tee /etc/flowone/state.key >/dev/null

ls -l /etc/flowone/state.key
# expected mode: -rw-r----- 1 root flowone-storage 65 ...
```

## 4. Create runtime directories

```bash
sudo install -d -m 0755 -o root -g root                       /var/lib/flowone
sudo install -d -m 0755 -o flowone-storage -g flowone-storage /var/log/flowone
sudo install -d -m 0755 -o flowone-storage -g flowone-storage /var/log/flowone/chaos
sudo install -d -m 0755 -o root -g root                       /run/flowone

# Mount lock file lives under /var/lock; created at first use.
sudo install -d -m 0755 -o root -g root                       /var/lock

# /var/lib/flowone needs to be writable by the daemon for state files.
sudo chown root:flowone-storage /var/lib/flowone
sudo chmod 0775 /var/lib/flowone
```

## 5. Install systemd units

```bash
sudo install -m 0644 /var/www/shared/systemd/flowone-storage-helper.service     /etc/systemd/system/
sudo install -m 0644 /var/www/shared/systemd/flowone-storage-monitord.service   /etc/systemd/system/

sudo systemctl daemon-reload
```

### Audit the sandboxes

```bash
sudo systemd-analyze security flowone-storage-helper.service
sudo systemd-analyze security flowone-storage-monitord.service
```

Target: overall exposure score ≤ 2.0 for both. The helper will be a bit
higher because it needs `CAP_SYS_ADMIN` for mount, but the seccomp filter
keeps the exploitable surface tight.

## 6. Smoke-test the binaries before enabling

Run each daemon in the foreground once to confirm config + key load:

```bash
sudo -u flowone-storage /usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/storage-monitord.php &
HELPER_PID_TEST=$(sudo /usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/storage-helper.php & echo $!)
sleep 3
sudo /usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/storage-ctl.php status
sudo /usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/storage-ctl.php helper ping

# Stop both, then start under systemd in step 7.
sudo kill $HELPER_PID_TEST
sudo pkill -f storage-monitord.php
```

## 7. Enable and start under systemd

```bash
sudo systemctl enable --now flowone-storage-helper.service
sudo systemctl enable --now flowone-storage-monitord.service

sudo systemctl status flowone-storage-helper.service
sudo systemctl status flowone-storage-monitord.service
```

Watch the journal logs for the first probe cycles:

```bash
sudo journalctl -u flowone-storage-monitord.service -f
sudo journalctl -u flowone-storage-helper.service -f
```

Expect:

- `monitord_started` (boot_epoch=1 if this is the first run)
- one `nas_status_change` if anything wasn't healthy at startup
- regular probe activity (no entry per probe; entries are only on change)

## 8. Wire the email backend autoloader

```bash
cd /var/www/vps-email/backend
composer dump-autoload
```

That's it. `Webmail\Services\NasHealthCheck` now delegates to the shared
daemon (controlled by `phases.phase1_shared_health = true` in
`/var/www/shared/config/storage.php`).

## 9. Wire the panel API autoloader

```bash
cd /var/www/vps-admin/api
composer dump-autoload
```

The panel agent uses its own `spl_autoload_register` — no composer step
needed there; the agent's autoloader was extended to resolve
`FlowOne\Storage\*` from the shared library automatically.

## 10. Restart PHP workers

OpenLiteSpeed will pick up the new autoloader on next request. To force:

```bash
sudo /usr/local/lsws/bin/lswsctrl restart
```

## 11. Validate end-to-end

```bash
# Status from the operator's perspective.
sudo /usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/storage-ctl.php status

# Same status reached through the panel dashboard's NAS health widget
# (uses NasMonitorAction.getStatus, which now delegates to the daemon).
curl -k https://flowone.pro/panel/api/v1/system/nas/health | jq

# Same status from the email backend perspective:
# NasHealthCheck::isAvailable() returns true if status === 'healthy'.
sudo /usr/local/lsws/lsphp83/bin/php -r "
  require '/var/www/vps-email/backend/vendor/autoload.php';
  var_dump(Webmail\Services\NasHealthCheck::isAvailable());
"
```

All three must report the same logical answer. If they don't, you have
either a config drift or the daemon isn't running yet — check journals.

## 12. Run the Phase 1 test suite

```bash
sudo /usr/local/lsws/lsphp83/bin/php \
  /var/www/shared/tests/foundation-test.php --verbose
```

Expected exit code 0. Logs land in `/var/log/flowone/foundation-test-*.log`.

## 13. Optional: enable chaos mode (for chaos harness runs)

The chaos suite is OPT-IN. Without this flag, every scenario refuses to
run. The flag also surfaces a banner on the dashboard while active.

```bash
sudo /usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/storage-ctl.php chaos enable
```

Then run a scenario, e.g.:

```bash
sudo /usr/local/lsws/lsphp83/bin/php \
  /var/www/shared/tests/chaos/scenario_vpn_drop.php \
  --i-understand-this-is-live --tenant=chaos-test
```

Disable when done:

```bash
sudo /usr/local/lsws/lsphp83/bin/php /var/www/shared/bin/storage-ctl.php chaos disable
```

## Rollback

If anything misbehaves, flip the kill switch in
`/var/www/shared/config/storage.php`:

```php
'phases' => [
    'phase1_shared_health'   => false,   // back to legacy in-process probes
    ...
],
```

…and restart PHP workers. `NasHealthCheck` will skip the daemon and use
its embedded legacy probe. The daemon can be left running for telemetry
or stopped:

```bash
sudo systemctl stop flowone-storage-monitord.service flowone-storage-helper.service
```

## Common troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `status` says `unknown / source=default` | Daemon not running or HMAC key wrong | `systemctl status flowone-storage-monitord` and re-check key mode (0640 root:flowone-storage) |
| `helper ping` fails | Helper not running, socket missing, or peer mismatch | Check `flowone-storage` user exists; check `/run/flowone/storage-helper.sock` permissions |
| Frequent `helper_peer_rejected` in journal | Caller running as a different UID than `flowone-storage` | Make sure the monitor daemon's `User=` in the unit matches `helper.allowed_peer_user` in config |
| systemd unit refuses to load with seccomp error | Old kernel without seccomp filter support | Reduce/remove `SystemCallFilter` lines (you lose defence-in-depth but daemon still works) |

## What's NOT included in Phase 1

These land in later phases — do NOT add them ad-hoc:

- 6-state status enum (Phase 2)
- Recovery + read circuit breakers (Phase 2)
- Two-tenant layout (`/drive`, `/backups` subpaths) (Phase 3)
- `tier_state` schema in `drive_files` (Phase 4)
- VPS-first uploads + tier-down worker (Phase 5)
- Hot-tier GC + admission control + restore scheduler (Phase 6)
- Backup pipeline hardening (Phase 7)
- New frontend banners + badges (Phase 8)

Each phase has its own kill switch in `shared/config/storage.php` so
rollback is one flag flip.
