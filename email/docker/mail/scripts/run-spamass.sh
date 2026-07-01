#!/bin/sh
# spamass-milter wrapper. The Debian init script chmods the milter socket after
# creation so Postfix (user 'postfix', non-chroot) can connect; supervisord
# doesn't, so we replicate it here. Non-fatal for mail flow either way
# (milter_default_action=accept), but this keeps the SpamAssassin layer wired.
set -eu
SOCK=/var/spool/postfix/spamass/spamass.sock
rm -f "$SOCK"
/usr/sbin/spamass-milter -p "$SOCK" -i 127.0.0.1 -m -r -1 &
pid=$!
i=0
while [ ! -S "$SOCK" ] && [ "$i" -lt 40 ]; do i=$((i+1)); sleep 0.5; done
chmod 0666 "$SOCK" 2>/dev/null || true
wait "$pid"
