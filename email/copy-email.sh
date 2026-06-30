#!/bin/bash

# Email App Deployment Script
# Copies files from SFTP staging to production
#
# Expected upload structure:
#   /home/email.devcon1.hu/public_html/dist/         (frontend build)
#   /home/email.devcon1.hu/public_html/dist/fonts/   (local fonts — Google Fonts, Material Symbols)
#   /home/email.devcon1.hu/public_html/dist/js/      (local JS — tailwind.min.js)
#   /home/email.devcon1.hu/public_html/backend/      (PHP backend)
#   /home/email.devcon1.hu/public_html/collab/       (collab system)
#   /home/email.devcon1.hu/public_html/mailsync/     (mailsync WebSocket server)
#   /home/email.devcon1.hu/public_html/office/       (OnlyOffice integration: Dockerfile, installer, branding)
#   /home/email.devcon1.hu/public_html/landing/      (landing pages, optional)
#
# Production layout (docRoot = /var/www/vps-email):
#   /var/www/vps-email/index.html          (Vue SPA)
#   /var/www/vps-email/assets/             (Vite-hashed JS/CSS)
#   /var/www/vps-email/fonts/              (local fonts — NO external CDN)
#   /var/www/vps-email/js/                 (local JS libs)
#   /var/www/vps-email/backend/            (PHP API)
#   /var/www/vps-email/landing/            (marketing pages)

# Configuration
STAGING_DIR="/home/email.devcon1.hu/public_html"
PRODUCTION_DIR="/var/www/vps-email"
COLLAB_SERVER_DIR="/opt/collab-server"
MAILSYNC_SERVER_DIR="/var/www/vps-email/mailsync/server"
DATA_DIR="/var/www/vps-email/data"

# --- Web runtime user (CRITICAL) ------------------------------------------
# Every upload (chat / drive / mood) is written to disk by the lsphp worker, so
# the production tree MUST be owned by the user OpenLiteSpeed actually runs lsphp
# as for THIS vhost. Guess wrong and EVERY upload silently fails with "Storage
# directory could not be created" -- this is exactly the chat-attachment outage
# on 2026-06-30: storage had drifted to www-data while lsphp runs as nobody, and
# the old hard-coded "mailflow, else nobody" logic could not have caught it.
#
# So DON'T guess. Detect the user from a directory the PHP runtime created
# itself -- its owner IS the lsphp user. Bonus: if you later configure OLS to
# run lsphp as a dedicated user (e.g. mailflow), deploys follow automatically
# with no edit here. Falls back to the OLS default (nobody:nogroup) on a fresh
# box where nothing has been created yet.
detect_web_owner() {
    local p owner
    for p in \
        "${PRODUCTION_DIR}/storage/drive" \
        "${PRODUCTION_DIR}/storage/mood-uploads" \
        "${PRODUCTION_DIR}/storage/chat_attachments" \
        "${PRODUCTION_DIR}/backend/logs/php_errors.log"; do
        if [ -e "$p" ]; then
            owner="$(stat -c '%U:%G' "$p" 2>/dev/null)"
            # Skip root-owned paths: a root CLI run made those, not lsphp.
            if [ -n "$owner" ] && [ "${owner%%:*}" != "root" ]; then
                echo "$owner"
                return 0
            fi
        fi
    done
    echo "nobody:nogroup"
}
WEB_OWNER="$(detect_web_owner)"
WEB_USER="${WEB_OWNER%%:*}"
WEB_GROUP="${WEB_OWNER##*:}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}========================================${NC}"
echo -e "${YELLOW}  Email App Deployment Script${NC}"
echo -e "${YELLOW}========================================${NC}"
echo ""
echo -e "${GREEN}Web runtime user (auto-detected):${NC} ${WEB_USER}:${WEB_GROUP}"
echo ""

# Check if staging directories exist
if [ ! -d "$STAGING_DIR/dist" ]; then
    echo -e "${RED}Error: Frontend dist folder $STAGING_DIR/dist does not exist!${NC}"
    exit 1
fi

# Staging dist is wiped after every deploy (step 10), so a re-run without a
# fresh upload would delete production assets and copy nothing back.
if [ ! -f "$STAGING_DIR/dist/index.html" ] || [ -z "$(ls -A ${STAGING_DIR}/dist/assets 2>/dev/null)" ]; then
    echo -e "${RED}Error: $STAGING_DIR/dist is empty (no index.html or no assets/).${NC}"
    echo -e "${RED}Upload a fresh frontend build before running this script again.${NC}"
    exit 1
fi

if [ ! -d "$STAGING_DIR/backend" ]; then
    echo -e "${RED}Error: Backend folder $STAGING_DIR/backend does not exist!${NC}"
    exit 1
fi

# Check if production directory exists
if [ ! -d "$PRODUCTION_DIR" ]; then
    echo -e "${RED}Error: Production directory $PRODUCTION_DIR does not exist!${NC}"
    exit 1
fi

echo -e "${GREEN}[1/14]${NC} Cleaning old production assets..."
rm -rf ${PRODUCTION_DIR}/assets/*

echo -e "${GREEN}[2/14]${NC} Copying frontend files from dist/..."
cp ${STAGING_DIR}/dist/index.html ${PRODUCTION_DIR}/
cp ${STAGING_DIR}/dist/favicon.svg ${PRODUCTION_DIR}/ 2>/dev/null || true
cp -r ${STAGING_DIR}/dist/assets/* ${PRODUCTION_DIR}/assets/

echo -e "${GREEN}[2.1/14]${NC} Copying local fonts..."
if [ -d "${STAGING_DIR}/dist/fonts" ]; then
    mkdir -p ${PRODUCTION_DIR}/fonts
    cp -r ${STAGING_DIR}/dist/fonts/* ${PRODUCTION_DIR}/fonts/
    echo "Local fonts deployed ($(ls -d ${PRODUCTION_DIR}/fonts/*/ 2>/dev/null | wc -l) font families)"
else
    echo -e "${YELLOW}WARNING: No fonts directory in dist — icons/fonts may be broken${NC}"
fi

echo -e "${GREEN}[2.2/14]${NC} Copying local JS assets..."
if [ -d "${STAGING_DIR}/dist/js" ]; then
    mkdir -p ${PRODUCTION_DIR}/js
    cp -r ${STAGING_DIR}/dist/js/* ${PRODUCTION_DIR}/js/
    echo "Local JS assets deployed"
else
    echo "No js directory in dist, skipping..."
fi

echo -e "${GREEN}[2.3/14]${NC} Copying static pages..."
# Landing page
cp ${STAGING_DIR}/dist/landing.html ${PRODUCTION_DIR}/ 2>/dev/null || true
# SEO files
cp ${STAGING_DIR}/dist/robots.txt ${PRODUCTION_DIR}/ 2>/dev/null || true
cp ${STAGING_DIR}/dist/sitemap.xml ${PRODUCTION_DIR}/ 2>/dev/null || true
# Legal pages
if [ -d "${STAGING_DIR}/dist/privacy" ]; then
    mkdir -p ${PRODUCTION_DIR}/privacy
    cp -r ${STAGING_DIR}/dist/privacy/* ${PRODUCTION_DIR}/privacy/
fi
if [ -d "${STAGING_DIR}/dist/terms" ]; then
    mkdir -p ${PRODUCTION_DIR}/terms
    cp -r ${STAGING_DIR}/dist/terms/* ${PRODUCTION_DIR}/terms/
fi

echo -e "${GREEN}[2.4/14]${NC} Copying landing pages..."
if [ -d "${STAGING_DIR}/landing" ]; then
    mkdir -p ${PRODUCTION_DIR}/landing
    cp -r ${STAGING_DIR}/landing/* ${PRODUCTION_DIR}/landing/
    echo "Landing pages deployed"
else
    echo "No landing directory found, skipping..."
fi

echo -e "${GREEN}[3/14]${NC} Copying PWA files..."
# Service worker and manifest
cp ${STAGING_DIR}/dist/registerSW.js ${PRODUCTION_DIR}/ 2>/dev/null || true
cp ${STAGING_DIR}/dist/sw.js ${PRODUCTION_DIR}/ 2>/dev/null || true
cp ${STAGING_DIR}/dist/push-sw.js ${PRODUCTION_DIR}/ 2>/dev/null || true
cp ${STAGING_DIR}/dist/mood-cache-sw.js ${PRODUCTION_DIR}/ 2>/dev/null || true
cp ${STAGING_DIR}/dist/manifest.webmanifest ${PRODUCTION_DIR}/ 2>/dev/null || true
cp ${STAGING_DIR}/dist/workbox-*.js ${PRODUCTION_DIR}/ 2>/dev/null || true
# PWA icons
cp ${STAGING_DIR}/dist/apple-touch-icon.png ${PRODUCTION_DIR}/ 2>/dev/null || true
cp ${STAGING_DIR}/dist/pwa-192x192.png ${PRODUCTION_DIR}/ 2>/dev/null || true
cp ${STAGING_DIR}/dist/pwa-512x512.png ${PRODUCTION_DIR}/ 2>/dev/null || true

echo -e "${GREEN}[3.1/14]${NC} Copying notification sounds..."
# Served from the web root at /sounds/*.mp3 (notificationSounds.js). If these
# are missing the SPA fallback returns index.html for the .mp3 request, decoding
# fails, and the app degrades to synthesized beeps ("old sound still plays" bug).
if [ -d "${STAGING_DIR}/dist/sounds" ]; then
    mkdir -p ${PRODUCTION_DIR}/sounds
    cp -r ${STAGING_DIR}/dist/sounds/* ${PRODUCTION_DIR}/sounds/
    echo "Notification sounds deployed ($(ls ${PRODUCTION_DIR}/sounds/ 2>/dev/null | wc -l) files)"
else
    echo -e "${YELLOW}WARNING: No sounds directory in dist — notification sounds will fall back to synth beeps${NC}"
fi

echo -e "${GREEN}[3.2/14]${NC} Copying brand logo (root favicon + notification icon)..."
# Referenced at the web root as /flowone-logo.png (index.html favicon, legal
# pages, PWA includeAssets). Missing => broken favicon and notification icon.
if cp ${STAGING_DIR}/dist/flowone-logo.png ${PRODUCTION_DIR}/ 2>/dev/null; then
    echo "flowone-logo.png deployed"
else
    echo -e "${YELLOW}WARNING: flowone-logo.png missing in dist — favicon/notification icon may break${NC}"
fi

echo -e "${GREEN}[4/14]${NC} Copying backend files..."
# Backup backend .env if it exists (contains secrets - must not be overwritten)
if [ -f "${PRODUCTION_DIR}/backend/.env" ]; then
    cp ${PRODUCTION_DIR}/backend/.env /tmp/backend-env-backup
    echo "Backed up existing backend .env"
fi
cp -r ${STAGING_DIR}/backend/* ${PRODUCTION_DIR}/backend/
# Restore backend .env
if [ -f "/tmp/backend-env-backup" ]; then
    cp /tmp/backend-env-backup ${PRODUCTION_DIR}/backend/.env
    rm /tmp/backend-env-backup
    echo "Restored backend .env"
fi

# Strip dev-only override files that must NEVER exist on production.
# config.local.php is a developer-machine override (smtp/imap pointing at
# remote hosts for local dev). If it ends up on the VPS it overrides
# smtp.host -> mail.devcon1.hu, which routes outbound mail out the public
# interface and triggers OpenDMARC rejections (see 2026-05-20 incident).
echo -e "${GREEN}[4.05/14]${NC} Stripping dev-only override files from production..."
DEV_ONLY_FILES=(
    "${PRODUCTION_DIR}/backend/src/config.local.php"
    "${PRODUCTION_DIR}/backend/.env.local"
    "${PRODUCTION_DIR}/backend/.env.development"
)
for devfile in "${DEV_ONLY_FILES[@]}"; do
    if [ -f "$devfile" ]; then
        rm -f "$devfile"
        echo -e "${YELLOW}  Removed dev-only file: $devfile${NC}"
    fi
done
# Also strip from any staging area so subsequent runs are clean
rm -f "${STAGING_DIR}/backend/src/config.local.php" 2>/dev/null || true

# Fix Windows CRLF line endings in PHP files (prevents parsing errors).
# vendor/ is excluded: touching composer-managed files makes composer see
# "uncommitted changes" and breaks subsequent installs.
echo -e "${GREEN}[4.1/14]${NC} Converting PHP line endings to Unix format..."
find ${PRODUCTION_DIR}/backend -path "${PRODUCTION_DIR}/backend/vendor" -prune -o -name "*.php" -exec sed -i 's/\r$//' {} \;

echo -e "${GREEN}[4.5/14]${NC} Cleaning up duplicate migration files..."
# Remove any migration files without proper prefix (duplicates)
find ${PRODUCTION_DIR}/backend/migrations/ -maxdepth 1 -name "*.sql" ! -name "[0-9]*_*.sql" -delete 2>/dev/null || true

echo -e "${GREEN}[5/14]${NC} Copying collab backend files..."
if [ -d "$STAGING_DIR/collab/backend" ]; then
    mkdir -p ${PRODUCTION_DIR}/collab/backend
    cp -r ${STAGING_DIR}/collab/backend/* ${PRODUCTION_DIR}/collab/backend/
    echo "Collab backend files copied"
else
    echo "No collab backend folder found, skipping..."
fi

# Copy shared collab files (used by both PHP and Node.js)
if [ -d "$STAGING_DIR/collab/shared" ]; then
    mkdir -p ${PRODUCTION_DIR}/collab/shared
    cp -r ${STAGING_DIR}/collab/shared/* ${PRODUCTION_DIR}/collab/shared/
    echo "Collab shared files copied"
fi

echo -e "${GREEN}[5.5/14]${NC} Deploying collab WebSocket server..."
if [ -d "$STAGING_DIR/collab/server" ]; then
    # Create collab server directory if it doesn't exist
    mkdir -p ${COLLAB_SERVER_DIR}

    # Backup .env if it exists (contains secrets)
    if [ -f "${COLLAB_SERVER_DIR}/.env" ]; then
        cp ${COLLAB_SERVER_DIR}/.env /tmp/collab-server-env-backup
        echo "Backed up existing .env"
    fi

    # Remove old TypeScript dist folder (no longer needed - now using plain JS)
    if [ -d "${COLLAB_SERVER_DIR}/dist" ]; then
        rm -rf ${COLLAB_SERVER_DIR}/dist
        echo "Removed old TypeScript dist folder"
    fi

    # Copy new JavaScript source files
    mkdir -p ${COLLAB_SERVER_DIR}/src
    cp -r ${STAGING_DIR}/collab/server/src/* ${COLLAB_SERVER_DIR}/src/
    
    # Copy package files
    cp ${STAGING_DIR}/collab/server/package.json ${COLLAB_SERVER_DIR}/
    cp ${STAGING_DIR}/collab/server/package-lock.json ${COLLAB_SERVER_DIR}/ 2>/dev/null || true
    
    # Copy service file
    cp ${STAGING_DIR}/collab/server/collab-server.service ${COLLAB_SERVER_DIR}/ 2>/dev/null || true

    # Copy shared constants (Node.js server imports from ../shared/)
    mkdir -p ${COLLAB_SERVER_DIR}/../shared
    if [ -d "$STAGING_DIR/collab/shared" ]; then
        cp -r ${STAGING_DIR}/collab/shared/* ${COLLAB_SERVER_DIR}/../shared/
        echo "Copied shared constants for Node.js server"
    fi

    # Restore .env if it was backed up
    if [ -f "/tmp/collab-server-env-backup" ]; then
        cp /tmp/collab-server-env-backup ${COLLAB_SERVER_DIR}/.env
        rm /tmp/collab-server-env-backup
        echo "Restored .env"
    fi

    # Copy JWT public key for RS256 verification
    JWT_PUBLIC_KEY="${PRODUCTION_DIR}/backend/storage/config/jwt-public.pem"
    if [ -f "$JWT_PUBLIC_KEY" ]; then
        cp "$JWT_PUBLIC_KEY" "${COLLAB_SERVER_DIR}/jwt-public.pem"
        # Sync JWT settings from backend .env
        BACKEND_ENV="${PRODUCTION_DIR}/backend/.env"
        COLLAB_ENV="${COLLAB_SERVER_DIR}/.env"
        if [ -f "$BACKEND_ENV" ] && [ -f "$COLLAB_ENV" ]; then
            BACKEND_JWT=$(grep '^JWT_SECRET=' "$BACKEND_ENV" | head -1)
            BACKEND_ALGO=$(grep '^JWT_ALGORITHM=' "$BACKEND_ENV" | head -1)
            if [ -n "$BACKEND_JWT" ]; then
                sed -i '/^JWT_SECRET=/d' "$COLLAB_ENV"
                echo "$BACKEND_JWT" >> "$COLLAB_ENV"
            fi
            if [ -n "$BACKEND_ALGO" ]; then
                sed -i '/^JWT_ALGORITHM=/d' "$COLLAB_ENV"
                echo "$BACKEND_ALGO" >> "$COLLAB_ENV"
            fi
        fi
        echo "Copied JWT public key and settings to collab server"
    else
        echo -e "${YELLOW}WARNING: JWT public key not found — run generate-jwt-keys.sh${NC}"
    fi

    # Sync PANEL_API_KEY and PANEL_API_URL for audit logging
    COLLAB_ENV="${COLLAB_SERVER_DIR}/.env"
    if [ -f "$BACKEND_ENV" ] && [ -f "$COLLAB_ENV" ]; then
        PANEL_KEY=$(grep '^PANEL_API_KEY=' "$BACKEND_ENV" | head -1)
        PANEL_URL=$(grep '^PANEL_API_URL=' "$BACKEND_ENV" | head -1)
        if [ -n "$PANEL_KEY" ]; then
            sed -i '/^PANEL_API_KEY=/d' "$COLLAB_ENV"
            echo "$PANEL_KEY" >> "$COLLAB_ENV"
        fi
        if [ -n "$PANEL_URL" ]; then
            sed -i '/^PANEL_API_URL=/d' "$COLLAB_ENV"
            echo "$PANEL_URL" >> "$COLLAB_ENV"
        fi
        echo "Synced PANEL_API_KEY/URL to collab .env"
    fi

    # Install/update npm dependencies
    cd ${COLLAB_SERVER_DIR}
    if [ -f "package.json" ]; then
        echo "Installing collab server dependencies..."
        npm install --production 2>/dev/null || echo "npm install skipped (may need manual run)"
    fi

    # Update systemd service file if it exists
    if [ -f "${COLLAB_SERVER_DIR}/collab-server.service" ]; then
        cp ${COLLAB_SERVER_DIR}/collab-server.service /etc/systemd/system/
        systemctl daemon-reload
        echo "Updated systemd service file"
    fi

    # Restart collab server service if it exists
    if systemctl is-enabled collab-server 2>/dev/null; then
        echo "Restarting collab-server service..."
        systemctl restart collab-server
        sleep 2
        if systemctl is-active collab-server 2>/dev/null; then
            echo -e "${GREEN}collab-server started successfully${NC}"
        else
            echo -e "${RED}collab-server failed to start! Check logs with: journalctl -u collab-server -n 50${NC}"
        fi
    else
        echo "collab-server service not enabled, skipping restart"
    fi

    cd - > /dev/null
    echo "Collab WebSocket server deployed (Plain JavaScript)"
else
    echo "No collab server folder found, skipping..."
fi

echo -e "${GREEN}[5.6/14]${NC} Deploying mailsync WebSocket server..."
if [ -d "$STAGING_DIR/mailsync/server" ]; then
    # Create mailsync server directory if it doesn't exist
    mkdir -p ${MAILSYNC_SERVER_DIR}

    # Backup .env if it exists (contains secrets)
    if [ -f "${MAILSYNC_SERVER_DIR}/.env" ]; then
        cp ${MAILSYNC_SERVER_DIR}/.env /tmp/mailsync-server-env-backup
        echo "Backed up existing mailsync .env"
    fi

    # Copy source files
    mkdir -p ${MAILSYNC_SERVER_DIR}/src
    cp -r ${STAGING_DIR}/mailsync/server/src/* ${MAILSYNC_SERVER_DIR}/src/

    # Copy package files
    cp ${STAGING_DIR}/mailsync/server/package.json ${MAILSYNC_SERVER_DIR}/
    cp ${STAGING_DIR}/mailsync/server/package-lock.json ${MAILSYNC_SERVER_DIR}/ 2>/dev/null || true

    # Copy service file
    cp ${STAGING_DIR}/mailsync/server/mailsync-server.service ${MAILSYNC_SERVER_DIR}/ 2>/dev/null || true

    # Copy server-side test suite (+ shared runner). The backend deploys its
    # tests/ as part of backend/*; mailsync only ships src/, so without this the
    # Node test scripts (e.g. fcm-delivery-test.js) never reach the server.
    if [ -d "${STAGING_DIR}/mailsync/server/tests" ]; then
        mkdir -p ${MAILSYNC_SERVER_DIR}/tests
        cp -r ${STAGING_DIR}/mailsync/server/tests/* ${MAILSYNC_SERVER_DIR}/tests/
        echo "Mailsync test suite copied"
    fi

    # Restore .env if it was backed up
    if [ -f "/tmp/mailsync-server-env-backup" ]; then
        cp /tmp/mailsync-server-env-backup ${MAILSYNC_SERVER_DIR}/.env
        rm /tmp/mailsync-server-env-backup
        echo "Restored mailsync .env"
    fi

    # If no .env exists yet, create one with VAPID keys for push notifications
    if [ ! -f "${MAILSYNC_SERVER_DIR}/.env" ]; then
        cat > ${MAILSYNC_SERVER_DIR}/.env << 'ENVEOF'
# VAPID Keys for Web Push Notifications
VAPID_PUBLIC_KEY=BGGfrCJodx7M3Se36uWzy_Vxnxh94irAA_gw2bHPKshJMMoGwEQlG9z8k3GpDBJsw72Nd5x_75QHTAVEY4toqlw
VAPID_PRIVATE_KEY=hbqSxU6PNsh31tapt8ondjQ-VUiDIsITrfpvHHUiTIU
VAPID_SUBJECT=mailto:admin@devcon1.hu
ENVEOF
        echo "Created initial mailsync .env with VAPID keys"
    fi

    # Sync JWT_SECRET from backend .env to mailsync .env (kept for HS256 fallback during migration)
    BACKEND_ENV="${PRODUCTION_DIR}/backend/.env"
    MAILSYNC_ENV="${MAILSYNC_SERVER_DIR}/.env"
    if [ -f "$BACKEND_ENV" ]; then
        BACKEND_JWT=$(grep '^JWT_SECRET=' "$BACKEND_ENV" | head -1)
        if [ -n "$BACKEND_JWT" ]; then
            sed -i '/^JWT_SECRET=/d' "$MAILSYNC_ENV"
            echo "$BACKEND_JWT" >> "$MAILSYNC_ENV"
            echo "Synced JWT_SECRET from backend to mailsync .env"
        else
            echo -e "${YELLOW}WARNING: JWT_SECRET not found in backend .env${NC}"
        fi
    fi

    # Copy JWT public key for RS256 verification
    JWT_PUBLIC_KEY="${PRODUCTION_DIR}/backend/storage/config/jwt-public.pem"
    if [ -f "$JWT_PUBLIC_KEY" ]; then
        cp "$JWT_PUBLIC_KEY" "${MAILSYNC_SERVER_DIR}/jwt-public.pem"
        # Sync JWT_ALGORITHM from backend .env
        if [ -f "$BACKEND_ENV" ]; then
            BACKEND_ALGO=$(grep '^JWT_ALGORITHM=' "$BACKEND_ENV" | head -1)
            if [ -n "$BACKEND_ALGO" ]; then
                sed -i '/^JWT_ALGORITHM=/d' "$MAILSYNC_ENV"
                echo "$BACKEND_ALGO" >> "$MAILSYNC_ENV"
            fi
        fi
        echo "Copied JWT public key and algorithm to mailsync"
    else
        echo -e "${YELLOW}WARNING: JWT public key not found at $JWT_PUBLIC_KEY — run generate-jwt-keys.sh${NC}"
    fi

    # Sync PANEL_API_KEY and PANEL_API_URL for audit logging
    if [ -f "$BACKEND_ENV" ]; then
        PANEL_KEY=$(grep '^PANEL_API_KEY=' "$BACKEND_ENV" | head -1)
        PANEL_URL=$(grep '^PANEL_API_URL=' "$BACKEND_ENV" | head -1)
        if [ -n "$PANEL_KEY" ]; then
            sed -i '/^PANEL_API_KEY=/d' "$MAILSYNC_ENV"
            echo "$PANEL_KEY" >> "$MAILSYNC_ENV"
        fi
        if [ -n "$PANEL_URL" ]; then
            sed -i '/^PANEL_API_URL=/d' "$MAILSYNC_ENV"
            echo "$PANEL_URL" >> "$MAILSYNC_ENV"
        fi
        echo "Synced PANEL_API_KEY/URL to mailsync .env"
    fi

    # Install/update npm dependencies
    cd ${MAILSYNC_SERVER_DIR}
    if [ -f "package.json" ]; then
        echo "Installing mailsync server dependencies..."
        npm install --production 2>/dev/null || echo "npm install skipped (may need manual run)"
    fi

    # Update systemd service file if it exists
    if [ -f "${MAILSYNC_SERVER_DIR}/mailsync-server.service" ]; then
        cp ${MAILSYNC_SERVER_DIR}/mailsync-server.service /etc/systemd/system/
        systemctl daemon-reload
        echo "Updated mailsync systemd service file"
    fi

    # Restart mailsync server service if it exists
    if systemctl is-enabled mailsync-server 2>/dev/null; then
        echo "Restarting mailsync-server service..."
        systemctl restart mailsync-server
        sleep 2
        if systemctl is-active mailsync-server 2>/dev/null; then
            echo -e "${GREEN}mailsync-server started successfully${NC}"
        else
            echo -e "${RED}mailsync-server failed to start! Check logs with: journalctl -u mailsync-server -n 50${NC}"
        fi
    else
        echo "mailsync-server service not enabled, skipping restart"
    fi

    cd - > /dev/null
    echo "Mailsync WebSocket server deployed"
else
    echo "No mailsync server folder found, skipping..."
fi

echo -e "${GREEN}[5.7/14]${NC} Copying OnlyOffice integration files..."
if [ -d "$STAGING_DIR/office" ]; then
    mkdir -p ${PRODUCTION_DIR}/office
    cp -r ${STAGING_DIR}/office/* ${PRODUCTION_DIR}/office/
    # Fix Windows CRLF line endings in shell scripts and make installer runnable
    find ${PRODUCTION_DIR}/office -name "*.sh" -exec sed -i 's/\r$//' {} \;
    chmod +x ${PRODUCTION_DIR}/office/install-onlyoffice.sh 2>/dev/null || true
    echo "Office files copied (container/config untouched — run install-onlyoffice.sh only for first install or image updates)"
else
    echo "No office folder found, skipping..."
fi

echo -e "${GREEN}[6/14]${NC} Setting correct ownership (${WEB_USER}:${WEB_GROUP})..."
chown -R ${WEB_USER}:${WEB_GROUP} ${PRODUCTION_DIR}/

echo -e "${GREEN}[6.5/14]${NC} Ensuring storage upload directories exist (owned by ${WEB_USER}:${WEB_GROUP})..."
# Runtime upload targets. PHP creates the per-conversation / per-file subdirs
# lazily, but ONLY if these parents exist and are writable by the lsphp user.
# The chown above already re-owned any that existed; this guarantees they exist
# at all and are owned correctly even on a fresh box -- so an upload can never
# dead-end on a missing or mis-owned storage dir again (chat outage 2026-06-30).
STORAGE_DIR="${PRODUCTION_DIR}/storage"
for sub in chat_attachments drive mood-uploads; do
    d="${STORAGE_DIR}/${sub}"
    mkdir -p "$d"                        # created as root when new...
    chown ${WEB_USER}:${WEB_GROUP} "$d"  # ...so hand it to the web user
    chmod 775 "$d"
done

echo -e "${GREEN}[7/14]${NC} Ensuring data directory exists with correct permissions..."
# Create data directory and subdirectories if they don't exist
mkdir -p ${DATA_DIR}/settings
mkdir -p ${DATA_DIR}/sync-queue
mkdir -p ${DATA_DIR}/labels
mkdir -p ${DATA_DIR}/filters
mkdir -p ${DATA_DIR}/todos
mkdir -p ${DATA_DIR}/inline-images
mkdir -p ${DATA_DIR}/drive
mkdir -p ${DATA_DIR}/calendar
mkdir -p ${DATA_DIR}/global
mkdir -p ${DATA_DIR}/threads

# Set ownership and permissions for data directory
chown -R ${WEB_USER}:${WEB_GROUP} ${DATA_DIR}
chmod -R 755 ${DATA_DIR}

echo -e "${GREEN}[8/14]${NC} Ensuring .well-known directory exists..."
mkdir -p ${PRODUCTION_DIR}/.well-known/acme-challenge
chown -R ${WEB_USER}:${WEB_GROUP} ${PRODUCTION_DIR}/.well-known

echo -e "${GREEN}[9/14]${NC} Installing PHP dependencies and regenerating autoloader..."
cd ${PRODUCTION_DIR}/backend
# Run composer with lsphp83 (the system 'composer' shim uses PHP 8.1 which
# resolves dependencies against the wrong platform). DISCARD_CHANGES avoids
# prompts when deploy-time file tweaks made vendor look dirty.
COMPOSER_BIN="$(command -v composer || echo /usr/local/bin/composer)"
PHP83=/usr/local/lsws/lsphp83/bin/php
${PHP83} "${COMPOSER_BIN}" config --no-interaction allow-plugins.php-http/discovery true 2>/dev/null || true
COMPOSER_DISCARD_CHANGES=true ${PHP83} "${COMPOSER_BIN}" install --no-dev --no-interaction --prefer-dist --optimize-autoloader 2>/dev/null || {
    echo -e "${YELLOW}composer install failed, falling back to dump-autoload${NC}"
    ${PHP83} "${COMPOSER_BIN}" dump-autoload -o --no-interaction 2>/dev/null || echo "Composer dump-autoload skipped"
}
cd - > /dev/null

# Composer runs as root AFTER the global chown (step 6), so anything it
# (re)installed would stay root-owned and unreadable by the web user
# (root umask is restrictive on this box). Re-own and normalize perms.
echo -e "${GREEN}[9.5/14]${NC} Fixing vendor ownership/permissions for web user..."
chown -R ${WEB_USER}:${WEB_GROUP} ${PRODUCTION_DIR}/backend/vendor ${PRODUCTION_DIR}/backend/composer.lock 2>/dev/null || true
find ${PRODUCTION_DIR}/backend/vendor -type d -exec chmod 755 {} + 2>/dev/null || true
find ${PRODUCTION_DIR}/backend/vendor -type f -exec chmod 644 {} + 2>/dev/null || true

echo -e "${GREEN}[10/14]${NC} Cleaning staging dist directory..."
rm -rf ${STAGING_DIR}/dist/*
echo "Staging dist folder cleared (backend/collab/mailsync preserved)"

echo -e "${GREEN}[11/14]${NC} Clearing PHP cache..."
# Try to restart PHP if available
systemctl restart lsphp83 2>/dev/null || true
killall -USR1 lsphp 2>/dev/null || true

echo -e "${GREEN}[12/14]${NC} Restarting OpenLiteSpeed (picks up new static files)..."
systemctl restart lsws 2>/dev/null || /usr/local/lsws/bin/lswsctrl restart 2>/dev/null || true

echo -e "${GREEN}[13/14]${NC} Deployment verification..."
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Deployment Complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Production files:"
ls -la ${PRODUCTION_DIR}/
echo ""
echo "Assets:"
ls -la ${PRODUCTION_DIR}/assets/ | head -10
echo ""
echo "Local fonts:"
if [ -d "${PRODUCTION_DIR}/fonts" ]; then
    FONT_COUNT=$(ls -d ${PRODUCTION_DIR}/fonts/*/ 2>/dev/null | wc -l)
    echo "  ${FONT_COUNT} font families in ${PRODUCTION_DIR}/fonts/"
    ls ${PRODUCTION_DIR}/fonts/core.css 2>/dev/null && echo "  core.css OK" || echo -e "  ${RED}core.css MISSING${NC}"
    ls ${PRODUCTION_DIR}/fonts/material-symbols-rounded/font.woff2 2>/dev/null && echo "  Material Symbols OK" || echo -e "  ${RED}Material Symbols MISSING${NC}"
else
    echo -e "  ${RED}fonts/ directory MISSING — icons will be broken!${NC}"
fi
echo ""
echo "Local JS:"
if [ -f "${PRODUCTION_DIR}/js/tailwind.min.js" ]; then
    echo "  tailwind.min.js OK ($(du -h ${PRODUCTION_DIR}/js/tailwind.min.js | cut -f1))"
else
    echo "  No local tailwind.min.js (landing pages may need CDN)"
fi
echo ""
echo "PWA files:"
ls -la ${PRODUCTION_DIR}/*.js ${PRODUCTION_DIR}/*.webmanifest ${PRODUCTION_DIR}/*.png 2>/dev/null || echo "No PWA files found"
echo ""
echo "Data directory permissions:"
ls -la ${DATA_DIR}/
echo ""
echo "PHP dependencies:"
ls ${PRODUCTION_DIR}/backend/vendor/tecnickcom/tcpdf/tcpdf.php 2>/dev/null && echo "  TCPDF OK (PDF export)" || echo -e "  ${YELLOW}TCPDF not installed (PDF export will fail)${NC}"
ls ${PRODUCTION_DIR}/backend/vendor/phpoffice/phppresentation/src/ 2>/dev/null >/dev/null && echo "  PhpPresentation OK (PPTX export)" || echo -e "  ${YELLOW}PhpPresentation not installed (PPTX export will fail)${NC}"
echo ""
echo "Collab server files:"
if [ -d "${COLLAB_SERVER_DIR}/src" ]; then
    echo "  Source: ${COLLAB_SERVER_DIR}/src/"
    ls -la ${COLLAB_SERVER_DIR}/src/
fi
echo ""
echo "Collab server status:"
if systemctl is-active collab-server 2>/dev/null; then
    systemctl status collab-server --no-pager | head -5
else
    echo "collab-server service not running"
fi
echo ""
echo "Mailsync server status:"
if systemctl is-active mailsync-server 2>/dev/null; then
    systemctl status mailsync-server --no-pager | head -5
else
    echo "mailsync-server service not running"
fi

echo ""
echo -e "${GREEN}[14/14]${NC} Quick health check..."
# Verify critical files exist
HEALTH_OK=true
[ ! -f "${PRODUCTION_DIR}/index.html" ] && echo -e "${RED}  MISSING: index.html${NC}" && HEALTH_OK=false
[ ! -f "${PRODUCTION_DIR}/backend/public/index.php" ] && echo -e "${RED}  MISSING: backend/public/index.php${NC}" && HEALTH_OK=false
[ ! -d "${PRODUCTION_DIR}/fonts/material-symbols-rounded" ] && echo -e "${RED}  MISSING: fonts/material-symbols-rounded${NC}" && HEALTH_OK=false
[ ! -f "${PRODUCTION_DIR}/fonts/core.css" ] && echo -e "${RED}  MISSING: fonts/core.css${NC}" && HEALTH_OK=false
[ ! -f "${PRODUCTION_DIR}/sounds/new-email.mp3" ] && echo -e "${RED}  MISSING: sounds/new-email.mp3 — notifications will fall back to synth beeps${NC}" && HEALTH_OK=false
[ ! -f "${PRODUCTION_DIR}/sounds/new-chat.mp3" ] && echo -e "${RED}  MISSING: sounds/new-chat.mp3 — notifications will fall back to synth beeps${NC}" && HEALTH_OK=false
[ ! -f "${PRODUCTION_DIR}/flowone-logo.png" ] && echo -e "${RED}  MISSING: flowone-logo.png — favicon/notification icon broken${NC}" && HEALTH_OK=false
# Verify dev-only override files are NOT present
[ -f "${PRODUCTION_DIR}/backend/src/config.local.php" ] && echo -e "${RED}  PRESENT (should NOT be): backend/src/config.local.php — will trigger DMARC reject${NC}" && HEALTH_OK=false
# Verify storage upload dirs exist AND are owned by the web user. Mis-ownership
# here is invisible until someone tries to upload, then every chat/drive/mood
# upload fails with "Storage directory could not be created" (outage 2026-06-30).
for sub in chat_attachments drive mood-uploads; do
    d="${PRODUCTION_DIR}/storage/${sub}"
    if [ ! -d "$d" ]; then
        echo -e "${RED}  MISSING storage dir: $d — uploads will fail${NC}" && HEALTH_OK=false
    else
        UPLOAD_OWNER="$(stat -c '%U:%G' "$d" 2>/dev/null)"
        if [ "$UPLOAD_OWNER" != "${WEB_USER}:${WEB_GROUP}" ]; then
            echo -e "${RED}  WRONG OWNER on $d: ${UPLOAD_OWNER} (expected ${WEB_USER}:${WEB_GROUP}) — uploads will fail${NC}" && HEALTH_OK=false
        fi
    fi
done
if $HEALTH_OK; then
    echo -e "  ${GREEN}All critical files present, storage upload dirs owned by ${WEB_USER}:${WEB_GROUP}, no dev-only files leaked${NC}"
fi

echo ""
echo -e "${YELLOW}Remember to hard refresh your browser (Ctrl+Shift+R)${NC}"

