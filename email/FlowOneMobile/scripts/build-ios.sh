#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
FRONTEND_DIR="$(cd "$PROJECT_DIR/../frontend" && pwd)"

echo "Building FlowOne Pro for iOS..."

echo "[1/3] Building web frontend..."
cd "$FRONTEND_DIR"
npm run build

echo "[2/3] Syncing to iOS..."
cd "$PROJECT_DIR"
npx cap sync ios

echo "[3/3] Opening Xcode..."
npx cap open ios

echo "Done! Build and archive in Xcode: Product > Archive"
