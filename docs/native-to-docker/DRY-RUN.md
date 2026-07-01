# Manual VPS Dry-Run (Phase E, step 1) — bridge stack

Bring the FlowOne **bridge-network** stack up on a real Linux VPS, by hand, to
validate the runtime that Windows Docker Desktop can't: real Linux, pulling the
**private GHCR** images on a real box, the OpenLiteSpeed `:443` listener, cert
wiring, and real-domain SPA / API / WebSocket routing.

This is the hand-run analog of Fleet's `DockerProvisioningService`. It uses
`email/docker/vps-bootstrap.sh`, which mints **fresh** secrets + JWT keys — so
run it **only on an empty box**, never against migrated production data.

## Scope

| In this dry-run | Not yet (later Phase E authoring) |
|---|---|
| web, mariadb, redis, meilisearch, collab, mailsync | mail (Postfix/Dovecot/Rspamd/ClamAV) |
| GHCR pull, TLS, domain routing, WS proxy, app login | PowerDNS, coTURN/LiveKit, OnlyOffice |

Because the mail/DNS/TURN pods aren't containerized yet, email send/receive,
calls/video, and doc editing stay dark. Everything else (webmail UI, auth,
drive, collab presence over the bridge) should work.

## Prerequisites

- A fresh Linux VPS (2 GB+ RAM, 20 GB+ disk), root SSH access.
- A GHCR token with `read:packages` (the packages are private). A dedicated
  read-only token is preferred over the write token used to publish.
- (For TLS only) a DNS A record pointing a hostname at the VPS IP.

## 1. Copy the stack files up

From your workstation, in the repo root:

```bash
scp -r email/docker/ root@<VPS_IP>:/opt/flowone-src/
```

That carries `docker-compose.yml`, `vps-bootstrap.sh`, and the `web/`, `collab/`,
`mailsync/` build contexts (the box pulls prebuilt images, so the Dockerfiles are
just along for the ride).

## 2. First bring-up — HTTP only (simplest)

```bash
ssh root@<VPS_IP>
cd /opt/flowone-src
GHCR_TOKEN=ghp_your_read_token ./vps-bootstrap.sh --ghcr-user=flowonedev --domain=<VPS_IP>
```

The script will: install Docker (if missing) → `docker login ghcr.io` → write
`/opt/flowone/.env` (fresh secrets) → seed the `flowone_jwt_keys` volume → pull
`ghcr.io/flowonedev/flowone-{web,collab,mailsync}:latest` → `compose up -d` →
wait for health and curl `/api/auth/me`.

Expected tail:

```
[OK] Stack healthy. GET /api/auth/me -> 401 (401/200 = app booted + DB/Redis answered).
```

Then from your workstation: `curl -I http://<VPS_IP>/` should return `200`, and
opening `http://<VPS_IP>/` shows the login page.

## 3. Add TLS (real domain)

1. Point a hostname at the VPS: `A  stg.flowone.pro -> <VPS_IP>` (avoid the prod
   apex; use a staging subdomain).
2. Obtain certs on the host (standalone, stack briefly down, or via a webroot):
   ```bash
   docker compose -f /opt/flowone/docker-compose.yml down
   apt-get update && apt-get install -y certbot
   certbot certonly --standalone -d stg.flowone.pro
   ```
   Certs land in `/etc/letsencrypt/live/stg.flowone.pro/` — the compose already
   mounts `/etc/letsencrypt` read-only into the web container.
3. Re-run with SSL + the real domain (delete the old `.env` so it regenerates
   with `https` URLs):
   ```bash
   rm -f /opt/flowone/.env
   cd /opt/flowone-src
   GHCR_TOKEN=ghp_your_read_token ./vps-bootstrap.sh \
     --ghcr-user=flowonedev --domain=stg.flowone.pro --ssl
   ```
4. Verify `https://stg.flowone.pro/` loads with a valid cert and log in.

## 4. What "pass" looks like

- All six services `healthy` in `docker compose ps`.
- `GET /api/auth/me` → 401, `GET /` → 200.
- Browser login works over HTTPS with a valid cert.
- `docker exec flowone-web-1 /usr/local/lsws/lsphp83/bin/php -m` lists
  `gd zip imap imagick intl pdo_mysql redis`.
- Uploading to Drive persists across `docker compose up -d --force-recreate web`
  (proves the `vps_email_files` volume).

## 5. Teardown

```bash
# keep data:
docker compose -f /opt/flowone/docker-compose.yml down
# wipe everything (fresh next run):
docker compose -f /opt/flowone/docker-compose.yml down -v
```

## 6. After this passes

Author the host-networking pods one at a time (mail → DNS → TURN/LiveKit →
OnlyOffice), dry-running each addition, then move to Fleet CLI orchestration
(`cli/provision-docker.php` from the existing prod Fleet server) and finally the
dashboard `DOCKER_PROVISION` wiring.
