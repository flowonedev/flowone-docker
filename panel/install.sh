#!/bin/bash
#
# VPS Admin Panel Installation Script
#
# Safe installation with:
# - Dry-run mode (--dry-run)
# - Automatic backups before changes
# - Rollback capability
# - Pre-flight validation
#
# Usage:
#   ./install.sh --dry-run                    # Show what would be done
#   ./install.sh --domain panel.example.com   # Actual installation
#   ./install.sh --rollback                   # Rollback last installation
#

set -e

# =====================================================
# Configuration
# =====================================================
INSTALL_PATH="/opt/vps-admin"
WEB_PATH="/var/www/vps-admin"
BACKUP_PATH="/opt/vps-admin-backups"
OLS_CONF_PATH="/usr/local/lsws/conf"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# Command line options
DRY_RUN=false
ROLLBACK=false
DOMAIN=""
MYSQL_USER="root"
MYSQL_PASS=""
SKIP_DB=false
SKIP_AGENT=false
SKIP_DASHBOARD=false

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# =====================================================
# Helper Functions
# =====================================================

log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[OK]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

log_dry() {
    echo -e "${CYAN}[DRY-RUN]${NC} Would: $1"
}

# Execute or simulate based on dry-run mode
safe_exec() {
    local description="$1"
    shift
    
    if [ "$DRY_RUN" = true ]; then
        log_dry "$description"
        return 0
    fi
    
    log_info "$description"
    "$@"
}

# Create backup of a file or directory
backup_item() {
    local item="$1"
    local backup_dir="$BACKUP_PATH/$TIMESTAMP"
    
    if [ ! -e "$item" ]; then
        return 0
    fi
    
    if [ "$DRY_RUN" = true ]; then
        log_dry "Backup $item -> $backup_dir/"
        return 0
    fi
    
    mkdir -p "$backup_dir"
    
    if [ -d "$item" ]; then
        cp -r "$item" "$backup_dir/"
    else
        cp "$item" "$backup_dir/"
    fi
    
    log_success "Backed up: $item"
}

# Check if a command exists
check_command() {
    command -v "$1" &> /dev/null
}

# =====================================================
# Pre-flight Checks
# =====================================================

preflight_checks() {
    echo ""
    echo -e "${GREEN}========================================${NC}"
    echo -e "${GREEN}   Pre-flight Checks${NC}"
    echo -e "${GREEN}========================================${NC}"
    echo ""
    
    local errors=0
    local warnings=0
    
    # Check root
    if [ "$EUID" -ne 0 ]; then
        log_error "Must run as root"
        errors=$((errors + 1))
    else
        log_success "Running as root"
    fi
    
    # Check PHP
    if check_command php; then
        PHP_VERSION=$(php -v | head -n 1 | cut -d ' ' -f 2 | cut -d '.' -f 1,2)
        if [ "$(echo "$PHP_VERSION >= 8.1" | bc)" -eq 1 ]; then
            log_success "PHP $PHP_VERSION found"
        else
            log_error "PHP 8.1+ required, found $PHP_VERSION"
            errors=$((errors + 1))
        fi
    else
        log_error "PHP not found"
        errors=$((errors + 1))
    fi
    
    # Check Node.js (only if building dashboard)
    if [ "$SKIP_DASHBOARD" = false ]; then
        if check_command node; then
            NODE_VERSION=$(node -v | cut -d 'v' -f 2 | cut -d '.' -f 1)
            if [ "$NODE_VERSION" -ge 18 ]; then
                log_success "Node.js v$NODE_VERSION found"
            else
                log_warn "Node.js 18+ recommended, found v$NODE_VERSION"
                warnings=$((warnings + 1))
            fi
        else
            log_error "Node.js not found"
            errors=$((errors + 1))
        fi
        
        # Check npm
        if check_command npm; then
            log_success "npm found"
        else
            log_error "npm not found"
            errors=$((errors + 1))
        fi
    else
        log_info "Skipping Node.js check (--skip-dashboard)"
    fi
    
    # Check Composer
    if check_command composer; then
        log_success "Composer found"
    else
        log_error "Composer not found"
        errors=$((errors + 1))
    fi
    
    # Check MySQL
    if check_command mysql; then
        log_success "MySQL client found"
    else
        log_error "MySQL client not found"
        errors=$((errors + 1))
    fi
    
    # Check OpenLiteSpeed
    if [ -d "/usr/local/lsws" ]; then
        log_success "OpenLiteSpeed found at /usr/local/lsws"
    else
        log_error "OpenLiteSpeed not found at /usr/local/lsws"
        errors=$((errors + 1))
    fi
    
    # Check if lswsctrl exists
    if [ -x "/usr/local/lsws/bin/lswsctrl" ]; then
        log_success "lswsctrl found"
    else
        log_warn "lswsctrl not found - OLS reload may fail"
        warnings=$((warnings + 1))
    fi
    
    # Check existing installation
    if [ -d "$INSTALL_PATH" ]; then
        log_warn "Existing installation found at $INSTALL_PATH"
        warnings=$((warnings + 1))
    fi
    
    # Check if domain vhost already exists
    if [ -n "$DOMAIN" ] && [ -d "$OLS_CONF_PATH/vhosts/$DOMAIN" ]; then
        log_warn "Vhost for $DOMAIN already exists - will be backed up"
        warnings=$((warnings + 1))
    fi
    
    # Check disk space (need at least 500MB)
    AVAILABLE_SPACE=$(df /opt 2>/dev/null | tail -1 | awk '{print $4}')
    if [ -n "$AVAILABLE_SPACE" ] && [ "$AVAILABLE_SPACE" -lt 512000 ]; then
        log_warn "Low disk space on /opt ($(($AVAILABLE_SPACE / 1024))MB available)"
        warnings=$((warnings + 1))
    else
        log_success "Sufficient disk space available"
    fi
    
    echo ""
    echo "========================================="
    echo "Pre-flight Summary:"
    echo "  Errors:   $errors"
    echo "  Warnings: $warnings"
    echo "========================================="
    echo ""
    
    if [ $errors -gt 0 ]; then
        log_error "Pre-flight checks failed. Fix errors before continuing."
        return 1
    fi
    
    if [ $warnings -gt 0 ]; then
        log_warn "Warnings detected. Review before continuing."
        if [ "$DRY_RUN" = false ]; then
            read -p "Continue anyway? (y/N): " confirm
            if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
                log_info "Installation cancelled."
                exit 0
            fi
        fi
    fi
    
    return 0
}

# =====================================================
# Show Installation Plan (Dry Run)
# =====================================================

show_installation_plan() {
    echo ""
    echo -e "${CYAN}========================================${NC}"
    echo -e "${CYAN}   Installation Plan (Dry Run)${NC}"
    echo -e "${CYAN}========================================${NC}"
    echo ""
    
    echo "The following actions will be performed:"
    echo ""
    
    echo "1. BACKUPS"
    echo "   - Backup directory: $BACKUP_PATH/$TIMESTAMP/"
    if [ -d "$INSTALL_PATH" ]; then
        echo "   - Will backup: $INSTALL_PATH"
    fi
    if [ -d "$WEB_PATH" ]; then
        echo "   - Will backup: $WEB_PATH"
    fi
    if [ -n "$DOMAIN" ] && [ -d "$OLS_CONF_PATH/vhosts/$DOMAIN" ]; then
        echo "   - Will backup: $OLS_CONF_PATH/vhosts/$DOMAIN"
    fi
    echo ""
    
    echo "2. DIRECTORIES"
    echo "   - Create: $INSTALL_PATH/"
    echo "   - Create: $INSTALL_PATH/agent/"
    echo "   - Create: $INSTALL_PATH/api/"
    echo "   - Create: $INSTALL_PATH/var/"
    echo "   - Create: $INSTALL_PATH/backups/"
    echo "   - Create: $INSTALL_PATH/logs/"
    echo "   - Create: $WEB_PATH/"
    echo ""
    
    echo "3. AGENT INSTALLATION"
    echo "   - Copy agent files to $INSTALL_PATH/agent/"
    echo "   - Generate auth token at $INSTALL_PATH/var/agent.token"
    echo "   - Create systemd service: vpsadmin-agent"
    echo "   - Set permissions (root:www-data)"
    echo ""
    
    echo "4. API INSTALLATION"
    echo "   - Copy API files to $INSTALL_PATH/api/"
    echo "   - Run composer install"
    echo "   - Set permissions (www-data:www-data)"
    echo ""
    
    echo "5. DASHBOARD BUILD"
    echo "   - Run npm install in dashboard/"
    echo "   - Run npm run build"
    echo "   - Copy dist/ to $WEB_PATH/"
    echo ""
    
    if [ "$SKIP_DB" = false ]; then
        echo "6. DATABASE"
        echo "   - Create database: vpsadmin"
        echo "   - Create tables: admin_users, sessions, audit_logs"
        echo "   - Create database user: vpsadmin@localhost"
        echo "   - Create admin user: admin"
        echo ""
    fi
    
    if [ -n "$DOMAIN" ]; then
        echo "7. OPENLITESPEED VHOST"
        echo "   - Create vhost config at: $OLS_CONF_PATH/vhosts/$DOMAIN/"
        echo "   - Document root: $WEB_PATH"
        echo "   - Reload OpenLiteSpeed"
        echo ""
    fi
    
    echo "8. SERVICES"
    echo "   - Enable and start: vpsadmin-agent"
    echo ""
    
    echo -e "${YELLOW}To proceed with actual installation, run without --dry-run${NC}"
    echo ""
}

# =====================================================
# Installation Steps
# =====================================================

create_backup() {
    echo ""
    log_info "Creating backups..."
    
    mkdir -p "$BACKUP_PATH/$TIMESTAMP"
    
    # Backup existing installation
    if [ -d "$INSTALL_PATH" ]; then
        backup_item "$INSTALL_PATH"
    fi
    
    # Backup existing web files
    if [ -d "$WEB_PATH" ]; then
        backup_item "$WEB_PATH"
    fi
    
    # Backup existing OLS vhost
    if [ -n "$DOMAIN" ] && [ -d "$OLS_CONF_PATH/vhosts/$DOMAIN" ]; then
        backup_item "$OLS_CONF_PATH/vhosts/$DOMAIN"
    fi
    
    # Save backup manifest
    if [ "$DRY_RUN" = false ]; then
        cat > "$BACKUP_PATH/$TIMESTAMP/manifest.json" << EOF
{
    "timestamp": "$TIMESTAMP",
    "domain": "$DOMAIN",
    "install_path": "$INSTALL_PATH",
    "web_path": "$WEB_PATH",
    "created": "$(date -Iseconds)"
}
EOF
        log_success "Backup manifest created"
    fi
    
    log_success "Backups complete"
}

create_directories() {
    echo ""
    log_info "Creating directories..."
    
    safe_exec "Create $INSTALL_PATH" mkdir -p "$INSTALL_PATH"
    safe_exec "Create agent directory" mkdir -p "$INSTALL_PATH/agent"
    safe_exec "Create API directory" mkdir -p "$INSTALL_PATH/api"
    safe_exec "Create var directory" mkdir -p "$INSTALL_PATH/var"
    safe_exec "Create backups directory" mkdir -p "$INSTALL_PATH/backups"
    safe_exec "Create logs directory" mkdir -p "$INSTALL_PATH/logs"
    safe_exec "Create backup subdirectories" mkdir -p "$INSTALL_PATH/backups"/{configs,databases,deleted_vhosts,deleted_sites,deleted_mail}
    safe_exec "Create web directory" mkdir -p "$WEB_PATH"
    
    log_success "Directories created"
}

install_agent() {
    echo ""
    log_info "Installing agent..."
    
    # Get script directory
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    
    if [ ! -d "$SCRIPT_DIR/agent" ]; then
        log_error "Agent source not found at $SCRIPT_DIR/agent"
        return 1
    fi
    
    safe_exec "Copy agent files" cp -r "$SCRIPT_DIR/agent/"* "$INSTALL_PATH/agent/"
    
    # Generate auth token
    if [ "$DRY_RUN" = false ]; then
        if [ ! -f "$INSTALL_PATH/var/agent.token" ]; then
            openssl rand -hex 32 > "$INSTALL_PATH/var/agent.token"
            chmod 640 "$INSTALL_PATH/var/agent.token"
            log_success "Generated auth token"
        else
            log_info "Auth token already exists, keeping it"
        fi
    else
        log_dry "Generate auth token at $INSTALL_PATH/var/agent.token"
    fi
    
    # Set permissions
    safe_exec "Set agent ownership" chown -R root:root "$INSTALL_PATH/agent"
    safe_exec "Set agent permissions" chmod -R 750 "$INSTALL_PATH/agent"
    safe_exec "Set var ownership" chown root:www-data "$INSTALL_PATH/var"
    safe_exec "Set var permissions" chmod 750 "$INSTALL_PATH/var"
    
    if [ -f "$INSTALL_PATH/var/agent.token" ]; then
        safe_exec "Set token ownership" chown root:www-data "$INSTALL_PATH/var/agent.token"
    fi
    
    safe_exec "Set backups ownership" chown root:www-data "$INSTALL_PATH/backups"
    safe_exec "Set backups permissions" chmod 750 "$INSTALL_PATH/backups"
    safe_exec "Set logs ownership" chown root:www-data "$INSTALL_PATH/logs"
    safe_exec "Set logs permissions" chmod 750 "$INSTALL_PATH/logs"
    
    # Create systemd service
    if [ "$DRY_RUN" = false ]; then
        cat > /etc/systemd/system/vpsadmin-agent.service << 'EOF'
[Unit]
Description=VPS Admin Agent
After=network.target

[Service]
Type=forking
ExecStart=/usr/bin/php /opt/vps-admin/agent/agent.php
ExecReload=/bin/kill -HUP $MAINPID
PIDFile=/opt/vps-admin/var/agent.pid
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
        systemctl daemon-reload
        log_success "Created systemd service"
    else
        log_dry "Create systemd service at /etc/systemd/system/vpsadmin-agent.service"
    fi
    
    log_success "Agent installed"
}

install_api() {
    echo ""
    log_info "Installing API..."
    
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    
    if [ ! -d "$SCRIPT_DIR/api" ]; then
        log_error "API source not found at $SCRIPT_DIR/api"
        return 1
    fi
    
    safe_exec "Copy API files" cp -r "$SCRIPT_DIR/api/"* "$INSTALL_PATH/api/"
    
    # Install composer dependencies
    if [ "$DRY_RUN" = false ]; then
        cd "$INSTALL_PATH/api"
        if [ -f "composer.json" ]; then
            composer install --no-dev --optimize-autoloader --no-interaction
            log_success "Composer dependencies installed"
        fi
    else
        log_dry "Run composer install in $INSTALL_PATH/api"
    fi
    
    # Set permissions
    safe_exec "Set API ownership" chown -R www-data:www-data "$INSTALL_PATH/api"
    safe_exec "Set API permissions" chmod -R 750 "$INSTALL_PATH/api"
    
    log_success "API installed"
}

build_dashboard() {
    echo ""
    log_info "Building dashboard..."
    
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    
    if [ ! -d "$SCRIPT_DIR/dashboard" ]; then
        log_error "Dashboard source not found at $SCRIPT_DIR/dashboard"
        return 1
    fi
    
    if [ "$DRY_RUN" = false ]; then
        cd "$SCRIPT_DIR/dashboard"
        npm install --silent
        npm run build
        
        # Copy built files
        cp -r dist/* "$WEB_PATH/"
        log_success "Dashboard built and deployed"
    else
        log_dry "Run npm install in $SCRIPT_DIR/dashboard"
        log_dry "Run npm run build"
        log_dry "Copy dist/ to $WEB_PATH/"
    fi
    
    # Set permissions
    safe_exec "Set web ownership" chown -R www-data:www-data "$WEB_PATH"
    safe_exec "Set web permissions" chmod -R 755 "$WEB_PATH"
    
    log_success "Dashboard installed"
}

setup_database() {
    echo ""
    log_info "Setting up database..."
    
    if [ "$SKIP_DB" = true ]; then
        log_info "Skipping database setup (--skip-db)"
        return 0
    fi
    
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    
    if [ "$DRY_RUN" = false ]; then
        if [ -f "$SCRIPT_DIR/database/install.php" ]; then
            php "$SCRIPT_DIR/database/install.php" --password="$MYSQL_PASS" --user="$MYSQL_USER"
        else
            log_warn "Database installer not found, skipping"
        fi
    else
        log_dry "Run database installation script"
        log_dry "Create database: vpsadmin"
        log_dry "Create tables: admin_users, sessions, audit_logs"
        log_dry "Create admin user"
    fi
    
    log_success "Database setup complete"
}

configure_ols_vhost() {
    echo ""
    log_info "Configuring OpenLiteSpeed vhost..."
    
    if [ -z "$DOMAIN" ]; then
        log_info "No domain specified, skipping vhost configuration"
        return 0
    fi
    
    VHOST_PATH="$OLS_CONF_PATH/vhosts/$DOMAIN"
    
    if [ "$DRY_RUN" = false ]; then
        mkdir -p "$VHOST_PATH"
        
        cat > "$VHOST_PATH/vhconf.conf" << EOF
docRoot                   $WEB_PATH
vhDomain                  $DOMAIN
enableGzip                1
enableBr                  1

index  {
  useServer               0
  indexFiles              index.html
}

# API Proxy - adjust port if needed
context /api {
  type                    proxy
  handler                 127.0.0.1:8080
  addDefaultCharset       off
}

rewrite  {
  enable                  1
  rules                   <<<END_RULES
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ /index.html [L]
END_RULES
}
EOF
        log_success "Vhost config created"
        
        # Check if vhost is in main config
        MAIN_CONF="$OLS_CONF_PATH/httpd_config.conf"
        if ! grep -q "virtualhost $DOMAIN" "$MAIN_CONF" 2>/dev/null; then
            log_warn "Vhost not found in main config. You may need to add it manually."
            echo ""
            echo "Add this to $MAIN_CONF:"
            echo "----------------------------------------"
            echo "virtualhost $DOMAIN {"
            echo "  vhRoot                  $VHOST_PATH"
            echo "  configFile              \$VH_ROOT/vhconf.conf"
            echo "  allowSymbolLink         1"
            echo "  enableScript            1"
            echo "  restrained              0"
            echo "}"
            echo "----------------------------------------"
        fi
        
        # Reload OLS
        if [ -x "/usr/local/lsws/bin/lswsctrl" ]; then
            /usr/local/lsws/bin/lswsctrl reload
            log_success "OpenLiteSpeed reloaded"
        else
            log_warn "Could not reload OLS - please restart manually"
        fi
    else
        log_dry "Create vhost config at $VHOST_PATH/vhconf.conf"
        log_dry "Reload OpenLiteSpeed"
    fi
    
    log_success "Vhost configured"
}

start_services() {
    echo ""
    log_info "Starting services..."
    
    if [ "$DRY_RUN" = false ]; then
        systemctl enable vpsadmin-agent
        systemctl start vpsadmin-agent
        
        if systemctl is-active --quiet vpsadmin-agent; then
            log_success "Agent service started"
        else
            log_error "Agent service failed to start"
            log_info "Check logs: journalctl -u vpsadmin-agent"
        fi
    else
        log_dry "Enable and start vpsadmin-agent service"
    fi
}

# =====================================================
# Rollback Function
# =====================================================

do_rollback() {
    echo ""
    echo -e "${YELLOW}========================================${NC}"
    echo -e "${YELLOW}   Rollback${NC}"
    echo -e "${YELLOW}========================================${NC}"
    echo ""
    
    if [ ! -d "$BACKUP_PATH" ]; then
        log_error "No backups found at $BACKUP_PATH"
        exit 1
    fi
    
    # Find latest backup
    LATEST_BACKUP=$(ls -1t "$BACKUP_PATH" 2>/dev/null | head -n 1)
    
    if [ -z "$LATEST_BACKUP" ]; then
        log_error "No backups found"
        exit 1
    fi
    
    BACKUP_DIR="$BACKUP_PATH/$LATEST_BACKUP"
    
    echo "Latest backup: $LATEST_BACKUP"
    echo "Backup contents:"
    ls -la "$BACKUP_DIR"
    echo ""
    
    read -p "Rollback to this backup? (y/N): " confirm
    if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
        log_info "Rollback cancelled"
        exit 0
    fi
    
    # Stop services
    log_info "Stopping services..."
    systemctl stop vpsadmin-agent 2>/dev/null || true
    
    # Restore files
    if [ -d "$BACKUP_DIR/vps-admin" ]; then
        log_info "Restoring $INSTALL_PATH..."
        rm -rf "$INSTALL_PATH"
        cp -r "$BACKUP_DIR/vps-admin" "$INSTALL_PATH"
    fi
    
    if [ -d "$BACKUP_DIR/vps-admin-web" ]; then
        log_info "Restoring $WEB_PATH..."
        rm -rf "$WEB_PATH"
        cp -r "$BACKUP_DIR/vps-admin-web" "$WEB_PATH"
    fi
    
    # Restore vhost if backed up
    for vhost_backup in "$BACKUP_DIR"/*.vhost.backup; do
        if [ -f "$vhost_backup" ]; then
            VHOST_NAME=$(basename "$vhost_backup" .vhost.backup)
            log_info "Restoring vhost: $VHOST_NAME"
            cp -r "$vhost_backup" "$OLS_CONF_PATH/vhosts/$VHOST_NAME"
        fi
    done
    
    # Reload OLS
    if [ -x "/usr/local/lsws/bin/lswsctrl" ]; then
        /usr/local/lsws/bin/lswsctrl reload
    fi
    
    log_success "Rollback complete"
}

# =====================================================
# Parse Arguments
# =====================================================

while [[ "$#" -gt 0 ]]; do
    case $1 in
        --dry-run)
            DRY_RUN=true
            ;;
        --rollback)
            ROLLBACK=true
            ;;
        --domain)
            DOMAIN="$2"
            shift
            ;;
        --mysql-user)
            MYSQL_USER="$2"
            shift
            ;;
        --mysql-pass)
            MYSQL_PASS="$2"
            shift
            ;;
        --skip-db)
            SKIP_DB=true
            ;;
        --skip-agent)
            SKIP_AGENT=true
            ;;
        --skip-dashboard)
            SKIP_DASHBOARD=true
            ;;
        --help|-h)
            echo "VPS Admin Panel Installer"
            echo ""
            echo "Usage: $0 [options]"
            echo ""
            echo "Options:"
            echo "  --dry-run         Show what would be done without making changes"
            echo "  --rollback        Rollback to the last backup"
            echo "  --domain NAME     Panel domain (e.g., panel.example.com)"
            echo "  --mysql-user USER MySQL admin username (default: root)"
            echo "  --mysql-pass PASS MySQL admin password"
            echo "  --skip-db         Skip database setup"
            echo "  --skip-agent      Skip agent installation"
            echo "  --skip-dashboard  Skip dashboard build"
            echo "  --help, -h        Show this help"
            echo ""
            echo "Examples:"
            echo "  $0 --dry-run --domain panel.example.com"
            echo "  $0 --domain panel.example.com --mysql-pass secret"
            echo "  $0 --rollback"
            exit 0
            ;;
        *)
            log_error "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
    shift
done

# =====================================================
# Main
# =====================================================

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}   VPS Admin Panel Installer${NC}"
if [ "$DRY_RUN" = true ]; then
    echo -e "${CYAN}   (DRY RUN MODE)${NC}"
fi
echo -e "${GREEN}========================================${NC}"
echo ""

# Handle rollback
if [ "$ROLLBACK" = true ]; then
    do_rollback
    exit 0
fi

# Interactive prompts if needed
if [ -z "$DOMAIN" ] && [ "$DRY_RUN" = false ]; then
    echo -n "Enter panel domain (e.g., panel.example.com): "
    read DOMAIN
fi

if [ -z "$MYSQL_PASS" ] && [ "$SKIP_DB" = false ] && [ "$DRY_RUN" = false ]; then
    echo -n "Enter MySQL root password: "
    read -s MYSQL_PASS
    echo ""
fi

echo ""
echo "Configuration:"
echo "  Domain:       ${DOMAIN:-<not set>}"
echo "  Install path: $INSTALL_PATH"
echo "  Web path:     $WEB_PATH"
echo "  Dry run:      $DRY_RUN"
echo ""

# Run pre-flight checks
preflight_checks

# Show plan for dry run
if [ "$DRY_RUN" = true ]; then
    show_installation_plan
    exit 0
fi

# Actual installation
echo ""
echo -e "${GREEN}Starting installation...${NC}"
echo ""

create_backup

create_directories

if [ "$SKIP_AGENT" = false ]; then
    install_agent
fi

install_api

if [ "$SKIP_DASHBOARD" = false ]; then
    build_dashboard
fi

if [ "$SKIP_DB" = false ]; then
    setup_database
fi

configure_ols_vhost

start_services

# Final summary
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}   Installation Complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Panel URL: https://$DOMAIN"
echo "Default login: admin / admin"
echo ""
echo "Backup location: $BACKUP_PATH/$TIMESTAMP/"
echo ""
echo -e "${YELLOW}IMPORTANT:${NC}"
echo "1. Change the default password immediately!"
echo "2. Update $INSTALL_PATH/api/config.local.php with database credentials"
echo "3. Issue SSL certificate for $DOMAIN"
echo ""
echo "Commands:"
echo "  Check agent:   systemctl status vpsadmin-agent"
echo "  View logs:     journalctl -u vpsadmin-agent -f"
echo "  Rollback:      $0 --rollback"
echo ""
