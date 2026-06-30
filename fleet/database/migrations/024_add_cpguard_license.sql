-- Per-server CPGuard license key (licenses are bound to the server IP, so each
-- box needs its own). Stored encrypted like every other server credential.
-- Used by the optional install_cpguard provisioning step and the on-demand
-- "Install CPGuard" action on the server detail page.

ALTER TABLE servers
    ADD COLUMN cpguard_license_key_encrypted TEXT NULL AFTER panel_admin_password_encrypted;
