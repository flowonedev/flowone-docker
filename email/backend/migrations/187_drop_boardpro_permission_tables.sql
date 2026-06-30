-- Drop the Board Pro "advanced permissions" tables (Phase 2E leftovers).
-- No UI ever wrote to them and nothing in the backend enforced them
-- (BoardService::moveCard / card queries never consulted these tables),
-- so the stored permissions were pure dead weight. The endpoints and
-- store wrappers were removed in the same change.

DROP TABLE IF EXISTS boardpro_card_permissions;
DROP TABLE IF EXISTS boardpro_member_stage_permissions;
