#!/bin/bash
# Generate RSA key pair for RS256 JWT signing
#
# Usage: bash scripts/generate-jwt-keys.sh
#
# This creates:
#   storage/config/jwt-private.pem  (PRIVATE - backend only, never share)
#   storage/config/jwt-public.pem   (PUBLIC  - distribute to all verifying services)
#
# After generating:
#   1. Copy jwt-public.pem to mailsync/server/ and collab/server/
#   2. Set JWT_PUBLIC_KEY_PATH in their .env files
#   3. Update backend .env with JWT_PRIVATE_KEY_PATH and JWT_PUBLIC_KEY_PATH
#   4. Deploy and wait 8 days (max token lifetime) before removing HS256 fallback

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
CONFIG_DIR="$SCRIPT_DIR/../storage/config"

# Create config directory if it doesn't exist
mkdir -p "$CONFIG_DIR"

PRIVATE_KEY="$CONFIG_DIR/jwt-private.pem"
PUBLIC_KEY="$CONFIG_DIR/jwt-public.pem"

if [ -f "$PRIVATE_KEY" ]; then
    echo "WARNING: Private key already exists at $PRIVATE_KEY"
    echo "If you regenerate, ALL existing tokens will be invalidated."
    read -p "Continue? (y/N): " confirm
    if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
        echo "Aborted."
        exit 0
    fi
fi

echo "Generating 2048-bit RSA key pair..."

# Generate private key
openssl genrsa -out "$PRIVATE_KEY" 2048 2>/dev/null

# Extract public key
openssl rsa -in "$PRIVATE_KEY" -pubout -out "$PUBLIC_KEY" 2>/dev/null

# Set permissions: private key readable only by owner
chmod 600 "$PRIVATE_KEY"
chmod 644 "$PUBLIC_KEY"

echo ""
echo "Keys generated successfully:"
echo "  Private key: $PRIVATE_KEY (mode 600)"
echo "  Public key:  $PUBLIC_KEY (mode 644)"
echo ""
echo "Next steps:"
echo "  1. Add to backend .env:"
echo "     JWT_PRIVATE_KEY_PATH=$PRIVATE_KEY"
echo "     JWT_PUBLIC_KEY_PATH=$PUBLIC_KEY"
echo "     JWT_ALGORITHM=RS256"
echo ""
echo "  2. Copy public key to verifying services:"
echo "     cp $PUBLIC_KEY /path/to/mailsync/server/jwt-public.pem"
echo "     cp $PUBLIC_KEY /path/to/collab/server/jwt-public.pem"
echo ""
echo "  3. Add to mailsync/server/.env and collab/server/.env:"
echo "     JWT_PUBLIC_KEY_PATH=./jwt-public.pem"
echo "     JWT_ALGORITHM=RS256"
echo ""
echo "  4. Wait 8 days after deploy before removing HS256 fallback"
echo "     (max token lifetime = 7d refresh + 12h access)"

