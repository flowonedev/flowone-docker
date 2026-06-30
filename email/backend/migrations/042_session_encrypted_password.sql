-- Store encrypted IMAP password server-side in the sessions table
-- instead of carrying it in the JWT token payload.
-- This way if the JWT is exposed, the IMAP password cannot be extracted.
ALTER TABLE webmail_sessions 
    ADD COLUMN encrypted_password TEXT DEFAULT NULL 
    COMMENT 'AES-encrypted IMAP password, stored server-side instead of in JWT'
    AFTER session_token_hash;

