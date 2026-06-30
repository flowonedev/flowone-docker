#!/bin/bash
# DNS Record Fixer - Interactive domain-by-domain script
# Database: devc_vps_dash

DB="devc_vps_dash"
NAMESERVER="ns1.devcon1.hu"
ADMIN_PREFIX="admin"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}   DNS Record Fixer - Interactive Mode  ${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Get all domains that have issues (SOA with wrong nameserver)
echo -e "${YELLOW}Scanning for domains with DNS issues...${NC}"
echo ""

PROBLEM_DOMAINS=$(mysql -N -e "
SELECT DISTINCT d.name 
FROM dns_domains d
JOIN dns_records r ON r.domain_id = d.id
WHERE r.type = 'SOA' 
  AND r.content NOT LIKE 'ns1.devcon1.hu%'
  AND r.content NOT LIKE 'ns2.devcon1.hu%'
ORDER BY d.name;
" $DB 2>/dev/null)

if [ -z "$PROBLEM_DOMAINS" ]; then
    echo -e "${GREEN}No domains with SOA issues found!${NC}"
    
    # Also check for orphan records
    echo ""
    echo -e "${YELLOW}Checking for orphan _dmarc.mail.* or _domainkey.mail.* records...${NC}"
    
    ORPHAN_COUNT=$(mysql -N -e "
    SELECT COUNT(*) FROM dns_records 
    WHERE name LIKE '_dmarc.mail.%' 
       OR name LIKE '_domainkey.mail.%'
       OR (name LIKE '_domainkey.%' AND name NOT LIKE 'default._domainkey.%' AND content LIKE '%t=y%');
    " $DB 2>/dev/null)
    
    if [ "$ORPHAN_COUNT" -gt 0 ]; then
        echo -e "${YELLOW}Found $ORPHAN_COUNT orphan records to clean up${NC}"
    else
        echo -e "${GREEN}All DNS records look clean!${NC}"
        exit 0
    fi
fi

echo -e "${YELLOW}Found domains with potential issues:${NC}"
echo "$PROBLEM_DOMAINS" | nl
echo ""

# Process each domain
for DOMAIN in $PROBLEM_DOMAINS; do
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}   Processing: ${YELLOW}$DOMAIN${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo ""
    
    # Get domain ID
    DOMAIN_ID=$(mysql -N -e "SELECT id FROM dns_domains WHERE name='$DOMAIN';" $DB 2>/dev/null)
    
    if [ -z "$DOMAIN_ID" ]; then
        echo -e "${RED}Could not find domain ID for $DOMAIN, skipping...${NC}"
        continue
    fi
    
    echo -e "${YELLOW}Current DNS records for $DOMAIN:${NC}"
    echo "-------------------------------------------"
    mysql -e "
    SELECT name, type, 
           SUBSTRING(content, 1, 60) as content_preview, 
           prio as priority
    FROM dns_records 
    WHERE domain_id = $DOMAIN_ID 
    ORDER BY type, name;
    " $DB 2>/dev/null
    echo ""
    
    # Check what needs fixing
    echo -e "${YELLOW}Issues detected:${NC}"
    
    # 1. Check SOA
    SOA_CONTENT=$(mysql -N -e "SELECT content FROM dns_records WHERE domain_id=$DOMAIN_ID AND type='SOA';" $DB 2>/dev/null)
    if [[ ! "$SOA_CONTENT" =~ ^ns[12]\.devcon1\.hu ]]; then
        echo -e "  ${RED}✗${NC} SOA nameserver is wrong: ${RED}$(echo $SOA_CONTENT | awk '{print $1}')${NC}"
        FIX_SOA=1
    else
        echo -e "  ${GREEN}✓${NC} SOA nameserver is correct"
        FIX_SOA=0
    fi
    
    # 2. Check for _dmarc.mail.* records
    DMARC_MAIL=$(mysql -N -e "SELECT id, name FROM dns_records WHERE domain_id=$DOMAIN_ID AND name LIKE '_dmarc.mail.%';" $DB 2>/dev/null)
    if [ -n "$DMARC_MAIL" ]; then
        echo -e "  ${RED}✗${NC} Found orphan _dmarc.mail.* record"
        DELETE_DMARC_MAIL=1
    else
        echo -e "  ${GREEN}✓${NC} No orphan _dmarc.mail.* records"
        DELETE_DMARC_MAIL=0
    fi
    
    # 3. Check for _domainkey.mail.* records
    DKEY_MAIL=$(mysql -N -e "SELECT id, name FROM dns_records WHERE domain_id=$DOMAIN_ID AND name LIKE '_domainkey.mail.%';" $DB 2>/dev/null)
    if [ -n "$DKEY_MAIL" ]; then
        echo -e "  ${RED}✗${NC} Found orphan _domainkey.mail.* record"
        DELETE_DKEY_MAIL=1
    else
        echo -e "  ${GREEN}✓${NC} No orphan _domainkey.mail.* records"
        DELETE_DKEY_MAIL=0
    fi
    
    # 4. Check for old _domainkey.{domain} with t=y format
    OLD_DKEY=$(mysql -N -e "SELECT id FROM dns_records WHERE domain_id=$DOMAIN_ID AND name='_domainkey.$DOMAIN' AND content LIKE '%t=y%';" $DB 2>/dev/null)
    if [ -n "$OLD_DKEY" ]; then
        echo -e "  ${RED}✗${NC} Found old-format _domainkey.$DOMAIN record"
        DELETE_OLD_DKEY=1
    else
        echo -e "  ${GREEN}✓${NC} No old-format _domainkey records"
        DELETE_OLD_DKEY=0
    fi
    
    # 5. Check DMARC policy (should be p=reject with strict alignment)
    DMARC_CONTENT=$(mysql -N -e "SELECT content FROM dns_records WHERE domain_id=$DOMAIN_ID AND name='_dmarc.$DOMAIN' AND type='TXT';" $DB 2>/dev/null)
    UPGRADE_DMARC=0
    if [[ "$DMARC_CONTENT" == *"p=none"* ]]; then
        echo -e "  ${YELLOW}!${NC} DMARC policy is 'none' (should be 'reject' with strict alignment)"
        UPGRADE_DMARC=1
    elif [[ "$DMARC_CONTENT" == *"p=quarantine"* ]]; then
        echo -e "  ${YELLOW}!${NC} DMARC policy is 'quarantine' (should be 'reject' with strict alignment)"
        UPGRADE_DMARC=1
    elif [[ ! "$DMARC_CONTENT" == *"adkim=s"* ]] || [[ ! "$DMARC_CONTENT" == *"aspf=s"* ]]; then
        echo -e "  ${YELLOW}!${NC} DMARC missing strict alignment (adkim=s; aspf=s)"
        UPGRADE_DMARC=1
    fi
    
    # 6. Check SPF policy (should use -all not ~all)
    SPF_CONTENT=$(mysql -N -e "SELECT content FROM dns_records WHERE domain_id=$DOMAIN_ID AND name='$DOMAIN' AND type='TXT' AND content LIKE 'v=spf1%';" $DB 2>/dev/null)
    UPGRADE_SPF=0
    if [[ "$SPF_CONTENT" == *"~all"* ]]; then
        echo -e "  ${YELLOW}!${NC} SPF policy uses soft fail (~all), should be hard fail (-all)"
        UPGRADE_SPF=1
    fi
    
    echo ""
    
    # If nothing to fix, skip
    if [ "$FIX_SOA" -eq 0 ] && [ "$DELETE_DMARC_MAIL" -eq 0 ] && [ "$DELETE_DKEY_MAIL" -eq 0 ] && [ "$DELETE_OLD_DKEY" -eq 0 ]; then
        echo -e "${GREEN}No critical issues for $DOMAIN!${NC}"
        echo ""
        read -p "Press Enter to continue to next domain..."
        continue
    fi
    
    # Ask for confirmation
    echo -e "${YELLOW}Do you want to fix these issues? [y/N/s(kip)/q(uit)]${NC}"
    read -r CONFIRM
    
    case "$CONFIRM" in
        q|Q)
            echo "Exiting..."
            exit 0
            ;;
        s|S)
            echo "Skipping $DOMAIN..."
            continue
            ;;
        y|Y)
            echo ""
            echo -e "${YELLOW}Applying fixes...${NC}"
            
            # Fix SOA
            if [ "$FIX_SOA" -eq 1 ]; then
                # Extract serial and other SOA params, replace nameserver
                # SOA format: ns1.domain.com admin.domain.com serial refresh retry expire minimum
                SERIAL=$(echo "$SOA_CONTENT" | awk '{print $3}')
                NEW_SOA="$NAMESERVER $ADMIN_PREFIX.$DOMAIN $SERIAL 10800 3600 604800 3600"
                
                mysql -e "UPDATE dns_records SET content='$NEW_SOA' WHERE domain_id=$DOMAIN_ID AND type='SOA';" $DB 2>/dev/null
                echo -e "  ${GREEN}✓${NC} Fixed SOA record"
            fi
            
            # Delete _dmarc.mail.* records
            if [ "$DELETE_DMARC_MAIL" -eq 1 ]; then
                mysql -e "DELETE FROM dns_records WHERE domain_id=$DOMAIN_ID AND name LIKE '_dmarc.mail.%';" $DB 2>/dev/null
                echo -e "  ${GREEN}✓${NC} Deleted _dmarc.mail.* records"
            fi
            
            # Delete _domainkey.mail.* records
            if [ "$DELETE_DKEY_MAIL" -eq 1 ]; then
                mysql -e "DELETE FROM dns_records WHERE domain_id=$DOMAIN_ID AND name LIKE '_domainkey.mail.%';" $DB 2>/dev/null
                echo -e "  ${GREEN}✓${NC} Deleted _domainkey.mail.* records"
            fi
            
            # Delete old _domainkey.{domain} records
            if [ "$DELETE_OLD_DKEY" -eq 1 ]; then
                mysql -e "DELETE FROM dns_records WHERE domain_id=$DOMAIN_ID AND name='_domainkey.$DOMAIN' AND content LIKE '%t=y%';" $DB 2>/dev/null
                echo -e "  ${GREEN}✓${NC} Deleted old-format _domainkey record"
            fi
            
            # Optionally upgrade DMARC
            if [ "$UPGRADE_DMARC" -eq 1 ]; then
                echo ""
                echo -e "${YELLOW}Upgrade DMARC to strict policy (p=reject; adkim=s; aspf=s)? [y/N]${NC}"
                read -r UPGRADE_CONFIRM
                if [[ "$UPGRADE_CONFIRM" =~ ^[Yy]$ ]]; then
                    NEW_DMARC="v=DMARC1; p=reject; adkim=s; aspf=s; pct=100; rua=mailto:postmaster@$DOMAIN; fo=1"
                    mysql -e "UPDATE dns_records SET content='$NEW_DMARC' WHERE domain_id=$DOMAIN_ID AND name='_dmarc.$DOMAIN' AND type='TXT';" $DB 2>/dev/null
                    echo -e "  ${GREEN}✓${NC} Upgraded DMARC policy"
                fi
            fi
            
            # Optionally upgrade SPF
            if [ "$UPGRADE_SPF" -eq 1 ]; then
                echo ""
                echo -e "${YELLOW}Upgrade SPF from soft fail (~all) to hard fail (-all)? [y/N]${NC}"
                read -r UPGRADE_CONFIRM
                if [[ "$UPGRADE_CONFIRM" =~ ^[Yy]$ ]]; then
                    # Replace ~all with -all in the SPF record
                    mysql -e "UPDATE dns_records SET content = REPLACE(content, '~all', '-all') WHERE domain_id=$DOMAIN_ID AND name='$DOMAIN' AND type='TXT' AND content LIKE 'v=spf1%';" $DB 2>/dev/null
                    echo -e "  ${GREEN}✓${NC} Upgraded SPF policy"
                fi
            fi
            
            # Increment SOA serial
            NEW_SERIAL=$(date +%Y%m%d%H)
            mysql -e "UPDATE dns_records SET content = CONCAT('$NAMESERVER $ADMIN_PREFIX.$DOMAIN ', '$NEW_SERIAL', ' 10800 3600 604800 3600') WHERE domain_id=$DOMAIN_ID AND type='SOA';" $DB 2>/dev/null
            
            echo ""
            echo -e "${GREEN}Fixes applied! Updated records:${NC}"
            echo "-------------------------------------------"
            mysql -e "
            SELECT name, type, 
                   SUBSTRING(content, 1, 60) as content_preview, 
                   prio as priority
            FROM dns_records 
            WHERE domain_id = $DOMAIN_ID 
            ORDER BY type, name;
            " $DB 2>/dev/null
            ;;
        *)
            echo "Skipping $DOMAIN..."
            continue
            ;;
    esac
    
    echo ""
    echo -e "${YELLOW}Please verify the changes in the panel, then press Enter to continue...${NC}"
    read -r
    
done

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}   All domains processed!              ${NC}"
echo -e "${GREEN}========================================${NC}"

