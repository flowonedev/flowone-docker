#!/bin/bash
#
# build-drive-mac.command
# One-shot: build FlowOne Drive for macOS (Apple Silicon / arm64), produce a
# .dmg + .app, install the app to /Applications and launch it for testing.
#
# Double-click in Finder, or run:  ./build-drive-mac.command
#
# Options:
#   --skip-install   Skip npm dependency install (use existing node_modules)
#   --skip-build     Skip the TypeScript/Vite compile (reuse existing dist/)
#   --dmg-only       Build the .dmg only; do NOT copy the app to /Applications
#   --no-launch      Install to /Applications but do not auto-launch
#   -h | --help      Show this help
#
set -euo pipefail

# --- locate project dirs relative to this script (works from Finder too) ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRIVE_DIR="$SCRIPT_DIR/email/FlowOneDrive"
RELEASE_DIR="$DRIVE_DIR/release"

# --- colors ---
GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; RED=$'\033[0;31m'; BLUE=$'\033[0;34m'; NC=$'\033[0m'
step() { echo "${BLUE}==>${NC} $1"; }
ok()   { echo "${GREEN}✔${NC} $1"; }
warn() { echo "${YELLOW}!${NC} $1"; }
die()  { echo "${RED}ERROR: $1${NC}" >&2; exit 1; }

# --- args ---
DO_INSTALL=1
DO_BUILD=1
DO_INSTALL_APP=1
DO_LAUNCH=1
while [[ $# -gt 0 ]]; do
  case "$1" in
    --skip-install) DO_INSTALL=0; shift ;;
    --skip-build)   DO_BUILD=0; shift ;;
    --dmg-only)     DO_INSTALL_APP=0; DO_LAUNCH=0; shift ;;
    --no-launch)    DO_LAUNCH=0; shift ;;
    -h|--help)
      sed -n '3,16p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) die "Unknown option: $1 (use --help)" ;;
  esac
done

# --- preflight ---
[[ "$(uname)" == "Darwin" ]] || die "macOS only (this produces a .app / .dmg)."
command -v npm >/dev/null || die "npm/node not found. Install Node.js."
[[ -d "$DRIVE_DIR" ]] || die "Drive app not found: $DRIVE_DIR"
ARCH="$(uname -m)"
[[ "$ARCH" == "arm64" ]] || warn "You're on '$ARCH', not arm64. Cross-building arm64 — native modules may need arm64 prebuilds."

cd "$DRIVE_DIR"

# --- 1. dependencies ---
# Always run `npm install`: it installs any MISSING packages (e.g. better-sqlite3)
# AND, via the package's postinstall hook (`electron-builder install-app-deps`),
# rebuilds native modules against Electron's ABI. It's idempotent and fast when
# everything is already present, so it safely covers both first-run and
# incomplete-node_modules cases.
if [[ "$DO_INSTALL" -eq 1 ]]; then
  step "Installing / verifying dependencies (also rebuilds native modules for Electron)..."
  npm install
  ok "Dependencies ready."
else
  warn "Skipping dependency install (--skip-install)."
fi

# --- 2. compile main + preload + renderer ---
if [[ "$DO_BUILD" -eq 1 ]]; then
  step "Compiling main + preload + renderer..."
  npm run build
  ok "Compiled."
else
  warn "Skipping compile (--skip-build)."
fi

# --- 3. package the macOS app (Apple Silicon / arm64) ---
# CSC_IDENTITY_AUTO_DISCOVERY=false -> ad-hoc sign, so no paid Developer ID
# certificate is required for a local test build. The dmg target + arm64 arch
# come from electron-builder.yml; we pin them on the CLI to be explicit.
step "Packaging FlowOne Drive for macOS (arm64, ad-hoc signed)..."
CSC_IDENTITY_AUTO_DISCOVERY=false npx electron-builder --mac dmg --arm64
ok "Packaged."

# --- 4. locate artifacts ---
APP_PATH="$(/usr/bin/find "$RELEASE_DIR/mac-arm64" -maxdepth 1 -name '*.app' -print -quit 2>/dev/null || true)"
[[ -z "$APP_PATH" ]] && APP_PATH="$(/usr/bin/find "$RELEASE_DIR" -maxdepth 2 -name '*.app' -print -quit 2>/dev/null || true)"
DMG_PATH="$(ls -t "$RELEASE_DIR"/*.dmg 2>/dev/null | head -1 || true)"
[[ -n "$DMG_PATH" ]] || die "Build finished but no .dmg found in $RELEASE_DIR"
ok "DMG: $DMG_PATH"
[[ -n "$APP_PATH" ]] && ok "App: $APP_PATH"

# --- 5. install to /Applications + launch ---
if [[ "$DO_INSTALL_APP" -eq 1 && -n "$APP_PATH" ]]; then
  DEST="/Applications/$(basename "$APP_PATH")"
  step "Installing to $DEST ..."
  rm -rf "$DEST"
  cp -R "$APP_PATH" "$DEST"
  # Locally-built apps aren't quarantined, but strip it just in case.
  xattr -dr com.apple.quarantine "$DEST" 2>/dev/null || true
  ok "Installed."
  if [[ "$DO_LAUNCH" -eq 1 ]]; then
    step "Launching FlowOne Drive..."
    open "$DEST"
    ok "Launched."
  fi
else
  step "Revealing the DMG in Finder (drag it onto Applications to install)..."
  open -R "$DMG_PATH"
fi

echo ""
echo "${GREEN}All done.${NC} Apple-Silicon build of FlowOne Drive is ready."
echo "  DMG:  $DMG_PATH"
[[ -n "$APP_PATH" ]] && echo "  App:  $APP_PATH"
echo ""
echo "If macOS says it 'can't be opened' (unidentified developer):"
echo "  right-click the app → Open (once), or run:"
echo "  xattr -dr com.apple.quarantine \"${DEST:-/Applications/FlowOne Drive.app}\""
