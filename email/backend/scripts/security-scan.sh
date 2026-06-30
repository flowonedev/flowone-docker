#!/bin/bash
# ============================================
# Security Dependency Scanner
# ============================================
# Scans PHP (composer audit) and Node.js (npm audit)
# dependencies across all apps and sends results
# to the Panel's security scan ingest endpoint.
#
# Setup: Add to crontab (runs daily at 3 AM):
#   0 3 * * * /var/www/vps-email/backend/scripts/security-scan.sh >> /var/log/security-scan.log 2>&1
#
# Required env vars in /var/www/vps-email/backend/.env:
#   PANEL_API_URL=https://panel.devcon1.hu/api
#   PANEL_API_KEY=<your-key>
# ============================================

set -euo pipefail

# Config
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EMAIL_APP_ROOT="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$EMAIL_APP_ROOT/.env"

# Load env
if [ -f "$ENV_FILE" ]; then
    # shellcheck source=/dev/null
    set -a
    source <(grep -v '^#' "$ENV_FILE" | grep -v '^\s*$')
    set +a
fi

PANEL_API_URL="${PANEL_API_URL:-https://panel.devcon1.hu/api}"
PANEL_API_KEY="${PANEL_API_KEY:-}"

if [ -z "$PANEL_API_KEY" ]; then
    echo "[ERROR] PANEL_API_KEY not set in $ENV_FILE"
    exit 1
fi

INGEST_URL="$PANEL_API_URL/security/scans/ingest"

echo "========================================="
echo "Security Dependency Scan - $(date)"
echo "========================================="

# Temp file for collecting scan results
RESULTS='{"scans":[]}'

# ============================================
# Helper: Parse composer audit JSON output
# ============================================
parse_composer_audit() {
    local app_name="$1"
    local audit_output="$2"
    
    local total=0 critical=0 high=0 medium=0 low=0
    local vulns_json="[]"
    
    if echo "$audit_output" | python3 -c "
import sys, json
try:
    data = json.load(sys.stdin)
    advisories = data.get('advisories', {})
    vulns = []
    counts = {'critical': 0, 'high': 0, 'medium': 0, 'low': 0}
    total = 0
    for pkg, advs in advisories.items():
        for adv in advs:
            severity = (adv.get('severity') or 'medium').lower()
            if severity in counts:
                counts[severity] += 1
            total += 1
            vulns.append({
                'package': pkg,
                'severity': severity,
                'advisory': adv.get('advisoryId', ''),
                'title': adv.get('title', ''),
                'url': adv.get('link', ''),
                'range': adv.get('affectedVersions', '')
            })
    print(json.dumps({
        'total': total,
        'critical': counts['critical'],
        'high': counts['high'],
        'medium': counts['medium'],
        'low': counts['low'],
        'vulns': vulns
    }))
except:
    print(json.dumps({'total': 0, 'critical': 0, 'high': 0, 'medium': 0, 'low': 0, 'vulns': []}))
" 2>/dev/null; then
        return 0
    fi
    echo '{"total": 0, "critical": 0, "high": 0, "medium": 0, "low": 0, "vulns": []}'
}

# ============================================
# Helper: Parse npm audit JSON output
# ============================================
parse_npm_audit() {
    local app_name="$1"
    local audit_output="$2"
    
    echo "$audit_output" | python3 -c "
import sys, json
try:
    data = json.load(sys.stdin)
    vulns_data = data.get('vulnerabilities', {})
    vulns = []
    counts = {'critical': 0, 'high': 0, 'moderate': 0, 'low': 0}
    total = 0
    for pkg, info in vulns_data.items():
        severity = (info.get('severity') or 'low').lower()
        if severity in counts:
            counts[severity] += 1
        total += 1
        via = info.get('via', [])
        title = ''
        url = ''
        if isinstance(via, list) and via:
            first = via[0]
            if isinstance(first, dict):
                title = first.get('title', '')
                url = first.get('url', '')
        vulns.append({
            'package': pkg,
            'severity': 'medium' if severity == 'moderate' else severity,
            'title': title,
            'url': url,
            'range': info.get('range', '')
        })
    print(json.dumps({
        'total': total,
        'critical': counts['critical'],
        'high': counts['high'],
        'medium': counts['moderate'],
        'low': counts['low'],
        'vulns': vulns
    }))
except Exception as e:
    print(json.dumps({'total': 0, 'critical': 0, 'high': 0, 'medium': 0, 'low': 0, 'vulns': []}), file=sys.stderr)
    print(json.dumps({'total': 0, 'critical': 0, 'high': 0, 'medium': 0, 'low': 0, 'vulns': []}))
" 2>/dev/null || echo '{"total": 0, "critical": 0, "high": 0, "medium": 0, "low": 0, "vulns": []}'
}

# ============================================
# Scan Function
# ============================================
scan_app() {
    local app_name="$1"
    local app_path="$2"
    local scan_type="$3"  # composer or npm
    
    echo "  Scanning $app_name ($scan_type) at $app_path..."
    
    local audit_output=""
    local parsed=""
    
    if [ "$scan_type" = "composer" ]; then
        if [ ! -f "$app_path/composer.lock" ]; then
            echo "    No composer.lock found, skipping."
            return
        fi
        audit_output=$(cd "$app_path" && composer audit --format=json 2>/dev/null || true)
        parsed=$(echo "$audit_output" | parse_composer_audit "$app_name" "$audit_output")
    elif [ "$scan_type" = "npm" ]; then
        if [ ! -f "$app_path/package-lock.json" ]; then
            echo "    No package-lock.json found, skipping."
            return
        fi
        audit_output=$(cd "$app_path" && npm audit --json 2>/dev/null || true)
        parsed=$(echo "$audit_output" | parse_npm_audit "$app_name" "$audit_output")
    fi
    
    local total critical high medium low vulns_json
    total=$(echo "$parsed" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['total'])" 2>/dev/null || echo "0")
    critical=$(echo "$parsed" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['critical'])" 2>/dev/null || echo "0")
    high=$(echo "$parsed" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['high'])" 2>/dev/null || echo "0")
    medium=$(echo "$parsed" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['medium'])" 2>/dev/null || echo "0")
    low=$(echo "$parsed" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['low'])" 2>/dev/null || echo "0")
    vulns_json=$(echo "$parsed" | python3 -c "import sys,json; d=json.load(sys.stdin); print(json.dumps(d['vulns']))" 2>/dev/null || echo "[]")
    
    echo "    Found: $total total ($critical critical, $high high, $medium medium, $low low)"
    
    # Build scan result JSON
    local scan_json
    scan_json=$(python3 -c "
import json
print(json.dumps({
    'source_app': '$app_name',
    'scan_type': '$scan_type',
    'vulnerabilities_found': int('$total'),
    'critical_count': int('$critical'),
    'high_count': int('$high'),
    'medium_count': int('$medium'),
    'low_count': int('$low'),
    'results': json.loads('''$vulns_json''')
}))
" 2>/dev/null)
    
    # Append to results
    RESULTS=$(echo "$RESULTS" | python3 -c "
import sys, json
data = json.load(sys.stdin)
data['scans'].append(json.loads('''$scan_json'''))
print(json.dumps(data))
" 2>/dev/null)
}

# ============================================
# Run Scans
# ============================================

echo ""
echo "--- Email App Backend (PHP) ---"
scan_app "email" "$EMAIL_APP_ROOT" "composer"

echo ""
echo "--- Email App Collab Backend (PHP) ---"
COLLAB_PHP="$(dirname "$EMAIL_APP_ROOT")/collab/backend"
if [ -d "$COLLAB_PHP" ]; then
    scan_app "collab" "$COLLAB_PHP" "composer"
else
    echo "  Collab backend not found at $COLLAB_PHP, skipping."
fi

echo ""
echo "--- Mailsync Server (Node.js) ---"
MAILSYNC_DIR="$(dirname "$EMAIL_APP_ROOT")/mailsync/server"
if [ -d "$MAILSYNC_DIR" ]; then
    scan_app "mailsync" "$MAILSYNC_DIR" "npm"
else
    echo "  Mailsync not found at $MAILSYNC_DIR, skipping."
fi

echo ""
echo "--- Collab Server (Node.js) ---"
COLLAB_NODE="/opt/collab-server"
if [ -d "$COLLAB_NODE" ]; then
    scan_app "collab" "$COLLAB_NODE" "npm"
else
    echo "  Collab server not found at $COLLAB_NODE, skipping."
fi

echo ""
echo "--- Panel API (PHP) ---"
PANEL_API="/var/www/vps-admin/api"
if [ -d "$PANEL_API" ]; then
    scan_app "panel" "$PANEL_API" "composer"
else
    echo "  Panel API not found at $PANEL_API, skipping."
fi

echo ""
echo "--- Panel Dashboard (Node.js) ---"
PANEL_DASH="/var/www/vps-admin/dashboard"
if [ -d "$PANEL_DASH" ]; then
    scan_app "panel" "$PANEL_DASH" "npm"
else
    echo "  Panel dashboard not found at $PANEL_DASH, skipping."
fi

# ============================================
# Send Results to Panel
# ============================================
echo ""
echo "--- Sending results to Panel ---"

SCAN_COUNT=$(echo "$RESULTS" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['scans']))" 2>/dev/null || echo "0")
echo "  Sending $SCAN_COUNT scan results..."

HTTP_CODE=$(curl -s -o /tmp/scan-response.json -w "%{http_code}" \
    -X POST "$INGEST_URL" \
    -H "Content-Type: application/json" \
    -H "X-Api-Key: $PANEL_API_KEY" \
    -d "$RESULTS" \
    --connect-timeout 10 \
    --max-time 30 \
    2>/dev/null || echo "000")

if [ "$HTTP_CODE" = "200" ]; then
    echo "  Successfully sent to Panel (HTTP $HTTP_CODE)"
    cat /tmp/scan-response.json 2>/dev/null && echo ""
else
    echo "  [WARNING] Failed to send to Panel (HTTP $HTTP_CODE)"
    cat /tmp/scan-response.json 2>/dev/null && echo ""
fi

rm -f /tmp/scan-response.json

echo ""
echo "Scan complete at $(date)"
echo "========================================="

