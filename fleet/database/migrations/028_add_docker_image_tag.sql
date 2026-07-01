-- Persist the Docker image tag currently deployed on each server (Phase D).
--
-- CI publishes every image as :latest AND :<short-git-sha>. When Fleet rolls a
-- server's stack (docker_provision) or updates a service (docker_update), it
-- records the tag it pointed the .env at here, so the dashboard can show exactly
-- which build is live on which server and offer a targeted rollback/upgrade.
--
-- NULL = never deployed via the Docker path (or a legacy/native box). The value
-- is a plain tag string ('latest', 'a1b2c3d', 'v1.2.3'); it is not a secret.
ALTER TABLE servers
    ADD COLUMN IF NOT EXISTS deployed_image_tag VARCHAR(64) NULL AFTER jwt_public_key;
