# OAuth Hardening — Production Rollout Checklist

This is the one-time deployment checklist for going live with the OAuth
encryption hardening. Run these on `flowone.pro` in order.

> Always run the underlying details of each step against
> [`oauth-key-rotation.md`](./oauth-key-rotation.md) — that doc is the source
> of truth for rotation procedures going forward.

## Pre-flight (do not skip)

- [ ] Pull the latest backend + frontend code onto the server (`/var/www/vps-email`).
- [ ] Verify the **legacy** `IMAP_ENCRYPTION_KEY` env var is still set in
      `/var/www/vps-email/backend/.env`. (It is required by the legacy CBC
      decrypt path until we re-encrypt all rows.)
- [ ] Backup `webmail_oauth_tokens` before any changes:
      ```bash
      mysqldump -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' \
          devc_vps_dash webmail_oauth_tokens \
          > /var/www/vps-email/backups/webmail_oauth_tokens-pre-hardening-$(date +%Y%m%d).sql
      ```

## 1. Run the schema migration

The migration adds `health`, `health_reason`, `health_updated_at` to
`webmail_oauth_tokens` and creates the `webmail_canary` table.

```bash
mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' devc_vps_dash \
    < /var/www/vps-email/backend/migrations/151_oauth_health.sql
```

(`MigrationService` also auto-applies this on the first request after deploy.
Running it manually first removes a small race risk.)

## 2. Generate the v1 OAuth key

```bash
openssl rand -hex 32
```

Copy the 64-char hex output.

## 3. Wire the new env vars

Edit `/var/www/vps-email/backend/.env`:

```env
OAUTH_KEYS=v1:<paste hex from step 2>
OAUTH_CURRENT_VERSION=1

# KEEP this — needed to decrypt legacy CBC rows until we re-encrypt them.
IMAP_ENCRYPTION_KEY=<existing value, do not change>
```

Reload OpenLiteSpeed so PHP-FPM picks up the new env:

```bash
/usr/local/lsws/bin/lswsctrl reload
```

Hit any endpoint to trigger the boot canary; first hit writes the canary row.

```bash
curl -sk https://flowone.pro/api/auth/google/enabled | head
```

If the response is HTTP 500 with `Server misconfiguration (OAuth encryption)`,
something is wrong — fix before continuing.

## 4. Dry-run the re-encryption

```bash
/usr/local/lsws/lsphp83/bin/php \
    /var/www/vps-email/backend/scripts/reencrypt-oauth.php \
    --target-version=1 --dry-run --verbose
```

Check the printed counts and the log file in
`/var/www/vps-email/backend/storage/logs/reencrypt-oauth-*.log`. Investigate
any `quarantined` rows. Most of those are the old broken rows from the recent
incident (e.g. previously unblocked accounts) — those should be deleted or
re-linked, not re-encrypted.

## 5. Run the re-encryption for real

```bash
/usr/local/lsws/lsphp83/bin/php \
    /var/www/vps-email/backend/scripts/reencrypt-oauth.php \
    --target-version=1 --verbose
```

After it finishes successfully, all healthy rows have a `v1:` prefix in
`refresh_token_encrypted` and `access_token_encrypted`.

## 6. Verify

```bash
mysql -u vpsadmin -p'7bcf619af819e4e274e5cfdfba022274' devc_vps_dash <<'SQL'
SELECT health, COUNT(*) FROM webmail_oauth_tokens GROUP BY health;
SELECT COUNT(*) AS legacy_cbc_left
  FROM webmail_oauth_tokens
  WHERE refresh_token_encrypted <> ''
    AND refresh_token_encrypted NOT LIKE 'v%:%';
SELECT id, primary_email, oauth_email, provider, health, health_reason, health_updated_at
  FROM webmail_oauth_tokens
  WHERE health <> 'healthy';
SQL
```

Expected:

- `legacy_cbc_left` is 0 (no rows still on legacy CBC after migration).
- `health` is `healthy` for every row you care about.

## 7. Pre-existing problem rows (Phase 4a follow-up)

From the unblock plan, `feketeroberto@gmail.com` (`id=37`) still needs a probe:

```sql
SELECT id, primary_email, oauth_email, provider, health, health_reason
  FROM webmail_oauth_tokens
  WHERE oauth_email = 'feketeroberto@gmail.com'
     OR primary_email = 'feketeroberto@gmail.com';
```

- If `health='broken'` or `health='revoked'`: do nothing. The frontend will
  auto-open the silent re-consent popup the next time the user opens mail.
- If `health='healthy'` but the user still gets 503/401 on mailbox actions:
  delete the row and re-link via the UI under their primary account.

## 8. Watch logs for 24 hours

```bash
tail -F /usr/local/lsws/logs/stderr.log | grep -Ei 'oauth|canary|decrypt failed'
```

You should see **zero** lines saying `decrypt failed`, `canary failed`, or
`quarantined`. If anything does appear, capture the row id and consult
`docs/oauth-key-rotation.md` § Disaster recovery.

## 9. (Optional) Lock down `IMAP_ENCRYPTION_KEY`

Once 24 hours pass cleanly and no rows reference legacy CBC ciphertext, the
`IMAP_ENCRYPTION_KEY` env var is no longer needed by `OAuthCryptor`. It is
**still required** by `SessionService` for IMAP password encryption — leave
it set. Do **not** remove or rename it on the server.

---

## Rollback

If anything goes wrong before step 5 completes:

1. Restore the backup taken in Pre-flight.
2. Remove `OAUTH_KEYS` / `OAUTH_CURRENT_VERSION` from `.env`.
3. Reload OLS.
4. The legacy CBC path resumes operation under `IMAP_ENCRYPTION_KEY`.
