-- Add permission columns to colleague_groups
ALTER TABLE colleague_groups
  ADD COLUMN can_see_all_boards TINYINT(1) NOT NULL DEFAULT 0 AFTER icon,
  ADD COLUMN can_see_all_tasks TINYINT(1) NOT NULL DEFAULT 0 AFTER can_see_all_boards,
  ADD COLUMN can_manage_members TINYINT(1) NOT NULL DEFAULT 0 AFTER can_see_all_tasks,
  ADD COLUMN can_view_financials TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage_members,
  ADD COLUMN admin_equivalent TINYINT(1) NOT NULL DEFAULT 0 AFTER can_view_financials;
