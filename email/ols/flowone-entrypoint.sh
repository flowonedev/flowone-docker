#!/bin/bash
# LSAPI does not pass the container environment to lsphp — only `env` directives
# declared in the lsapi extprocessor are exported. So before OLS starts, inject
# the container env (set by docker-compose) into the extprocessor block. This
# keeps docker-compose.local.yml as the single source of truth for config.
set -e

VHCONF=/usr/local/lsws/conf/vhosts/flowone/vhconf.conf

# Whitelist of vars the PHP backend reads via getenv(). Add new ones here.
VARS="DB_HOST DB_NAME DB_USER DB_PASS \
REDIS_HOST REDIS_PORT REDIS_PASSWORD REDIS_DATABASE \
MEILI_HOST MEILI_MASTER_KEY MEILI_SEARCH_KEY \
JWT_SECRET JWT_ALGORITHM IMAP_ENCRYPTION_KEY \
PANEL_API_URL PANEL_API_KEY APP_DEBUG STORAGE_PATH OAUTH_KEYS"

: > /tmp/ols_envlines
for k in $VARS; do
  # Only inject vars that are actually set (even if empty), via indirect expansion.
  if [ -n "${!k+x}" ]; then
    printf '  env                     %s=%s\n' "$k" "${!k}" >> /tmp/ols_envlines
  fi
done

if [ -f "$VHCONF" ]; then
  awk '/#ENV_INJECT/{ while ((getline line < "/tmp/ols_envlines") > 0) print line; next } { print }' \
    "$VHCONF" > "$VHCONF.new" && mv "$VHCONF.new" "$VHCONF"
fi

# Hand off to the stock OpenLiteSpeed entrypoint (seeds conf if empty, starts lshttpd, keeps PID 1 alive).
exec /entrypoint.sh "$@"
