# FlowOne mail pod — the full native mail stack in one supervised container.
# Postfix + Dovecot + OpenDKIM + OpenDMARC + Rspamd + ClamAV + SpamAssassin +
# Unbound, DB-backed virtual users (mailserver DB in the shared MariaDB).
# Runs with network_mode: host on the server (real sender IPs on port 25).
#
# Build context: email/docker/mail  (self-contained).
#   docker build -t flowone-mail email/docker/mail
FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8

# Postfix asks debconf questions on install; preseed "No configuration" so the
# non-interactive install doesn't stall, then our entrypoint writes main.cf.
RUN echo "postfix postfix/main_mailer_type select No configuration" | debconf-set-selections \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        postfix postfix-mysql \
        dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-mysql \
        dovecot-sieve dovecot-managesieved \
        opendkim opendkim-tools opendmarc \
        rspamd redis-server \
        clamav clamav-daemon clamav-freshclam \
        spamassassin spamass-milter \
        postfix-policyd-spf-python \
        unbound \
        supervisor rsyslog \
        ca-certificates openssl netcat-openbsd procps sed \
    && rm -rf /var/lib/apt/lists/*

# Baked config: native Fleet templates (rendered at boot) + static Rspamd/Unbound/
# Redis/rsyslog/supervisor config + entrypoint.
COPY templates/ /opt/mail-templates/
COPY rspamd/ /etc/rspamd/local.d/
COPY unbound/flowone.conf /etc/unbound/unbound.conf.d/flowone.conf
COPY redis/rspamd-redis.conf /etc/redis/rspamd-redis.conf
COPY rsyslog.conf /etc/rsyslog.conf
COPY supervisord.conf /etc/supervisor/conf.d/mail.conf
COPY scripts/run-spamass.sh /usr/local/bin/run-spamass.sh
COPY entrypoint.sh /entrypoint.sh

# Normalize line endings (repo is edited on Windows) and set exec bits.
RUN sed -i 's/\r$//' /entrypoint.sh /usr/local/bin/run-spamass.sh \
        /etc/supervisor/conf.d/mail.conf /etc/rsyslog.conf \
    && find /opt/mail-templates /etc/rspamd/local.d /etc/unbound/unbound.conf.d /etc/redis \
        -type f -exec sed -i 's/\r$//' {} + \
    && chmod +x /entrypoint.sh /usr/local/bin/run-spamass.sh \
    # Disable the distro rspamd controller password prompt file if present.
    && rm -f /etc/rspamd/local.d/worker-controller.inc.orig 2>/dev/null || true

# Host networking on the server, but declare the mail ports for documentation.
EXPOSE 25 110 143 465 587 993 995 4190

# imaps (993) + smtp (25) listening = the pod is serving. Give the security
# stack + freshclam time to warm up before the first check.
HEALTHCHECK --interval=30s --timeout=10s --start-period=90s --retries=5 \
    CMD nc -z 127.0.0.1 993 && nc -z 127.0.0.1 25 || exit 1

ENTRYPOINT ["/entrypoint.sh"]
