#!/bin/bash
# =============================================================
# FlowOne Test Suite - Quick Reference
# =============================================================
# Upload to server: /var/www/vps-email/backend/tests/run-tests.sh
# Make executable:  chmod +x /var/www/vps-email/backend/tests/run-tests.sh
# Run all:          bash /var/www/vps-email/backend/tests/run-tests.sh
# =============================================================

PHP="/usr/local/lsws/lsphp83/bin/php"
DIR="/var/www/vps-email/backend/tests"
EMAIL="robert@pixelranger.hu"
PASS='d3Logir6Siege1985//'

echo ""
echo "============================================="
echo "  FlowOne - Full Test Suite"
echo "============================================="
echo ""

# ── Email System ──────────────────────────────────────────────

echo ">>> Email System Test (full)"
$PHP $DIR/email-system-test.php --email=$EMAIL --password="$PASS" --verbose
echo ""

# ── Drive System ──────────────────────────────────────────────

echo ">>> Drive System Test (full)"
$PHP $DIR/drive-system-test.php --email=$EMAIL --password="$PASS" --verbose
echo ""

# ── Mailbox Operations ────────────────────────────────────────

echo ">>> Mailbox Operations Test (full)"
$PHP $DIR/mailbox-operations-test.php --email=$EMAIL --password="$PASS" --verbose
echo ""

# ── Email Tracking ────────────────────────────────────────────

echo ">>> Email Tracking Test (full)"
$PHP $DIR/email-tracking-test.php --email=$EMAIL --verbose
echo ""

# ── Calendar ──────────────────────────────────────────────────

echo ">>> Calendar Test (full)"
$PHP $DIR/calendar-test.php --email=$EMAIL --verbose
echo ""

# ── Moodboard ─────────────────────────────────────────────────

echo ">>> Moodboard Test (full)"
$PHP $DIR/moodboard-test.php --email=$EMAIL --verbose
echo ""

# ── Chat System ──────────────────────────────────────────────

echo ">>> Chat System Test (full)"
$PHP $DIR/chat-system-test.php --email=$EMAIL --verbose
echo ""

echo "============================================="
echo "  All test suites finished."
echo "============================================="


# =============================================================
# INDIVIDUAL COMMANDS (copy-paste as needed)
# =============================================================
#
# ── Email System ──────────────────────────────────────────────
#
# Full:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/email-system-test.php --email=robert@pixelranger.hu --password='d3Logir6Siege1985//' --verbose
#
# Skip send (no actual email delivery):
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/email-system-test.php --email=robert@pixelranger.hu --password='d3Logir6Siege1985//' --skip-send --verbose
#
# With external recipient:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/email-system-test.php --email=robert@pixelranger.hu --password='d3Logir6Siege1985//' --to=feketeroberto@gmail.com --verbose
#
#
# ── Drive System ──────────────────────────────────────────────
#
# Full:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-system-test.php --email=robert@pixelranger.hu --password='d3Logir6Siege1985//' --verbose
#
# Smoke:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-system-test.php --email=robert@pixelranger.hu --password='d3Logir6Siege1985//' --smoke --verbose
#
# Selective groups:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/drive-system-test.php --email=robert@pixelranger.hu --password='d3Logir6Siege1985//' --only=share,cleanup,expiry --verbose
#
#
# ── Mailbox Operations ────────────────────────────────────────
#
# Full:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/mailbox-operations-test.php --email=robert@pixelranger.hu --password='d3Logir6Siege1985//' --verbose
#
# Smoke:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/mailbox-operations-test.php --email=robert@pixelranger.hu --password='d3Logir6Siege1985//' --smoke --verbose
#
# Selective groups:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/mailbox-operations-test.php --email=robert@pixelranger.hu --password='d3Logir6Siege1985//' --only=conv,label,reaction --verbose
#
#
# ── Email Tracking ────────────────────────────────────────────
#
# Full:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/email-tracking-test.php --email=robert@pixelranger.hu --verbose
#
# Selective groups:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/email-tracking-test.php --email=robert@pixelranger.hu --only=create,record --verbose
#
# Smoke:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/email-tracking-test.php --email=robert@pixelranger.hu --smoke --verbose
#
#
# ── Calendar ──────────────────────────────────────────────────
#
# Full:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/calendar-test.php --email=robert@pixelranger.hu --verbose
#
# Selective groups:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/calendar-test.php --email=robert@pixelranger.hu --only=recurrence,invite --verbose
#
# Smoke:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/calendar-test.php --email=robert@pixelranger.hu --smoke --verbose
#
#
# ── Moodboard ─────────────────────────────────────────────────
#
# Full:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/moodboard-test.php --email=robert@pixelranger.hu --verbose
#
# Selective groups:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/moodboard-test.php --email=robert@pixelranger.hu --only=item,batch,style --verbose
#
# Smoke:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/moodboard-test.php --email=robert@pixelranger.hu --smoke --verbose
#
#
# ── Chat System ──────────────────────────────────────────────
#
# Full:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/chat-system-test.php --email=robert@pixelranger.hu --verbose
#
# Selective groups:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/chat-system-test.php --email=robert@pixelranger.hu --only=dm,message,reaction --verbose
#
# Smoke:
#   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/chat-system-test.php --email=robert@pixelranger.hu --smoke --verbose
#
# =============================================================
