-- Migration 018: Add preflight results storage and fix missing steps_total column
-- Stores the last preflight check results for reference during/after deployment
-- Also adds steps_total which was missing from migration 017

ALTER TABLE deployments ADD COLUMN IF NOT EXISTS steps_total INT UNSIGNED DEFAULT 0 AFTER steps_completed;
ALTER TABLE deployments ADD COLUMN IF NOT EXISTS preflight_results JSON NULL;
ALTER TABLE deployments ADD COLUMN IF NOT EXISTS preflight_at DATETIME NULL;
