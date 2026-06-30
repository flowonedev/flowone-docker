# FlowOne PRODUCTION web tier — multi-stage.
#
# Build context: the email/ directory (so backend/, collab/, frontend/ are all
# reachable). Build from the repo with:
#   docker build -f docker/web/Dockerfile -t flowone-web:latest .
# (run from inside email/, or pass the email/ path as the context).
#
# Unlike the dev images (which bind-mount source + run Vite), this BAKES the app:
#   - frontend is `vite build`-ed to /var/www/vps-email/dist (served by OLS)
#   - backend has `composer install --no-dev` vendored in
#   - PHP runs under lsphp83/LSAPI, same binary path as the native server
# Per-host config (DB creds, domains, secrets) is injected at runtime via env;
# migrated state (storage, certs, JWT keys) is mounted as volumes.

# ---------- Stage 1: build the SPA ----------
FROM node:20-bookworm-slim AS frontend
WORKDIR /build/frontend
# Install deps first (better layer caching). package-lock may or may not exist.
COPY frontend/package*.json ./
RUN npm ci || npm install
COPY frontend/ ./
# pdfjs/pixi/xlsx make this a heavy build; give Node room.
ENV NODE_OPTIONS=--max-old-space-size=4096
RUN npm run build

# ---------- Stage 2: OLS + lsphp83 runtime ----------
FROM litespeedtech/openlitespeed:1.8.5-lsphp83

# Match the production lsphp83 extension set (see fleet/installer/ols-install.sh).
# curl is the CLI tool used by the container HEALTHCHECK.
RUN apt-get update && apt-get install -y --no-install-recommends \
      lsphp83-mysql \
      lsphp83-curl \
      lsphp83-imap \
      lsphp83-intl \
      lsphp83-opcache \
      lsphp83-redis \
      lsphp83-imagick \
      curl \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# App source at the production layout. collab/ is needed for the backend's
# composer "Collab\\" autoload (../collab/backend); the empty shared/ dir
# satisfies the lazy "FlowOne\\Storage\\" PSR-4 base (../../shared/src/Storage).
RUN mkdir -p /var/www/vps-email /var/www/shared/src/Storage
COPY backend /var/www/vps-email/backend
COPY collab  /var/www/vps-email/collab

# Vendored PHP deps (production only). Built with the real runtime lsphp so the
# platform extension set matches exactly (no --ignore-platform-reqs needed).
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/tmp/composer
RUN cd /var/www/vps-email/backend \
 && /usr/local/lsws/lsphp83/bin/php /usr/bin/composer install \
      --no-dev --optimize-autoloader --no-interaction --no-progress \
 && rm -rf /tmp/composer

# Baked SPA build.
COPY --from=frontend /build/frontend/dist /var/www/vps-email/dist

# The SPA's .htaccess (authored for the native Apache box) force-redirects
# HTTP->HTTPS at the app level. In the container, TLS is the web tier's job
# (ENABLE_SSL + listener), so strip only the force-https rule — the MIME,
# security-header, SPA-fallback and 404 rules are kept (autoLoadHtaccess on).
# When SSL is wired in production, re-add http->https at the listener/vhost.
RUN if [ -f /var/www/vps-email/dist/.htaccess ]; then \
      sed -i -e '/# Force HTTPS/d' \
             -e '/RewriteCond %{HTTPS} off/d' \
             -e '\#RewriteRule .*https://%{HTTP_HOST}#d' \
             /var/www/vps-email/dist/.htaccess ; \
    fi

# OLS vhost + listener + entrypoint + opcache. Strip CRLF (files may be authored
# on Windows) so bash/OLS parse them, then wire the vhost into httpd_config.
COPY docker/web/ols/vhconf.conf /usr/local/lsws/conf/vhosts/flowone/vhconf.conf
COPY docker/web/ols/httpd_config.append.conf /tmp/httpd_config.append.conf
COPY docker/web/entrypoint.sh /flowone-entrypoint.sh
COPY docker/web/opcache.ini /usr/local/lsws/lsphp83/etc/php/8.3/mods-available/zz-opcache.ini
RUN sed -i 's/\r$//' \
      /usr/local/lsws/conf/vhosts/flowone/vhconf.conf \
      /tmp/httpd_config.append.conf \
      /flowone-entrypoint.sh \
 && cat /tmp/httpd_config.append.conf >> /usr/local/lsws/conf/httpd_config.conf \
 && rm -f /tmp/httpd_config.append.conf \
 # Remove the stock demo vhost (vhTemplate docker -> localhost, which force-
 # redirects HTTP->HTTPS) and map our flowone vhost onto the existing :80 listener.
 && sed -i '/^vhTemplate docker {/,/^}/d' /usr/local/lsws/conf/httpd_config.conf \
 && awk '{print} /^listener HTTP \{/{print "  map                     flowone *"}' \
      /usr/local/lsws/conf/httpd_config.conf > /usr/local/lsws/conf/httpd_config.conf.new \
 && mv /usr/local/lsws/conf/httpd_config.conf.new /usr/local/lsws/conf/httpd_config.conf \
 && chmod +x /flowone-entrypoint.sh \
 && mkdir -p /usr/local/lsws/logs \
 && mkdir -p /var/www/vps-email/data/settings \
 && mkdir -p /var/www/vps-email/backend/storage/logs \
 && rm -f /var/www/vps-email/backend/src/config.local.php \
 && chmod -R 777 /var/www/vps-email/data /var/www/vps-email/backend/storage

WORKDIR /var/www/vps-email

# 401 (auth required) means the app booted, the router ran, and the DB/Redis
# stack answered — a meaningful liveness signal for this API. 000 = OLS down.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=5 \
  CMD curl -s -o /dev/null -w '%{http_code}' http://localhost/api/auth/me | grep -qE '^(200|401)$'

ENTRYPOINT ["/flowone-entrypoint.sh"]
