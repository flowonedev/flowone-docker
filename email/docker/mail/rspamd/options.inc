# Rspamd options — FlowOne Docker mail pod.
# Use the pod-local Unbound resolver (127.0.0.1:5335) for DNSBL/DKIM/SPF lookups,
# matching the native box (PowerDNS owns :53, so Rspamd resolves via Unbound).
dns {
    nameservers = ["127.0.0.1:5335"];
    timeout = 2s;
    retransmits = 2;
}
