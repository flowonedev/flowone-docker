-- Add payment_status column to board lists for manual milestone payment tracking
ALTER TABLE webmail_board_lists
    ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) DEFAULT 'unpaid';
