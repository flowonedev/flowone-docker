#!/bin/bash
#
# VPS Admin - Pre-Installation Check Script
#
# This script performs a comprehensive check of your system
# to ensure it's ready for VPS Admin installation.
#
# Run this BEFORE the actual installation to identify any issues.
#
# Usage: ./install-check.sh
#

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

ERRORS=0
WARNINGS=0

echo ""
echo -e "${CYAN}========================================${NC}"
echo -e "${CYAN}   VPS Admin Pre-Installation Check${NC}"
echo -e "${CYAN}========================================${NC}"
echo ""

# =====================================================
# System Checks
# =====================================================

echo -e "${BLUE}[SYSTEM]${NC}"

# Check OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    echo -e "  OS: ${GREEN}$PRETTY_NAME${NC}"
else
    echo -e "  OS: ${YELLOW}Unknown${NC}"
fi

# Check architecture
ARCH=$(uname -m)
echo -e "  Architecture: $ARCH"

# Check if root
if [ "$EUID" -eq 0 ]; then
    echo -e "  Root access: ${GREEN}Yes${NC}"
else
    echo -e "  Root access: ${RED}No (required)${NC}"
    ERRORS=$((ERRORS + 1))
fi

# Check systemd
if command -v systemctl &> /dev/null; then
    echo -e "  Systemd: ${GREEN}Available${NC}"
else
    echo -e "  Systemd: ${RED}Not found (required)${NC}"
    ERRORS=$((ERRORS + 1))
fi

echo ""

# =====================================================
# PHP Checks
# =====================================================

echo -e "${BLUE}[PHP]${NC}"

if command -v php &> /dev/null; then
    PHP_VERSION=$(php -v | head -n 1)
    PHP_VER_NUM=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
    
    echo -e "  Version: $PHP_VER_NUM"
    
    # Check version >= 8.1
    PHP_MAJOR=$(php -r "echo PHP_MAJOR_VERSION;")
    PHP_MINOR=$(php -r "echo PHP_MINOR_VERSION;")
    
    if [ "$PHP_MAJOR" -ge 8 ] && [ "$PHP_MINOR" -ge 1 ]; then
        echo -e "  Version check: ${GREEN}OK (8.1+ required)${NC}"
    else
        echo -e "  Version check: ${RED}FAIL (8.1+ required, found $PHP_VER_NUM)${NC}"
        ERRORS=$((ERRORS + 1))
    fi
    
    # Check required extensions
    REQUIRED_EXTS=("pdo" "pdo_mysql" "json" "openssl" "pcntl" "posix" "sockets")
    
    echo "  Extensions:"
    for ext in "${REQUIRED_EXTS[@]}"; do
        if php -m | grep -qi "^$ext$"; then
            echo -e "    - $ext: ${GREEN}OK${NC}"
        else
            echo -e "    - $ext: ${RED}MISSING${NC}"
            ERRORS=$((ERRORS + 1))
        fi
    done
else
    echo -e "  ${RED}PHP not found${NC}"
    ERRORS=$((ERRORS + 1))
fi

echo ""

# =====================================================
# Node.js Checks
# =====================================================

echo -e "${BLUE}[Node.js]${NC}"

if command -v node &> /dev/null; then
    NODE_VERSION=$(node -v)
    NODE_MAJOR=$(echo $NODE_VERSION | cut -d'.' -f1 | tr -d 'v')
    
    echo "  Version: $NODE_VERSION"
    
    if [ "$NODE_MAJOR" -ge 18 ]; then
        echo -e "  Version check: ${GREEN}OK (18+ required)${NC}"
    else
        echo -e "  Version check: ${YELLOW}WARN (18+ recommended)${NC}"
        WARNINGS=$((WARNINGS + 1))
    fi
else
    echo -e "  ${RED}Node.js not found${NC}"
    ERRORS=$((ERRORS + 1))
fi

if command -v npm &> /dev/null; then
    NPM_VERSION=$(npm -v)
    echo -e "  npm: ${GREEN}$NPM_VERSION${NC}"
else
    echo -e "  npm: ${RED}Not found${NC}"
    ERRORS=$((ERRORS + 1))
fi

echo ""

# =====================================================
# Composer Check
# =====================================================

echo -e "${BLUE}[Composer]${NC}"

if command -v composer &> /dev/null; then
    COMPOSER_VERSION=$(composer --version 2>/dev/null | head -n 1)
    echo -e "  ${GREEN}$COMPOSER_VERSION${NC}"
else
    echo -e "  ${RED}Composer not found${NC}"
    ERRORS=$((ERRORS + 1))
fi

echo ""

# =====================================================
# MySQL Checks
# =====================================================

echo -e "${BLUE}[MySQL/MariaDB]${NC}"

if command -v mysql &> /dev/null; then
    MYSQL_VERSION=$(mysql --version 2>/dev/null)
    echo -e "  Client: ${GREEN}Found${NC}"
    echo "  Version: $MYSQL_VERSION"
else
    echo -e "  Client: ${RED}Not found${NC}"
    ERRORS=$((ERRORS + 1))
fi

# Check if MySQL is running
if systemctl is-active --quiet mysql 2>/dev/null || systemctl is-active --quiet mariadb 2>/dev/null; then
    echo -e "  Server: ${GREEN}Running${NC}"
else
    echo -e "  Server: ${YELLOW}Not running or not detected${NC}"
    WARNINGS=$((WARNINGS + 1))
fi

echo ""

# =====================================================
# OpenLiteSpeed Checks
# =====================================================

echo -e "${BLUE}[OpenLiteSpeed]${NC}"

if [ -d "/usr/local/lsws" ]; then
    echo -e "  Installation: ${GREEN}Found at /usr/local/lsws${NC}"
    
    # Check version
    if [ -f "/usr/local/lsws/VERSION" ]; then
        OLS_VERSION=$(cat /usr/local/lsws/VERSION)
        echo "  Version: $OLS_VERSION"
    fi
    
    # Check if running
    if pgrep -x "litespeed" > /dev/null; then
        echo -e "  Status: ${GREEN}Running${NC}"
    else
        echo -e "  Status: ${YELLOW}Not running${NC}"
        WARNINGS=$((WARNINGS + 1))
    fi
    
    # Check lswsctrl
    if [ -x "/usr/local/lsws/bin/lswsctrl" ]; then
        echo -e "  lswsctrl: ${GREEN}Available${NC}"
    else
        echo -e "  lswsctrl: ${YELLOW}Not found${NC}"
        WARNINGS=$((WARNINGS + 1))
    fi
    
    # Check config directory
    if [ -d "/usr/local/lsws/conf" ]; then
        echo -e "  Config dir: ${GREEN}OK${NC}"
    else
        echo -e "  Config dir: ${RED}Not found${NC}"
        ERRORS=$((ERRORS + 1))
    fi
    
    # Check vhosts directory
    if [ -d "/usr/local/lsws/conf/vhosts" ]; then
        VHOST_COUNT=$(ls -1 /usr/local/lsws/conf/vhosts 2>/dev/null | wc -l)
        echo "  Existing vhosts: $VHOST_COUNT"
    fi
else
    echo -e "  ${RED}OpenLiteSpeed not found at /usr/local/lsws${NC}"
    ERRORS=$((ERRORS + 1))
fi

echo ""

# =====================================================
# Optional Services Check
# =====================================================

echo -e "${BLUE}[Optional Services]${NC}"

# Fail2ban
if command -v fail2ban-client &> /dev/null; then
    echo -e "  Fail2ban: ${GREEN}Found${NC}"
else
    echo -e "  Fail2ban: ${YELLOW}Not found (optional)${NC}"
fi

# FirewallD
if command -v firewall-cmd &> /dev/null; then
    echo -e "  FirewallD: ${GREEN}Found${NC}"
else
    echo -e "  FirewallD: ${YELLOW}Not found (optional)${NC}"
fi

# Postfix
if command -v postfix &> /dev/null; then
    echo -e "  Postfix: ${GREEN}Found${NC}"
else
    echo -e "  Postfix: ${YELLOW}Not found (optional)${NC}"
fi

# Dovecot
if command -v dovecot &> /dev/null; then
    echo -e "  Dovecot: ${GREEN}Found${NC}"
else
    echo -e "  Dovecot: ${YELLOW}Not found (optional)${NC}"
fi

# PowerDNS
if command -v pdns_control &> /dev/null; then
    echo -e "  PowerDNS: ${GREEN}Found${NC}"
else
    echo -e "  PowerDNS: ${YELLOW}Not found (optional)${NC}"
fi

echo ""

# =====================================================
# Disk Space Check
# =====================================================

echo -e "${BLUE}[Disk Space]${NC}"

echo "  Partition usage:"
df -h /opt /var /tmp 2>/dev/null | tail -n +2 | while read line; do
    MOUNT=$(echo $line | awk '{print $6}')
    USED=$(echo $line | awk '{print $5}' | tr -d '%')
    AVAIL=$(echo $line | awk '{print $4}')
    
    if [ "$USED" -ge 90 ]; then
        echo -e "    $MOUNT: ${RED}$USED% used ($AVAIL free)${NC}"
    elif [ "$USED" -ge 80 ]; then
        echo -e "    $MOUNT: ${YELLOW}$USED% used ($AVAIL free)${NC}"
    else
        echo -e "    $MOUNT: ${GREEN}$USED% used ($AVAIL free)${NC}"
    fi
done

# Check /opt specifically
OPT_AVAIL=$(df /opt 2>/dev/null | tail -1 | awk '{print $4}')
if [ -n "$OPT_AVAIL" ] && [ "$OPT_AVAIL" -lt 512000 ]; then
    echo -e "  ${YELLOW}Warning: Less than 500MB available on /opt${NC}"
    WARNINGS=$((WARNINGS + 1))
fi

echo ""

# =====================================================
# Network Check
# =====================================================

echo -e "${BLUE}[Network]${NC}"

# Check if ports are available
for PORT in 80 443 8080; do
    if ss -tlnp | grep -q ":$PORT "; then
        SERVICE=$(ss -tlnp | grep ":$PORT " | awk '{print $NF}' | head -n 1)
        echo "  Port $PORT: In use by $SERVICE"
    else
        echo -e "  Port $PORT: ${GREEN}Available${NC}"
    fi
done

echo ""

# =====================================================
# Summary
# =====================================================

echo -e "${CYAN}========================================${NC}"
echo -e "${CYAN}   Summary${NC}"
echo -e "${CYAN}========================================${NC}"
echo ""

if [ $ERRORS -eq 0 ] && [ $WARNINGS -eq 0 ]; then
    echo -e "${GREEN}All checks passed! Your system is ready for installation.${NC}"
elif [ $ERRORS -eq 0 ]; then
    echo -e "${YELLOW}$WARNINGS warning(s) found. Installation should work but review warnings above.${NC}"
else
    echo -e "${RED}$ERRORS error(s) and $WARNINGS warning(s) found.${NC}"
    echo -e "${RED}Fix the errors above before running the installer.${NC}"
fi

echo ""
echo "Next steps:"
echo "  1. Fix any errors listed above"
echo "  2. Run: ./install.sh --dry-run --domain your-domain.com"
echo "  3. Review the installation plan"
echo "  4. Run: ./install.sh --domain your-domain.com --mysql-pass yourpass"
echo ""

exit $ERRORS

