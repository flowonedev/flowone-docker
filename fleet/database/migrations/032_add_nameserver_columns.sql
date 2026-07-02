-- Per-server authoritative nameservers. Empty = the client has no NS of their
-- own: no NS records are seeded, the panel derives ns1/ns2.<base-domain> for
-- display only, and the operator's own nameservers (ns1/ns2.devcon1.hu) are
-- NEVER pushed onto a provisioned box. Editable from the server detail page.

ALTER TABLE servers
    ADD COLUMN ns1_domain VARCHAR(255) NULL AFTER mail_domain,
    ADD COLUMN ns2_domain VARCHAR(255) NULL AFTER ns1_domain;
