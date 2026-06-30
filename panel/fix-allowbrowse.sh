#!/bin/bash
#
# fix-allowbrowse.sh
# Finds and fixes allowBrowse 1 -> 0 in all OpenLiteSpeed vhost configs
#
# Usage:
#   ./fix-allowbrowse.sh          # Dry run (shows what would change)
#   ./fix-allowbrowse.sh --apply  # Actually apply changes
#

VHOST_DIR="/usr/local/lsws/conf/vhosts"
DRY_RUN=true
BACKUP_SUFFIX=".bak.$(date +%Y%m%d_%H%M%S)"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Parse arguments
if [[ "$1" == "--apply" ]]; then
    DRY_RUN=false
fi

echo ""
echo "=========================================="
echo "  OpenLiteSpeed allowBrowse Security Fix"
echo "=========================================="
echo ""

if $DRY_RUN; then
    echo -e "${YELLOW}DRY RUN MODE${NC} - No changes will be made"
    echo "Run with --apply to make actual changes"
else
    echo -e "${RED}APPLY MODE${NC} - Changes will be made!"
    echo "Backups will be created with suffix: $BACKUP_SUFFIX"
fi
echo ""

# Check if vhost directory exists
if [[ ! -d "$VHOST_DIR" ]]; then
    echo -e "${RED}Error:${NC} Vhost directory not found: $VHOST_DIR"
    exit 1
fi

# Find all vhost.conf files
VHOST_FILES=$(find "$VHOST_DIR" -name "vhost.conf" -type f 2>/dev/null)

if [[ -z "$VHOST_FILES" ]]; then
    echo -e "${YELLOW}No vhost.conf files found in $VHOST_DIR${NC}"
    exit 0
fi

# Count files and occurrences
TOTAL_FILES=0
AFFECTED_FILES=0
TOTAL_OCCURRENCES=0

echo "Scanning vhost configurations..."
echo ""

# Process each vhost.conf
while IFS= read -r vhost_file; do
    TOTAL_FILES=$((TOTAL_FILES + 1))
    vhost_name=$(basename $(dirname "$vhost_file"))
    
    # Count occurrences of allowBrowse 1
    occurrences=$(grep -c "allowBrowse[[:space:]]*1" "$vhost_file" 2>/dev/null | head -1)
    if [[ ! "$occurrences" =~ ^[0-9]+$ ]]; then
        occurrences=0
    fi
    
    if [[ "$occurrences" -gt 0 ]]; then
        AFFECTED_FILES=$((AFFECTED_FILES + 1))
        TOTAL_OCCURRENCES=$((TOTAL_OCCURRENCES + occurrences))
        
        echo -e "${BLUE}[$vhost_name]${NC} $vhost_file"
        echo -e "  Found ${RED}$occurrences${NC} occurrence(s) of allowBrowse 1"
        
        # Show the lines with context
        echo "  Lines:"
        grep -n "allowBrowse[[:space:]]*1" "$vhost_file" | while read -r line; do
            line_num=$(echo "$line" | cut -d: -f1)
            line_content=$(echo "$line" | cut -d: -f2-)
            echo -e "    ${YELLOW}Line $line_num:${NC}$line_content"
        done
        
        if ! $DRY_RUN; then
            # Create backup
            cp "$vhost_file" "${vhost_file}${BACKUP_SUFFIX}"
            echo -e "  ${GREEN}Backup created:${NC} ${vhost_file}${BACKUP_SUFFIX}"
            
            # Apply fix
            sed -i 's/allowBrowse[[:space:]]*1/allowBrowse             0/g' "$vhost_file"
            echo -e "  ${GREEN}Fixed:${NC} allowBrowse set to 0"
        fi
        echo ""
    fi
done <<< "$VHOST_FILES"

# Summary
echo "=========================================="
echo "  Summary"
echo "=========================================="
echo ""
echo "Total vhost files scanned: $TOTAL_FILES"
echo -e "Files with allowBrowse 1: ${YELLOW}$AFFECTED_FILES${NC}"
echo -e "Total occurrences found:  ${YELLOW}$TOTAL_OCCURRENCES${NC}"
echo ""

if $DRY_RUN; then
    if [[ "$TOTAL_OCCURRENCES" -gt 0 ]]; then
        echo -e "${YELLOW}To apply these changes, run:${NC}"
        echo "  sudo $0 --apply"
        echo ""
        echo "After applying, restart OpenLiteSpeed:"
        echo "  sudo systemctl restart lsws"
    else
        echo -e "${GREEN}All vhosts already have allowBrowse set to 0 (or not set)${NC}"
    fi
else
    if [[ "$TOTAL_OCCURRENCES" -gt 0 ]]; then
        echo -e "${GREEN}Changes applied successfully!${NC}"
        echo ""
        echo "Restart OpenLiteSpeed to apply:"
        echo "  sudo systemctl restart lsws"
        echo ""
        echo "To verify changes:"
        echo "  grep -r 'allowBrowse' $VHOST_DIR"
    else
        echo -e "${GREEN}No changes needed - all vhosts already secure${NC}"
    fi
fi
echo ""

