# Rspamd milter proxy — FlowOne Docker mail pod.
# Postfix talks to this on 127.0.0.1:11332 (appended to smtpd_milters at boot).
# self_scan = the proxy scans inline (no separate scan round-trip).
milter = yes;
timeout = 120s;
upstream "local" {
    default = yes;
    self_scan = yes;
}
bind_socket = "127.0.0.1:11332";
