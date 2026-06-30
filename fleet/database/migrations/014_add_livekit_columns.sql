-- Migration: Add LiveKit credentials to servers table
-- LiveKit is used for voice/video calling in the Email App
-- These can be either self-hosted LiveKit or LiveKit Cloud credentials

ALTER TABLE servers
    ADD COLUMN livekit_api_key_encrypted TEXT NULL AFTER meili_search_key,
    ADD COLUMN livekit_api_secret_encrypted TEXT NULL AFTER livekit_api_key_encrypted,
    ADD COLUMN livekit_ws_url VARCHAR(255) NULL AFTER livekit_api_secret_encrypted;

