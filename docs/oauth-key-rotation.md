# OAuth Token Encryption — Rotation Runbook

This document is the **only** safe procedure for rotating the encryption key
used for OAuth refresh/access tokens (`webmail_oauth_tokens`).

> **TL;DR**: never edit `OAUTH_KEYS` / `OAUTH_CURRENT_VERSION` in production
> without following this runbook. The boot canary will refuse to start the app
> if the configured key cannot decrypt the canary row.

---

## 1. Architecture (one-paragraph refresher)

OAuth tokens are stored encrypted under a **versioned envelope**:

```
v{N}:<base64(iv|ciphertext|tag)>   (AES-256-GCM, 12-byte IV, 16-byte tag, AAD="oauth_token_v1")
```

The `OAuthCryptor` service:

- **Reads** any ciphertext whose `v{N}:` prefix matches a key listed in
  `OAUTH_KEYS`. Multiple versions can coexist.
- **Writes** new ciphertexts under `OAUTH_CURRENT_VERSION`.
- **Also reads legacy AES-256-CBC** ciphertexts (no `v` prefix) using
  `IMAP_ENCRYPTION_KEY` as the legacy single key — strictly to migrate
  pre-hardening rows.
- On boot, `OAuthCryptor::canaryCheck()` runs from `public/index.php` and
  **fails the request** (HTTP 500) if it cannot decrypt the canary row.

## 2. Environment variables

| Variable                | Purpose                                                    | Required |
|-------------------------|------------------------------------------------------------|----------|
| `OAUTH_KEYS`            | Comma list of `v{N}:<keySource>` pairs                      | yes (production) |
| `OAUTH_CURRENT_VERSION` | Integer; must match one of the v{N} entries above           | yes (production) |
| `IMAP_ENCRYPTION_KEY`   | Legacy key for reading pre-hardening rows (AES-CBC)         | only during migration |
| `JWT_SECRET`            | **Deprecated** for OAuth encryption. No longer a fallback.  | independent |

`<keySource>` is any high-entropy string (recommended: `openssl rand -hex 32`).
It is hashed to a 32-byte AES key at runtime.

## 3. Planned rotation procedure

Always follow these steps in order. **Never** swap `OAUTH_CURRENT_VERSION`
before the new key is added.

1. Generate a new key:
   ```bash
   openssl rand -hex 32
   ```
2. **Append** the new key to `OAUTH_KEYS` as `v{N+1}:<hex>`. Keep the old
   version present. Do **NOT** change `OAUTH_CURRENT_VERSION` yet.
   ```env
   OAUTH_KEYS=v1:abc...,v2:def...
   OAUTH_CURRENT_VERSION=1
   ```
3. Reload OLS / PHP-FPM. The canary continues to decrypt under v1.
4. Dry-run the migration tool:
   ```bash
   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/scripts/reencrypt-oauth.php \
       --target-version=2 --dry-run --verbose
   ```
   Verify counts (total / reencrypted / already_current / quarantined). Investigate any quarantined rows before proceeding.
5. Run for real:
   ```bash
   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/scripts/reencrypt-oauth.php \
       --target-version=2 --verbose
   ```
   Logs land in `/var/www/vps-email/backend/storage/logs/reencrypt-oauth-*.log`.
6. Promote the new version:
   ```env
   OAUTH_CURRENT_VERSION=2
   ```
   Reload OLS. Boot canary regenerates the canary row under v2 the next time the
   row is re-encrypted (or rotate it manually with a single
   `OAuthCryptor::canaryCheck()`-equivalent script if you want it pristine).
7. Soak for at least 30 days. Tail logs:
   ```bash
   tail -F /usr/local/lsws/logs/stderr.log | grep -i 'decrypt'
   ```
   You should see zero `decrypt failed` entries.
8. Retire the old key:
   - Confirm no rows reference `v{N}:` (e.g.
     `SELECT COUNT(*) FROM webmail_oauth_tokens WHERE refresh_token_encrypted LIKE 'v1:%'`)
   - Remove `v1:...` from `OAUTH_KEYS`.
   - Reload OLS. Canary still passes under v2.

## 4. Disaster recovery

If the **current** key was destroyed and there is no backup:

1. Generate a new key and add it as the next version:
   ```env
   OAUTH_KEYS=v1:<existing>,v2:<new>
   OAUTH_CURRENT_VERSION=2
   ```
2. Reload OLS.
3. The boot canary will fail until you reset the canary row:
   ```sql
   DELETE FROM webmail_canary;
   ```
   The next request auto-writes a fresh canary under v2.
4. Mass-quarantine all rows you cannot decrypt:
   ```bash
   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/scripts/reencrypt-oauth.php \
       --target-version=2 --quarantine-mode --verbose
   ```
   Rows that can't be decrypted are marked `health='broken'`, so the
   `getOAuthAccountByEmail` fallback skips them and they don't poison other users.
5. The frontend handles `action=oauth_reauth_required` by clearing the session
   and redirecting the user to `/login`. They re-sign-in with Google which (via
   the merged login flow) re-grants the full Gmail scope + a fresh refresh token,
   and IMAP/SMTP via XOAUTH2 resumes immediately.

## 5. Local development safety

- Local dev (`setup-local.ps1`) does **not** require setting `OAUTH_KEYS`. If
  only `IMAP_ENCRYPTION_KEY` is present, the config layer wires it as `v1` and
  things work. **But** importing a production DB dump into local dev without
  carrying the prod key over will fail the canary on the first request — by
  design, so you immediately see the mismatch instead of silent token corruption.
- When you import a prod DB dump:
  1. Set the prod `IMAP_ENCRYPTION_KEY` (or `OAUTH_KEYS`) in your local `.env`.
  2. Run the canary by hitting any endpoint. If it fails, you know the key
     drifted and rows are unreadable.
- Never store production keys in source. They live in `.env`, which is
  `.gitignore`d. See `email/backend/.env.example` for variable names.

## 6. Forbidden moves

- **Never** change `OAUTH_KEYS` or `OAUTH_CURRENT_VERSION` without following
  the procedure above. The canary will catch most mistakes, but you can still
  brick refresh-token reads for one rotation cycle.
- **Never** delete a key version that any row still references. Query first.
- **Never** rely on `JWT_SECRET` to derive an OAuth encryption key. That
  fallback has been removed deliberately; the canary will fail before it
  matters.
- **Never** copy a production `webmail_oauth_tokens` snapshot to a system
  without the matching encryption key. There is nothing to recover.

## 7. References

- Service: `email/backend/src/Services/OAuthCryptor.php`
- Bootstrap canary: `email/backend/public/index.php`
- Migration: `email/backend/migrations/151_oauth_health.sql`
- Re-encryption tool: `email/backend/scripts/reencrypt-oauth.php`
- Plan: `c:/Users/KITCHEN/.cursor/plans/harden_oauth_encryption_*.plan.md`
