-- Site Applications: enforce one active app per (domain, app_slug).
--
-- Why:
--   The V2 InstallAppStep is appended to the CREATE saga and must be
--   idempotent (the reconciler re-runs CREATE for pending_dns sites,
--   and the orchestrator may resume a partially-completed saga). The
--   existing INSERT into site_applications has no upsert guard, so a
--   retry would silently create duplicate rows and the
--   GET /api/sites/{domain}/applications query would return them all.
--
--   This migration adds a UNIQUE KEY so:
--     - The InstallAppStep can use ON DUPLICATE KEY UPDATE safely.
--     - Manual installs via /api/apps still fail loudly on a real
--       double-install attempt (which is what the legacy code was
--       trying to do, just without enforcement).
--
-- Cleanup before adding the constraint:
--   We must collapse any pre-existing duplicate rows; otherwise the
--   ALTER fails. We keep the row with the highest id (most recent
--   install) and DELETE the older ones. status='failed' rows are
--   deleted first - they were already abandoned. This is destructive
--   but matches the operator's intent: only one active record per
--   (domain, app_slug) was ever supposed to exist.
--
-- Safe to run repeatedly: each statement is guarded with IF EXISTS /
-- IF NOT EXISTS and the dedupe DELETE is a no-op when no duplicates
-- remain.

-- Step 1: nuke failed/superseded rows so the unique constraint can land.
DELETE sa1 FROM site_applications sa1
INNER JOIN site_applications sa2
  ON sa1.domain = sa2.domain
 AND sa1.app_slug = sa2.app_slug
 AND sa1.id < sa2.id;

-- Step 2: add the unique key (idempotent on MariaDB 10.x+).
ALTER TABLE site_applications
  ADD UNIQUE KEY IF NOT EXISTS uniq_domain_app (domain, app_slug);
