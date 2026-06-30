-- Migration 185: Reconcile the three "people" stores
--
-- FlowOne kept the same human in up to four places with no shared identity:
--   1. email_contacts  - the lightweight autocomplete cache (auto, behavioral)
--   2. contacts         - the real CardDAV-ready address book (curated)
--   3. client_contacts  - the people roster inside a CRM client
--   4. clients          - the business account itself
--
-- This migration links them into ONE canonical person (a row in `contacts`)
-- while keeping the CRM untouched:
--   - A sync boundary (`addressbooks.is_synced`) splits the address book into a
--     synced zone (what CardDAV exposes to phones) and a non-synced "Other
--     contacts" pool that holds auto-collected + client-derived people. This is
--     the Google "Other contacts" pattern - frequently-emailed junk never
--     pollutes the user's phone contacts.
--   - `contacts` gains behavioral (`use_count`, `last_used`) and provenance
--     (`origin`) columns so autocomplete can rank from the same table and we
--     know how each person got there.
--   - `email_contacts.contact_id` and `client_contacts.contact_id` point at the
--     canonical `contacts.id`. `client_contacts` stays as the CRM membership
--     row (its own id is still referenced by crm_*.contact_id / portal_access),
--     so NOTHING in the CRM needs re-pointing.
--
-- The same columns are also added at runtime by the services
-- (AddressBookService / ContactsService / ClientService) so the feature is
-- self-healing even if this migration hasn't been run. MariaDB-only
-- "IF NOT EXISTS" keeps every statement idempotent.

-- 1. Sync boundary on address books. is_synced = 0 marks the "Other contacts" pool.
ALTER TABLE addressbooks
    ADD COLUMN IF NOT EXISTS is_synced TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'Syncs to devices via CardDAV? 0 = non-synced "Other contacts" pool';

-- 2. Behavioral + provenance columns on the canonical contact.
ALTER TABLE contacts
    ADD COLUMN IF NOT EXISTS use_count INT NOT NULL DEFAULT 0 COMMENT 'Times emailed (autocomplete ranking)',
    ADD COLUMN IF NOT EXISTS last_used TIMESTAMP NULL DEFAULT NULL COMMENT 'Last email interaction',
    ADD COLUMN IF NOT EXISTS origin ENUM('manual','auto','client') NOT NULL DEFAULT 'manual'
    COMMENT 'manual = user saved; auto = threshold auto-collected; client = derived from a client';

-- 3. Link the seen/autocomplete cache to the canonical contact.
ALTER TABLE email_contacts
    ADD COLUMN IF NOT EXISTS contact_id INT NULL COMMENT 'Canonical contacts.id once saved/promoted';

-- 4. Link client people to the canonical contact. client_contacts.id stays the
--    CRM-facing key (crm_*.contact_id / portal_access.contact_id), so this is
--    purely additive - no CRM foreign keys move.
ALTER TABLE client_contacts
    ADD COLUMN IF NOT EXISTS contact_id INT NULL COMMENT 'Canonical contacts.id (shared person identity)';

-- Helpful indexes (non-critical; services work without them).
ALTER TABLE email_contacts  ADD INDEX IF NOT EXISTS idx_contact_id (contact_id);
ALTER TABLE client_contacts ADD INDEX IF NOT EXISTS idx_contact_id (contact_id);
ALTER TABLE contacts        ADD INDEX IF NOT EXISTS idx_use_count (use_count);
ALTER TABLE addressbooks    ADD INDEX IF NOT EXISTS idx_is_synced (is_synced);

-- Historical backfill (linking existing client_contacts / email_contacts rows to
-- canonical contacts, and seeding the Other contacts pool) is done by
-- scripts/backfill-people-links.php, because matching on the JSON `emails`
-- column is far more reliable in PHP than in SQL. New activity links itself
-- automatically going forward.
