#!/bin/bash
# Production web-tier entrypoint.
#
# LSAPI does NOT pass the container environment to lsphp — only `env` directives
# declared inside the lsapi extprocessor are exported. So before OLS starts we
# render the per-host config (injected via the stack .env / compose `environment`)
# into the vhost: env vars, the collab/mailsync proxy addresses, and (optionally)
# TLS. This keeps the .env the single source of truth for a server.
set -e

VHCONF=/usr/local/lsws/conf/vhosts/flowone/vhconf.conf
HTTPD=/usr/local/lsws/conf/httpd_config.conf

# --- 1. Inject the PHP backend env (whitelist mirrors backend/src/config.php) ---
VARS="\
DB_HOST DB_PORT DB_NAME DB_USER DB_PASS \
MAIL_DB_HOST MAIL_DB_NAME MAIL_DB_USER MAIL_DB_PASS \
REDIS_HOST REDIS_PORT REDIS_PASSWORD REDIS_DATABASE \
MEILI_HOST MEILI_MASTER_KEY MEILI_SEARCH_KEY \
JWT_SECRET JWT_ALGORITHM JWT_PRIVATE_KEY_PATH JWT_PUBLIC_KEY_PATH \
IMAP_ENCRYPTION_KEY AI_ENCRYPTION_KEY OAUTH_KEYS OAUTH_CURRENT_VERSION \
FRONTEND_URL API_URL APP_ENV APP_DEBUG STORAGE_PATH \
IMAP_HOST SMTP_HOST SMTP_NOTIFICATION_PASSWORD \
PANEL_API_URL PANEL_API_KEY \
GOOGLE_OAUTH_CLIENT_ID GOOGLE_OAUTH_CLIENT_SECRET GOOGLE_OAUTH_REDIRECT_URI \
MICROSOFT_OAUTH_CLIENT_ID MICROSOFT_OAUTH_CLIENT_SECRET MICROSOFT_OAUTH_REDIRECT_URI \
COLLAB_WS_PORT COLLAB_WS_HOST COLLAB_WS_URL \
VAPID_PUBLIC_KEY VAPID_PRIVATE_KEY VAPID_SUBJECT \
STUN_URL TURN_URL TURN_SECRET TURN_TTL \
LIVEKIT_API_KEY LIVEKIT_API_SECRET LIVEKIT_WS_URL \
SSO_SERVER_KEY SESSION_ENFORCE_IP_BINDING"

: > /tmp/ols_envlines
for k in $VARS; do
  # Inject only vars that are actually set (even if empty) via indirect expansion.
  if [ -n "${!k+x}" ]; then
    printf '  env                     %s=%s\n' "$k" "${!k}" >> /tmp/ols_envlines
  fi
done

awk '/@@ENV_INJECT@@/{ while ((getline line < "/tmp/ols_envlines") > 0) print line; close("/tmp/ols_envlines"); next } { print }' \
  "$VHCONF" > "$VHCONF.new" && mv "$VHCONF.new" "$VHCONF"

# --- 2. Point the WS proxies at the collab/mailsync containers ---
COLLAB_ADDR="${COLLAB_ADDR:-collab:1234}"
MAILSYNC_ADDR="${MAILSYNC_ADDR:-mailsync:1235}"
sed -i "s|__COLLAB_ADDR__|${COLLAB_ADDR}|g; s|__MAILSYNC_ADDR__|${MAILSYNC_ADDR}|g" "$VHCONF"

# --- 3. Optional TLS. ENABLE_SSL=1 wires a vhssl{} block + a :443 listener. ---
if [ "${ENABLE_SSL:-0}" = "1" ]; then
  DOMAIN="${EMAIL_DOMAIN:-localhost}"
  CERT="${SSL_CERT_FILE:-/etc/letsencrypt/live/${DOMAIN}/fullchain.pem}"
  KEY="${SSL_KEY_FILE:-/etc/letsencrypt/live/${DOMAIN}/privkey.pem}"
  cat > /tmp/ols_vhssl <<VHSSL
vhssl  {
  keyFile                 ${KEY}
  certFile                ${CERT}
  certChain               1
  sslProtocol             24
  enableECDHE             1
  renegProtection         1
  sslSessionCache         1
  enableSpdy              15
  enableStapling          1
  ocspRespMaxAge          86400
}
VHSSL
  awk '/@@VHSSL_INJECT@@/{ while ((getline line < "/tmp/ols_vhssl") > 0) print line; close("/tmp/ols_vhssl"); next } { print }' \
    "$VHCONF" > "$VHCONF.new" && mv "$VHCONF.new" "$VHCONF"

  # Map flowone onto the stock :443 listener (its vhssl block supplies the real
  # cert via SNI). Avoid a second :443 listener — that collides with the stock one.
  if ! awk '/^listener HTTPS \{/{f=1} f&&/map +flowone/{found=1} END{exit !found}' "$HTTPD"; then
    awk '{print} /^listener HTTPS \{/{print "  map                     flowone *"}' \
      "$HTTPD" > "$HTTPD.new" && mv "$HTTPD.new" "$HTTPD"
  fi
else
  # Drop the marker so it never appears in the live config.
  sed -i '/@@VHSSL_INJECT@@/d' "$VHCONF"
fi

# Hand off to the stock OpenLiteSpeed entrypoint (starts lshttpd, keeps PID 1).
exec /entrypoint.sh "$@"
