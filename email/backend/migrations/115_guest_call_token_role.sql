-- Add role column to distinguish admin (CRM user) from guest participants

ALTER TABLE guest_call_tokens
ADD COLUMN role ENUM('admin','guest') NOT NULL DEFAULT 'guest' AFTER guest_email;
