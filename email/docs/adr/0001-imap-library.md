# ADR 0001: IMAP library choice for FlowOne email

* Status: Accepted
* Date: 2026-05-14
* Drivers: WhiteRabbit "All Mail" silent skip; future provider expansion
  (Gmail, Microsoft 365, generic Dovecot, Cyrus, Courier).

## Context

FlowOne currently uses two IMAP code paths inside
`backend/src/Services/ImapService.php`:

1. **Native PHP IMAP extension** (`imap_open`, `imap_fetch_overview`,
   `imap_status`, ...) for password-based connections. Backed by the
   c-client library that ships with PHP.
2. **Raw socket commands** for OAuth-only providers (Gmail / Microsoft).
   We hand-roll IMAP requests over a `stream_socket_client()` connection
   because PHP's IMAP extension cannot perform `XOAUTH2`.

Wave 1 of the mailbox-folder rework hardened the `getUidsWithTimestamps`
fallback ladder for the native path and replicated the same ladder on
the OAuth path. That fixed the immediate bug, but it surfaced two
medium-term concerns:

1. The c-client library is unmaintained upstream. Its all-or-nothing
   `imap_fetch_overview()` failure mode is the root cause of the
   WhiteRabbit symptom; we cannot patch it.
2. The OAuth socket path is a hand-rolled subset of the IMAP4rev1
   protocol. It works today but is brittle (no CONDSTORE, no
   QRESYNC, no LIST-EXTENDED / SPECIAL-USE plumbing beyond what we
   manually parse).

We considered three options:

### Option A: Stay on `ext-imap` everywhere

Pros: zero migration cost, native code path, smallest surface area.
Cons: inherits the c-client failure modes; no OAuth path; we still
need the hand-rolled socket layer for Gmail / M365.

### Option B: Migrate fully to `webklex/php-imap`

A pure-PHP IMAP4rev1 client maintained on Packagist.
Pros: per-message error isolation by design, LIST-EXTENDED + SPECIAL-USE
+ CONDSTORE support, OAuth-friendly.
Cons: substantial refactor of `ImapService.php` (5000+ lines), a number
of subtle behavior differences (envelope decoding, attachment parsing),
and we lose the native c-client speed advantage on healthy folders.

### Option C: Hybrid (RECOMMENDED)

Keep `ext-imap` for the password-based path on healthy folders, where
its native speed matters. Drop into a pure-PHP fallback (`webklex/php-imap`
behind a thin facade) for:

* OAuth connections (replacing the hand-rolled socket layer).
* Folders that the tiered fallback ladder downgrades to `chunk_50` or
  `per_uid`, where the native library's all-or-nothing failure mode
  has already proven unhelpful.
* Operations that require CONDSTORE / QRESYNC / LIST-EXTENDED.

Both paths funnel through a unified `MailFolderScanner` interface so the
rest of the app does not care which engine is in use. Provider
fingerprinting (Wave 2) makes it possible to pick the engine by provider:
Gmail/M365 always use the pure-PHP path; Dovecot/Cyrus default to
`ext-imap` and fall back on demand.

## Decision

Adopt the hybrid (Option C). Wave 3 introduces no code change yet; this
ADR captures the direction and the migration plan so future work can
pick it up without re-litigating the choice.

## Consequences

* Future Wave: introduce `webklex/php-imap` as a composer dep, hide
  behind `MailFolderScannerFactory` keyed on provider_type +
  fallback_stage.
* Pure-PHP client adds memory pressure (no shared c-client buffer);
  the `SCAN_MAX_UID_TRACK` / `SCAN_MAX_BAD_UIDS_REPORTED` /
  `SCAN_MAX_SEGMENTS_PENDING` constants already in place are the
  correct guardrails.
* Hand-rolled OAuth socket code in `ImapService::*OAuth()` becomes a
  deprecation candidate once the pure-PHP path proves stable.
* We retain a viable rollback plan: any per-folder regression can be
  reverted to `ext-imap` by flipping a per-account override stored
  alongside `webmail_account_provider`.

## Migration plan (informational, not part of this ADR's commitment)

1. Wire `webklex/php-imap` behind `Webmail\Services\MailFolderScannerFactory`.
2. Run shadow mode: every OAuth scan returns the existing socket-layer
   result; `webklex` runs in parallel and we log mismatches structurally
   (evt=imap_engine_mismatch).
3. Cut OAuth to `webklex` after 7 consecutive days at <0.01% mismatch.
4. Cut native fallback (chunk_50 / per_uid) to `webklex` once OAuth has
   been stable for 30 days.
5. Retain `ext-imap` for the healthy-folder fast path indefinitely.
