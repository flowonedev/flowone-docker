#!/bin/bash
# Quick rebuild script: builds frontend + syncs to iOS/Android
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "Building frontend..."
cd "$SCRIPT_DIR/../frontend"
npm run build

echo ""
echo "Syncing to Capacitor..."
cd "$SCRIPT_DIR"
npx cap sync

echo ""
echo "Done! Now rebuild in Xcode (Cmd+R) or Android Studio."
