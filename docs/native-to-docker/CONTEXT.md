# Native-to-Docker Migration — Handoff Context

> Companion to `PLAN.md`. Captures the chat discussion/decisions that produced and validated
> the plan, so a fresh agent in any clone of the repo has full context. Read `PLAN.md` first.

## Status / where we are

- Plan authored and validated against the codebase (see `PLAN.md` → "What validation changed").
- Cursor plan id: `native_to_docker_transition_138837d6`
  (file: `c:\Users\KITCHEN\.cursor\plans\native_to_docker_transition_138837d6.plan.md`).
- This repo (`flowonedev/flowone-docker`) is a fresh, single-commit baseline split off from the
  production `flowonedev/flowone` repo specifically for the Docker migration.

### Done so far

- **Milestone 1 (local stack + login):** DONE. `email/docker-compose.local.yml` grown to
  PHP 8.3 + OpenLiteSpeed/lsphp83 + MariaDB 10.11 (tmpfs for dev I/O) + Redis + Meilisearch +
  Vite frontend + collab + mailsync. App opens locally and logs in against the dev mail server.
- **Phase A landmine fixes:** DONE (collab WS URL, OAuth/share/webhook flowone.pro origins,
  OpenDKIM 8891 / OpenDMARC 8893 port drift, LiveKit ws_url guard, turn.{domain} DNS seed).
- **Phase B app tier:** DONE + smoke-tested. Production images `flowone-web` (multi-stage:
  Vite build -> OLS/lsphp83 8.3 runtime, composer `--no-dev`, baked frontend), `flowone-collab`,
  `flowone-mailsync`, plus `email/docker/docker-compose.yml` (bridge net: mariadb/redis/meili/
  web/collab/mailsync). Full stack boots clean; login works end-to-end.
- **Perf hardening:** MigrationService cross-worker `GET_LOCK` (no cold-start migration herd);
  `SchemaGuard` gates ~44 services' per-request self-heal DDL behind a version marker
  (`/api` ~16s -> ~15ms locally; web healthcheck passes).
- **Phase C Layer 1:** DONE. `email/backend/tests/docker-stack-smoke-test.php` validates the
  running stack's connectivity + schema + secrets (16/16 green).
- **Phase C Layer 2 (local slice):** DONE. `email/backend/tests/docker-stack-functional-test.php`
  does real roundtrips: JWT sign/verify + tamper-reject, Redis set/get/del, Meili
  index/await/search; optional `--email=/--password=` login->me->logout. 4 pass + 1 skip (login
  needs creds).

- **Phase C migration tooling:** DONE (authored in `migration/`). `snapshot.sh` (old native box ->
  checksummed bundle: `mysqldump --all-databases` + vmail/homes/drive/certs/dkim/meili + the
  non-regenerable secrets), `restore.sh` (manifest verify -> DB into `mariadb` container + FS into
  volumes/host paths + secrets into `jwt_keys`/`/etc/flowone`, guarded by `--yes`/`--dry-run`),
  `db-parity-check.sh` (COMPARE old-vs-new + `--self-test`). DB dump->restore->parity self-test ran
  green locally (313 tables, exact row-count match; scratch DB auto-dropped). Root `.gitattributes`
  added so the `.sh` stay LF (authored on Windows, run on Linux).

- **Pre-VPS deep audit:** DONE (3 parallel audits + direct verification). Architecture sound; found
  and FIXED in one pass: **B1** was a FALSE ALARM later corrected — gd + zip are already compiled into
  the `litespeedtech/openlitespeed:1.8.5-lsphp83` base (`php -m` lists both) and there is NO
  `lsphp83-gd`/`lsphp83-zip` apt package; the interim "fix" that added those packages broke the build
  ("no installation candidate") and was reverted. `imap` IS absent from the base and is the one
  extension installed. Caught by a full local build of all three images. **B2** user-file trees
  (`/var/www/vps-email/storage` drive/mood/docs) had no persistent
  volume -> added `vps_email_files` mount + image dir/perms (were ephemeral, lost on recreate); **I1**
  `useOfficePresence.js` still hardcoded `wss://host:1234` -> now mirrors the `/collab-ws` proxy
  resolution; **I2** `DriveView.vue` desktop-app download hardcoded `flowone.pro` -> `VITE_DESKTOP_APP_URL`
  override (central vendor artifact, default kept); **I4** added a meilisearch healthcheck (web/collab/
  mailsync already had image HEALTHCHECKs — the audit's "no healthcheck" claim was compose-only); **I5**
  `provisionDocker()` now runs `ensure-schema.php` in the web container after health (deterministic
  schema warm-up; the web healthcheck already triggers base migrations). **I3** added
  `email/docker/gen-jwt-keys.sh` (DEV-ONLY, idempotent) so a plain local `docker compose up` can seed
  the `jwt_keys` volume — prod is seeded by Fleet/snapshot. docker-provisioning test 16/16; compose
  validates.
- **Registry round-trip (was B3/B4):** **B4 CLOSED** — added a `docker` config block to
  `fleet/api/config.php` (`registry` default `ghcr.io/flowonedev`, `tag` `latest`, `compose_path`
  repo-relative default); `DockerProvisioningService` already reads all three keys, so the provisioner
  now has a working compose source + image coordinates. **B3 IN PROGRESS** — added
  `email/docker/build-and-push.sh` (builds web/collab/mailsync from the `email/` context, `--push`
  optional, GHCR by default). All three images build locally (exit 0, ~8 min cold): **web 1.39 GB,
  mailsync 433 MB, collab 321 MB**. Decision: publish to **GHCR** (free for private images at our scale
  today, 1-month notice before metering; self-hosted registry as fallback). **B3 CLOSED** — pushed all
  three to `ghcr.io/flowonedev/flowone-{web,collab,mailsync}:latest` (private packages).
- **Registry round-trip VALIDATED (Layer 1/2, clean-room):** removed the local GHCR tags, re-pulled from
  the registry (genuine download), and `up -d` recreated the stack on the pulled images. All six services
  healthy (incl. meilisearch via the new healthcheck); the `vps_email_files` volume was created on first
  mount. In the running GHCR web image: `GET /api/auth/me` -> 401 and `GET /` -> 200 (app boots, router +
  DB + Redis answer, SPA served); `php -m` confirms gd/zip/imap/imagick/intl/pdo_mysql/redis loaded (B1
  laid to rest); `/var/www/vps-email/storage` is the persistent volume with drive/ + mood-uploads/ at 777
  and writable (B2). Local `.env` REGISTRY switched to `ghcr.io/flowonedev` so a plain `docker compose up`
  keeps using the published images.
- **Manual VPS dry-run tooling (Phase E step 1):** added `email/docker/vps-bootstrap.sh` (hand-run analog
  of `DockerProvisioningService`: installs Docker, `docker login` GHCR, mints fresh secrets + `.env`, seeds
  the JWT volume, pulls the published images, `compose up`, health-checks) + `docs/native-to-docker/DRY-RUN.md`
  runbook. Confirmed scope reality: **only the bridge stack is dockerized** — the host-networking pods
  (mail, PowerDNS, coTURN/LiveKit, OnlyOffice) are NOT authored yet (they exist only as a compose-header
  TODO), so the first dry-run validates real-Linux runtime + GHCR pull + TLS + domain routing for the
  webmail app, not mail/calls. The target-side `docker login` step in `vps-bootstrap.sh` is also the spec
  for the same gap in `DockerProvisioningService::provisionDocker()`.
- **First real-VPS dry-run PASSED (Ubuntu 22.04, 85.155.242.131, HTTP):** `vps-bootstrap.sh` on a fresh box
  installed Docker (v29.6.1), logged in to GHCR, minted `.env` + JWT, pulled the private images, brought
  the stack up — all six services healthy; from the public internet `GET / -> 200` and `GET /api/auth/me
  -> 401`. Two bugs the real box exposed (Windows clean-room missed them because its jwt volume was
  pre-seeded): JWT seeding used `node:20-bookworm-slim`, which does NOT ship the openssl CLI (`exit 127`).
  Fixed both `vps-bootstrap.sh` and `gen-jwt-keys.sh` to mint the pair via `alpine:3` + `apk add openssl`.
  Still TODO on this box: TLS pass (staging subdomain + certbot + `--ssl`), and there is no seeded user
  account yet so browser login needs a registration/seed step.

### Next action

Remaining tracks are gated on resources we don't have on Windows:

1. **Linux/staging-dependent (Phase E):** author + validate the host-networking pods (mail incl.
   Rspamd/ClamAV/unbound + 8891/8893 milters, powerdns gmysql, coturn/livekit) and onlyoffice;
   then the rest of Layer 2 (SMTP/IMAP roundtrip, Sieve, DKIM/DMARC, client WP HTTP 200) and Layer 3
   (run `db-parity-check.sh --source=... --target=...` old-box-DB vs new-box-DB, plus maildir byte
   counts + drive checksums). Docker Desktop on Windows can't run `network_mode: host` faithfully.
2. **Phase D Fleet refactor:** IN PROGRESS (see PLAN.md for the checklist). Done + tested off-box:
   `ComposeEnvRenderer` (Fleet-vars -> per-host `.env`, 18/18), `DockerProvisioningService` +
   `cli/provision-docker.php` (compose pull/up/health, 15/15), dead `panel_update`/`email_update`
   retired, docker-aware health for heartbeat + SSH probe (9/9, additive/gated so native boxes are
   untouched). All built as NEW modules — the native `ProvisioningService` is untouched, so the two
   provisioners run in parallel during cutover. STILL TODO: (a) generate+persist the non-regenerable
   crypto on a fresh box (`IMAP_ENCRYPTION_KEY`, `OAUTH_KEYS`, VAPID, `SSO_SERVER_KEY`, JWT PEMs —
   needs a servers-table migration) so the renderer's guards pass without a migrated snapshot, and
   seed the JWT pair into the `jwt_keys` volume; (b) wire a `DOCKER_PROVISION` type into the live
   dashboard (gated on Phase E validation). The SSH orchestration itself is only runnable against a
   real Linux target (Phase E).
3. **Migration delta-sync mode** (deferred): `snapshot.sh` is a full capture today; add an
   incremental/rsync-delta mode for the short cutover window.

## Repo setup history (how this repo was created)

- Started from a copy of the production `flowone` working tree.
- Re-initialized git (fresh history), removed 13 nested `.git` folders (`email/.git` + Composer
  vendor packages) that were blocking `git add`, committed a single baseline, created the
  `flowonedev/flowone-docker` GitHub repo, and pushed.
- Cleaned the baseline: untracked + ignored `email/backend/vendor/` (regenerated by
  `composer install`), `*.log` / `storage/logs/`, and mobile build artifacts (`*.apk/*.aab/*.ipa`);
  amended the initial commit and force-pushed. Tracked files 10,338 → 7,639; clone size ~320 → ~245 MB.
- Document folders (`EXCEL/`, `RADIO ADS/`, `FOR APP STORE/`, `FINAL CALCUALTGOR*.html`, `.docx`)
  were intentionally LEFT tracked — revisit if a leaner repo is wanted.

## Safety net (decided)

Two-part net — do NOT conflate code vs data:

- **CODE:** `git tag pre-docker-migration` + clean working tree. A duplicated project folder is
  redundant for code; if kept, keep it OUTSIDE the repo working tree so git/Cursor don't index two
  copies and you never edit the wrong one. The folder copy protects NOTHING on the live server.
- **DATA (the real risk):** a server data snapshot = DB (incl. per-site WP DBs + pdns gmysql tables),
  `/home/vmail`, `/home/{domain}`, drive + `/mnt/nas-drive`, `/etc/letsencrypt`, `/etc/opendkim/keys`,
  and the non-regenerable keys (`/etc/flowone/master.key`, `state.key`, Fleet `encryption.key`,
  `IMAP_ENCRYPTION_KEY`, all `OAUTH_KEYS` versions, JWT PEM pair). This snapshot is ALSO the migration
  seed. Regenerating any key bricks encrypted data / logs everyone out.

## Branching strategy (decided)

Most work goes straight on `main` — Docker artifacts are inert on the native box (Dockerfiles,
`docker-compose.yml`, entrypoints don't run unless invoked; `copy-email.sh` never touches them).
Isolate only the two things that change live behavior:

1. The Fleet `ProvisioningService` rewrite (separate branch / feature-flag).
2. The ~30-50 lines of landmine fixes (small standalone commits — they also help the native app).

This avoids a long-lived divergent branch.

## copy-email.sh -> Docker mapping (the build checklist)

The 671-line `copy-email.sh` is the SPEC for what the image + entrypoint must guarantee. Each manual
server step moves to either "baked at build" or "done once by entrypoint":

- Copy `dist/`, `backend`, `collab`, `mailsync`, `office`  -> baked into images (COPY + composer/npm install).
- `composer install` w/ lsphp83 + autoloader optimize (step 9) -> a build stage in the web Dockerfile.
- CRLF stripping (step 4.1) -> `.gitattributes`/`.dockerignore` + build; NO runtime sed.
- Strip `config.local.php` / `.env.local` (step 4.05) -> `.dockerignore` so they can't enter the image.
- Web-user ownership detection + storage dirs (steps 6, 6.5) -> container runs lsphp as a fixed user;
  entrypoint chowns mounted `storage/` volumes once. "Guess the owner" problem disappears.
- JWT public key + `PANEL_API_KEY` sync to collab/mailsync (steps 5.5, 5.6) -> shared read-only volume
  or baked + injected via `.env`.
- `systemctl restart` collab/mailsync/lsphp83/lsws (steps 5.5/5.6/11/12) -> `docker compose up -d`
  recreates affected containers; no systemd.

## Recommended next-steps order (milestones)

1. **MILESTONE 1 (do this first):** grow `email/docker-compose.local.yml` into production parity
   (PHP 8.3 + OLS/lsphp83 + MariaDB — not the 8.2/Apache/MySQL seed) + collab(:1234), mailsync(:1235),
   mail pod. Goal: open the app locally in a browser and log in. Zero server risk, fastest feedback.
2. Land the landmine fixes (collab WS URL, OAuth/share/webhook flowone.pro origins, LiveKit ws_url,
   turn.{domain} DNS seed). See `PLAN.md` → "Must-fix-in-code".
3. Author remaining images (mail pod is the big one; PowerDNS gmysql; OnlyOffice reference exists).
4. Throwaway VM — Layer 1+2 tests, no real data.
5. Parallel box from a production snapshot -> restore data -> Layer 3 parity.
6. Cutover (final delta sync + DNS/MX flip, old box kept warm) -> soak -> sundown.

## Open question parked for next session

"Go image-by-image through Phase B: for each Dockerfile state base image, packages, build steps,
volumes" — was offered but not yet done. Good first task before authoring files.
