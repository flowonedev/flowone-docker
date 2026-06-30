-- Project-level dates and time budget for boards.
-- Makes "project getting out of scope" measurable: % time elapsed vs % cards
-- done, and budgeted hours vs actually tracked hours.

ALTER TABLE webmail_boards
    ADD COLUMN IF NOT EXISTS start_date DATE DEFAULT NULL COMMENT 'Project start date',
    ADD COLUMN IF NOT EXISTS end_date DATE DEFAULT NULL COMMENT 'Project planned end date',
    ADD COLUMN IF NOT EXISTS budget_hours DECIMAL(8,2) DEFAULT NULL COMMENT 'Planned hours budget for the whole project';
