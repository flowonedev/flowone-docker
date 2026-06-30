#!/bin/bash
#
# DEVCON Fleet Manager - Config Extractor
# Extracts server configuration to create a blueprint
#
# Usage: ./extract-config.sh [output_dir]
#

set -e

OUTPUT_DIR="${1:-./extracted-configs}"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
EXTRACT_DIR="${OUTPUT_DIR}/extraction_${TIMESTAMP}"

echo "=========================================="
echo "DEVCON Fleet Manager - Config Extractor"
echo "=========================================="
echo ""

# Create output directories
mkdir -p "${EXTRACT_DIR}/openlitespeed"
mkdir -p "${EXTRACT_DIR}/php"
mkdir -p "${EXTRACT_DIR}/mariadb"
mkdir -p "${EXTRACT_DIR}/postfix"
mkdir -p "${EXTRACT_DIR}/dovecot"
mkdir -p "${EXTRACT_DIR}/fail2ban"
mkdir -p "${EXTRACT_DIR}/firewalld"
mkdir -p "${EXTRACT_DIR}/openvpn"
mkdir -p "${EXTRACT_DIR}/systemd"
mkdir -p "${EXTRACT_DIR}/panel"
mkdir -p "${EXTRACT_DIR}/email_app"
mkdir -p "${EXTRACT_DIR}/other"

echo "Extracting to: ${EXTRACT_DIR}"
echo ""

# Collect server info
echo "Collecting server information..."
cat > "${EXTRACT_DIR}/server_info.json" << EOF
{
    "hostname": "$(hostname)",
    "ip_address": "$(hostname -I | awk '{print $1}')",
    "os": "$(cat /etc/os-release | grep PRETTY_NAME | cut -d'"' -f2)",
    "kernel": "$(uname -r)",
    "extracted_at": "$(date -Iseconds)",
    "extractor_version": "1.0.0"
}
EOF

# ==========================================
# OpenLiteSpeed
# ==========================================
echo "Extracting OpenLiteSpeed configs..."

if [ -d "/usr/local/lsws/conf" ]; then
    # Main config
    cp -r /usr/local/lsws/conf/httpd_config.conf "${EXTRACT_DIR}/openlitespeed/" 2>/dev/null || true
    
    # Virtual hosts - SKIP extraction
    # Vhosts are created from templates during provisioning (panel, email, fleet)
    # Client site vhosts are created dynamically via the panel
    # if [ -d "/usr/local/lsws/conf/vhosts" ]; then
    #     cp -r /usr/local/lsws/conf/vhosts "${EXTRACT_DIR}/openlitespeed/" 2>/dev/null || true
    # fi
    
    # Templates
    if [ -d "/usr/local/lsws/conf/templates" ]; then
        cp -r /usr/local/lsws/conf/templates "${EXTRACT_DIR}/openlitespeed/" 2>/dev/null || true
    fi
    
    echo "  - OpenLiteSpeed configs extracted"
else
    echo "  - OpenLiteSpeed not found, skipping"
fi

# ==========================================
# PHP
# ==========================================
echo "Extracting PHP configs..."

for PHP_VERSION in 7.4 8.0 8.1 8.2 8.3; do
    PHP_INI="/usr/local/lsws/lsphp${PHP_VERSION//./}/etc/php/${PHP_VERSION}/litespeed/php.ini"
    if [ -f "$PHP_INI" ]; then
        mkdir -p "${EXTRACT_DIR}/php/${PHP_VERSION}"
        cp "$PHP_INI" "${EXTRACT_DIR}/php/${PHP_VERSION}/" 2>/dev/null || true
        echo "  - PHP ${PHP_VERSION} config extracted"
    fi
done

# Also check system PHP
if [ -f "/etc/php/8.3/fpm/php.ini" ]; then
    mkdir -p "${EXTRACT_DIR}/php/system"
    cp /etc/php/8.3/fpm/php.ini "${EXTRACT_DIR}/php/system/" 2>/dev/null || true
fi

# ==========================================
# MariaDB
# ==========================================
echo "Extracting MariaDB configs..."

if [ -f "/etc/mysql/mariadb.conf.d/50-server.cnf" ]; then
    cp /etc/mysql/mariadb.conf.d/50-server.cnf "${EXTRACT_DIR}/mariadb/" 2>/dev/null || true
    echo "  - MariaDB server config extracted"
fi

if [ -f "/etc/mysql/my.cnf" ]; then
    cp /etc/mysql/my.cnf "${EXTRACT_DIR}/mariadb/" 2>/dev/null || true
fi

# ==========================================
# Postfix
# ==========================================
echo "Extracting Postfix configs..."

if [ -d "/etc/postfix" ]; then
    cp /etc/postfix/main.cf "${EXTRACT_DIR}/postfix/" 2>/dev/null || true
    cp /etc/postfix/master.cf "${EXTRACT_DIR}/postfix/" 2>/dev/null || true
    cp /etc/postfix/virtual* "${EXTRACT_DIR}/postfix/" 2>/dev/null || true
    cp /etc/postfix/mysql-*.cf "${EXTRACT_DIR}/postfix/" 2>/dev/null || true
    cp /etc/postfix/header_checks "${EXTRACT_DIR}/postfix/" 2>/dev/null || true
    cp /etc/postfix/sasl_passwd "${EXTRACT_DIR}/postfix/" 2>/dev/null || true
    echo "  - Postfix configs extracted"
else
    echo "  - Postfix not found, skipping"
fi

# ==========================================
# Dovecot
# ==========================================
echo "Extracting Dovecot configs..."

if [ -d "/etc/dovecot" ]; then
    cp /etc/dovecot/dovecot.conf "${EXTRACT_DIR}/dovecot/" 2>/dev/null || true
    cp -r /etc/dovecot/conf.d "${EXTRACT_DIR}/dovecot/" 2>/dev/null || true
    cp /etc/dovecot/dovecot-sql.conf.ext "${EXTRACT_DIR}/dovecot/" 2>/dev/null || true
    echo "  - Dovecot configs extracted"
else
    echo "  - Dovecot not found, skipping"
fi

# ==========================================
# Fail2ban
# ==========================================
echo "Extracting Fail2ban configs..."

if [ -d "/etc/fail2ban" ]; then
    cp /etc/fail2ban/jail.local "${EXTRACT_DIR}/fail2ban/" 2>/dev/null || true
    cp /etc/fail2ban/jail.conf "${EXTRACT_DIR}/fail2ban/" 2>/dev/null || true
    
    if [ -d "/etc/fail2ban/jail.d" ]; then
        cp -r /etc/fail2ban/jail.d "${EXTRACT_DIR}/fail2ban/" 2>/dev/null || true
    fi
    
    if [ -d "/etc/fail2ban/filter.d" ]; then
        # Only copy custom filters
        for f in /etc/fail2ban/filter.d/*.local; do
            [ -f "$f" ] && cp "$f" "${EXTRACT_DIR}/fail2ban/filter.d/" 2>/dev/null || true
        done
    fi
    echo "  - Fail2ban configs extracted"
else
    echo "  - Fail2ban not found, skipping"
fi

# ==========================================
# FirewallD
# ==========================================
echo "Extracting FirewallD configs..."

if [ -d "/etc/firewalld" ]; then
    cp /etc/firewalld/firewalld.conf "${EXTRACT_DIR}/firewalld/" 2>/dev/null || true
    
    if [ -d "/etc/firewalld/zones" ]; then
        cp -r /etc/firewalld/zones "${EXTRACT_DIR}/firewalld/" 2>/dev/null || true
    fi
    
    if [ -d "/etc/firewalld/services" ]; then
        cp -r /etc/firewalld/services "${EXTRACT_DIR}/firewalld/" 2>/dev/null || true
    fi
    echo "  - FirewallD configs extracted"
else
    echo "  - FirewallD not found, skipping"
fi

# ==========================================
# OpenVPN (if exists)
# ==========================================
echo "Extracting OpenVPN configs..."

if [ -d "/etc/openvpn" ]; then
    # Copy server config (without keys/certs)
    cp /etc/openvpn/server.conf "${EXTRACT_DIR}/openvpn/" 2>/dev/null || true
    cp /etc/openvpn/client/*.conf "${EXTRACT_DIR}/openvpn/" 2>/dev/null || true
    echo "  - OpenVPN configs extracted (keys excluded)"
else
    echo "  - OpenVPN not found, skipping"
fi

# ==========================================
# Systemd Services
# ==========================================
echo "Extracting custom systemd services..."

# List of custom services to look for
CUSTOM_SERVICES=(
    "vps-agent.service"
    "collab-server.service"
    "mailsync.service"
    "fleet-agent.service"
)

for svc in "${CUSTOM_SERVICES[@]}"; do
    if [ -f "/etc/systemd/system/${svc}" ]; then
        cp "/etc/systemd/system/${svc}" "${EXTRACT_DIR}/systemd/" 2>/dev/null || true
        echo "  - ${svc} extracted"
    fi
done

# ==========================================
# Panel Configuration
# ==========================================
echo "Extracting Panel configs..."

PANEL_PATH="/var/www/vps-admin"
if [ -d "$PANEL_PATH" ]; then
    # Copy config files (without sensitive data)
    if [ -f "${PANEL_PATH}/api/config.php" ]; then
        cp "${PANEL_PATH}/api/config.php" "${EXTRACT_DIR}/panel/" 2>/dev/null || true
    fi
    
    # Schema
    if [ -f "${PANEL_PATH}/api/schema.sql" ]; then
        cp "${PANEL_PATH}/api/schema.sql" "${EXTRACT_DIR}/panel/" 2>/dev/null || true
    fi
    
    # Agent config
    if [ -f "${PANEL_PATH}/agent/config.php" ]; then
        cp "${PANEL_PATH}/agent/config.php" "${EXTRACT_DIR}/panel/agent-config.php" 2>/dev/null || true
    fi
    echo "  - Panel configs extracted"
fi

# ==========================================
# Email App Configuration
# ==========================================
echo "Extracting Email App configs..."

EMAIL_PATH="/var/www/vps-email"
if [ -d "$EMAIL_PATH" ]; then
    # Backend config
    if [ -f "${EMAIL_PATH}/backend/src/config.php" ]; then
        cp "${EMAIL_PATH}/backend/src/config.php" "${EXTRACT_DIR}/email_app/" 2>/dev/null || true
    fi
    
    # Storage config
    if [ -f "${EMAIL_PATH}/backend/storage/config/storage.json" ]; then
        cp "${EMAIL_PATH}/backend/storage/config/storage.json" "${EXTRACT_DIR}/email_app/" 2>/dev/null || true
    fi
    echo "  - Email App configs extracted"
fi

# ==========================================
# Other important configs
# ==========================================
echo "Extracting other configs..."

# SSL/TLS
if [ -d "/etc/letsencrypt" ]; then
    # Just record what domains have certs
    ls /etc/letsencrypt/live/ > "${EXTRACT_DIR}/other/ssl_domains.txt" 2>/dev/null || true
    echo "  - SSL domains list extracted"
fi

# Cron jobs
crontab -l > "${EXTRACT_DIR}/other/crontab_root.txt" 2>/dev/null || true

# /etc/hosts
cp /etc/hosts "${EXTRACT_DIR}/other/" 2>/dev/null || true

# Network config
ip addr show > "${EXTRACT_DIR}/other/network_interfaces.txt" 2>/dev/null || true

# Installed packages list
dpkg --get-selections > "${EXTRACT_DIR}/other/installed_packages.txt" 2>/dev/null || true

# ==========================================
# Generate manifest
# ==========================================
echo ""
echo "Generating manifest..."

cat > "${EXTRACT_DIR}/manifest.json" << EOF
{
    "name": "Extracted from $(hostname)",
    "version": "1.0.0",
    "extracted_at": "$(date -Iseconds)",
    "source_server": "$(hostname)",
    "source_ip": "$(hostname -I | awk '{print $1}')",
    "categories": {
        "openlitespeed": $(ls -1 "${EXTRACT_DIR}/openlitespeed" 2>/dev/null | wc -l),
        "php": $(ls -1R "${EXTRACT_DIR}/php" 2>/dev/null | grep -c "\.ini$" || echo 0),
        "mariadb": $(ls -1 "${EXTRACT_DIR}/mariadb" 2>/dev/null | wc -l),
        "postfix": $(ls -1 "${EXTRACT_DIR}/postfix" 2>/dev/null | wc -l),
        "dovecot": $(ls -1 "${EXTRACT_DIR}/dovecot" 2>/dev/null | wc -l),
        "fail2ban": $(ls -1 "${EXTRACT_DIR}/fail2ban" 2>/dev/null | wc -l),
        "firewalld": $(ls -1 "${EXTRACT_DIR}/firewalld" 2>/dev/null | wc -l),
        "systemd": $(ls -1 "${EXTRACT_DIR}/systemd" 2>/dev/null | wc -l),
        "panel": $(ls -1 "${EXTRACT_DIR}/panel" 2>/dev/null | wc -l),
        "email_app": $(ls -1 "${EXTRACT_DIR}/email_app" 2>/dev/null | wc -l)
    }
}
EOF

# ==========================================
# Summary
# ==========================================
echo ""
echo "=========================================="
echo "Extraction Complete!"
echo "=========================================="
echo ""
echo "Output directory: ${EXTRACT_DIR}"
echo ""
echo "Files extracted:"
find "${EXTRACT_DIR}" -type f | wc -l
echo ""
echo "Next steps:"
echo "1. Review extracted configs for sensitive data"
echo "2. Replace hardcoded values with {{VARIABLES}}"
echo "3. Import into Fleet Manager as a blueprint"
echo ""
echo "To create a tarball:"
echo "  tar -czvf blueprint_${TIMESTAMP}.tar.gz -C ${OUTPUT_DIR} extraction_${TIMESTAMP}"

