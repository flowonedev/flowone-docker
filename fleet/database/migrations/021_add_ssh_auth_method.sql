-- Track the SSH auth method per server so the dashboard can reflect the real
-- post-deploy connection (a cloned/hardened sshd_config may switch the box to
-- key-based auth). Kept in sync by the agent heartbeat (AgentController).

ALTER TABLE servers
    ADD COLUMN ssh_auth_method ENUM('password', 'key') DEFAULT 'password' AFTER ssh_port;
