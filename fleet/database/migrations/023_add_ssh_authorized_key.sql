-- Per-server override for the PUBLIC key authorized on the unprivileged "pxr"
-- account during SSH hardening. NULL = fall back to the fleet-wide default
-- (config ssh.pxr_authorized_key). Lets an operator paste a different key for
-- an individual server from the dashboard.

ALTER TABLE servers
    ADD COLUMN ssh_authorized_key TEXT NULL AFTER ssh_key_installed;
