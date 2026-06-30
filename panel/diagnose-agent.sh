#!/bin/bash
#
# VPS Admin - Agent Diagnostics
# Run this on your VPS to diagnose agent issues
#

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo ""
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  VPS Admin - Agent Diagnostics${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

ERRORS=0
WARNINGS=0

# Check 1: Agent service status
echo -e "${BLUE}[1/8] Checking agent service...${NC}"
if systemctl is-active --quiet vpsadmin-agent; then
    echo -e "  ${GREEN}✓${NC} Agent is running"
    systemctl status vpsadmin-agent --no-pager -l | grep "Active:" | sed 's/^/    /'
else
    echo -e "  ${RED}✗${NC} Agent is NOT running"
    echo "    → Fix: systemctl start vpsadmin-agent"
    ERRORS=$((ERRORS + 1))
fi

# Check 2: Agent enabled on boot
echo ""
echo -e "${BLUE}[2/8] Checking if agent is enabled on boot...${NC}"
if systemctl is-enabled --quiet vpsadmin-agent 2>/dev/null; then
    echo -e "  ${GREEN}✓${NC} Agent will start on boot"
else
    echo -e "  ${YELLOW}⚠${NC} Agent not enabled on boot"
    echo "    → Fix: systemctl enable vpsadmin-agent"
    WARNINGS=$((WARNINGS + 1))
fi

# Check 3: Socket file
echo ""
echo -e "${BLUE}[3/8] Checking socket file...${NC}"
SOCKET="/opt/vps-admin/var/agent.sock"
if [ -S "$SOCKET" ]; then
    echo -e "  ${GREEN}✓${NC} Socket exists: $SOCKET"
    ls -lah "$SOCKET" | sed 's/^/    /'
else
    echo -e "  ${RED}✗${NC} Socket NOT found: $SOCKET"
    if [ -e "$SOCKET" ]; then
        echo "    → File exists but is not a socket"
        echo "    → Fix: rm $SOCKET && systemctl restart vpsadmin-agent"
    else
        echo "    → Fix: systemctl start vpsadmin-agent"
    fi
    ERRORS=$((ERRORS + 1))
fi

# Check 4: Token file
echo ""
echo -e "${BLUE}[4/8] Checking token file...${NC}"
TOKEN_FILE="/opt/vps-admin/var/agent.token"
if [ -f "$TOKEN_FILE" ]; then
    echo -e "  ${GREEN}✓${NC} Token file exists"
    ls -lah "$TOKEN_FILE" | sed 's/^/    /'
    TOKEN_LENGTH=$(wc -c < "$TOKEN_FILE" | tr -d ' ')
    echo "    Token length: $TOKEN_LENGTH characters"
    if [ "$TOKEN_LENGTH" -lt 32 ]; then
        echo -e "    ${YELLOW}⚠${NC} Token seems short, should be 64+ characters"
        WARNINGS=$((WARNINGS + 1))
    fi
else
    echo -e "  ${RED}✗${NC} Token file NOT found"
    echo "    → Fix: openssl rand -hex 32 > $TOKEN_FILE"
    echo "    → Fix: chown root:www-data $TOKEN_FILE"
    echo "    → Fix: chmod 640 $TOKEN_FILE"
    ERRORS=$((ERRORS + 1))
fi

# Check 5: PHP extensions
echo ""
echo -e "${BLUE}[5/8] Checking PHP extensions...${NC}"
REQUIRED_EXTS=("sockets" "pcntl" "posix" "json" "pdo_mysql")
MISSING_EXTS=()
for ext in "${REQUIRED_EXTS[@]}"; do
    if php -m 2>/dev/null | grep -q "^$ext$"; then
        echo -e "  ${GREEN}✓${NC} $ext"
    else
        echo -e "  ${RED}✗${NC} $ext (missing)"
        MISSING_EXTS+=("$ext")
        ERRORS=$((ERRORS + 1))
    fi
done

if [ ${#MISSING_EXTS[@]} -gt 0 ]; then
    echo ""
    echo "  → Install missing extensions:"
    echo "    apt install $(for ext in "${MISSING_EXTS[@]}"; do echo -n "php-$ext "; done)"
fi

# Check 6: MySQL connectivity
echo ""
echo -e "${BLUE}[6/8] Checking MySQL connectivity...${NC}"
if [ -f "/root/.my.cnf" ]; then
    echo -e "  ${GREEN}✓${NC} MySQL config found at /root/.my.cnf"
    if mysql -e "SELECT 1" &>/dev/null; then
        echo -e "  ${GREEN}✓${NC} MySQL connection successful"
        MYSQL_VERSION=$(mysql -V | awk '{print $5}' | sed 's/,$//')
        echo "    Version: $MYSQL_VERSION"
    else
        echo -e "  ${RED}✗${NC} Cannot connect to MySQL"
        echo "    → Check /root/.my.cnf password"
        ERRORS=$((ERRORS + 1))
    fi
else
    echo -e "  ${YELLOW}⚠${NC} /root/.my.cnf not found"
    echo "    Agent may not be able to access MySQL"
    WARNINGS=$((WARNINGS + 1))
fi

# Check 7: Permissions
echo ""
echo -e "${BLUE}[7/8] Checking permissions...${NC}"
VAR_DIR="/opt/vps-admin/var"
if [ -d "$VAR_DIR" ]; then
    VAR_OWNER=$(stat -c "%U:%G" "$VAR_DIR")
    VAR_PERMS=$(stat -c "%a" "$VAR_DIR")
    
    if [ "$VAR_OWNER" = "root:www-data" ] && [ "$VAR_PERMS" = "750" ]; then
        echo -e "  ${GREEN}✓${NC} Permissions correct: $VAR_OWNER ($VAR_PERMS)"
    else
        echo -e "  ${YELLOW}⚠${NC} Permissions: $VAR_OWNER ($VAR_PERMS)"
        echo "    Expected: root:www-data (750)"
        echo "    → Fix: chown root:www-data $VAR_DIR"
        echo "    → Fix: chmod 750 $VAR_DIR"
        WARNINGS=$((WARNINGS + 1))
    fi
else
    echo -e "  ${RED}✗${NC} Directory not found: $VAR_DIR"
    ERRORS=$((ERRORS + 1))
fi

# Check 8: Test agent communication
echo ""
echo -e "${BLUE}[8/8] Testing agent communication...${NC}"
if [ -f "/opt/vps-admin/api/test-agent.php" ]; then
    if php /opt/vps-admin/api/test-agent.php >/dev/null 2>&1; then
        echo -e "  ${GREEN}✓${NC} Agent communication test PASSED"
    else
        echo -e "  ${RED}✗${NC} Agent communication test FAILED"
        echo "    → Run for details: php /opt/vps-admin/api/test-agent.php"
        ERRORS=$((ERRORS + 1))
    fi
else
    echo -e "  ${YELLOW}⚠${NC} Test script not found, skipping"
fi

# Recent logs
echo ""
echo -e "${BLUE}Recent agent logs:${NC}"
if journalctl -u vpsadmin-agent --no-pager -n 5 2>/dev/null | tail -n +2 | grep -q .; then
    journalctl -u vpsadmin-agent --no-pager -n 5 | tail -n +2 | sed 's/^/  /'
else
    echo "  (No logs found)"
fi

# Summary
echo ""
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  Summary${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

if [ $ERRORS -eq 0 ] && [ $WARNINGS -eq 0 ]; then
    echo -e "${GREEN}✓ All checks passed!${NC}"
    echo "  Your agent should be working correctly."
elif [ $ERRORS -eq 0 ]; then
    echo -e "${YELLOW}⚠ Warnings: $WARNINGS${NC}"
    echo "  Agent is working but has minor issues."
else
    echo -e "${RED}✗ Errors: $ERRORS, Warnings: $WARNINGS${NC}"
    echo "  Please fix the errors above."
fi

echo ""
echo "Useful commands:"
echo "  View logs:     journalctl -u vpsadmin-agent -f"
echo "  Restart agent: systemctl restart vpsadmin-agent"
echo "  Check status:  systemctl status vpsadmin-agent"
echo "  Test API:      curl http://localhost:8080/api/services"
echo ""

exit $ERRORS

