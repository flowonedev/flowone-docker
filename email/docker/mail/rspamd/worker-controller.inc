# Rspamd controller (HTTP API) — FlowOne Docker mail pod.
# The webmail app trains Bayes via `rspamc -h 127.0.0.1:11334 learn_spam/ham`.
# 127.0.0.1/::1 are trusted, so no controller password is needed locally.
bind_socket = "127.0.0.1:11334";
secure_ip = "127.0.0.1";
secure_ip = "::1";
