-- =====================================================
-- TEST SIMULATION: Tag colleague_groups + memberships
-- so the seeder can group sim users into realistic teams
-- (CEO / Creative Directors / Account Managers / Designers / Copywriters)
-- and the deleter can remove them cleanly.
-- =====================================================

ALTER TABLE colleague_groups
  ADD COLUMN simulation_run_id VARCHAR(16) NULL,
  ADD INDEX idx_simulation_run (simulation_run_id);

ALTER TABLE colleague_group_members
  ADD COLUMN simulation_run_id VARCHAR(16) NULL,
  ADD INDEX idx_simulation_run (simulation_run_id);
