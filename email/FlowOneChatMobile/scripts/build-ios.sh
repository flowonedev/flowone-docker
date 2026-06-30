#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

echo "Building FlowOne Chat for iOS..."

echo "[1/3] Building web frontend..."
cd "$PROJECT_DIR"
npm run build:web

echo "[2/3] Syncing to iOS..."
npx cap sync ios

echo "[3/3] Opening Xcode..."
npx cap open ios

echo "Done! Build and archive in Xcode: Product > Archive"
