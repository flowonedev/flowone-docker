#!/bin/bash
#
# deploy-chat-ios.command
# One-shot: build the FlowOne Chat mobile bundle, sync it into the Capacitor iOS
# project, build + code-sign for the connected iPhone, install and launch it.
#
# Double-click in Finder, or run:  ./deploy-chat-ios.command
#
# Options:
#   --device <udid>   Target a specific device UDID (default: first connected)
#   --no-launch       Install but do not auto-launch the app
#   --skip-build      Skip the web build (just sync + install existing dist)
#   -h | --help       Show this help
#
# One-time App Store / push prerequisites (NOT done by this script):
#   1. Firebase: create an iOS app for bundle id com.flowone.chat and drag its
#      GoogleService-Info.plist into the App target in Xcode (lands in
#      ios/App/App/). Without it the app still runs, just with push disabled.
#      (new-chat.wav is already wired into the project's Copy Bundle Resources.)
#   2. App.entitlements aps-environment is "production" (set for App Store /
#      TestFlight). Switch to "development" only for local push debugging.
#
set -euo pipefail

# --- locate project dirs relative to this script (works from Finder too) ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MOBILE_DIR="$SCRIPT_DIR/email/FlowOneChatMobile"
IOS_APP_DIR="$MOBILE_DIR/ios/App"
BUNDLE_ID="com.flowone.chat"

# --- colors ---
GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; RED=$'\033[0;31m'; BLUE=$'\033[0;34m'; NC=$'\033[0m'
step() { echo "${BLUE}==>${NC} $1"; }
ok()   { echo "${GREEN}✔${NC} $1"; }
warn() { echo "${YELLOW}!${NC} $1"; }
die()  { echo "${RED}ERROR: $1${NC}" >&2; exit 1; }

# --- args ---
DEVICE_ID=""
DO_LAUNCH=1
DO_BUILD=1
while [[ $# -gt 0 ]]; do
  case "$1" in
    --device)    DEVICE_ID="${2:-}"; shift 2 ;;
    --no-launch) DO_LAUNCH=0; shift ;;
    --skip-build) DO_BUILD=0; shift ;;
    -h|--help)
      sed -n '3,21p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) die "Unknown option: $1 (use --help)" ;;
  esac
done

# --- preflight ---
command -v xcodebuild >/dev/null || die "xcodebuild not found. Install Xcode."
command -v npx >/dev/null        || die "npx/node not found. Install Node.js."
[[ -d "$MOBILE_DIR" ]]  || die "Chat mobile dir not found: $MOBILE_DIR"
[[ -d "$IOS_APP_DIR" ]] || die "iOS project not found: $IOS_APP_DIR"

if [[ ! -f "$IOS_APP_DIR/App/GoogleService-Info.plist" ]]; then
  warn "GoogleService-Info.plist not found — native push will be disabled."
  warn "Add it for com.flowone.chat from the Firebase console (see header notes)."
fi

# --- detect connected device (physical iOS UDID, not simulator/Mac) ---
if [[ -z "$DEVICE_ID" ]]; then
  step "Looking for a connected iPhone..."
  DEVICE_LINE="$(xcrun xctrace list devices 2>/dev/null \
    | grep -E '\(([0-9A-Fa-f]{8}-[0-9A-Fa-f]{16}|[0-9A-Fa-f]{40})\)' \
    | head -1 || true)"
  [[ -n "$DEVICE_LINE" ]] || die "No iPhone detected. Plug it in via cable, unlock it, and tap 'Trust'."
  DEVICE_ID="$(echo "$DEVICE_LINE" | sed -E 's/.*\(([0-9A-Fa-f]{8}-[0-9A-Fa-f]{16}|[0-9A-Fa-f]{40})\).*/\1/')"
  ok "Found: $(echo "$DEVICE_LINE" | sed -E 's/ *\([0-9A-Fa-f-]+\) *$//')  (${DEVICE_ID})"
fi

# --- 1. build the chat web bundle (vite -> dist) ---
if [[ "$DO_BUILD" -eq 1 ]]; then
  step "Building chat web bundle (this takes ~20s)..."
  ( cd "$MOBILE_DIR" && npm run build:web )
  ok "Web bundle built."
else
  warn "Skipping web build (--skip-build)."
fi

# --- 2. sync into the iOS Capacitor project ---
step "Syncing web assets into iOS project..."
( cd "$MOBILE_DIR" && npx cap sync ios )
ok "Synced."

# --- 3. build + code-sign for the device ---
# NB: derived-data must NOT be named "build" or CocoaPods' clean step collides.
DERIVED_DIR="$MOBILE_DIR/ios/DerivedData"
step "Building & signing the iOS app for your device..."
( cd "$IOS_APP_DIR" && xcodebuild \
    -workspace App.xcworkspace \
    -scheme App \
    -configuration Debug \
    -destination "platform=iOS,id=${DEVICE_ID}" \
    -allowProvisioningUpdates \
    -derivedDataPath "$DERIVED_DIR" \
    -quiet )
APP_PATH="$DERIVED_DIR/Build/Products/Debug-iphoneos/App.app"
[[ -d "$APP_PATH" ]] || die "Build succeeded but App.app not found at $APP_PATH"
ok "Built: App.app"

# --- 4. install on device ---
step "Installing on your iPhone..."
xcrun devicectl device install app --device "$DEVICE_ID" "$APP_PATH"
ok "Installed (${BUNDLE_ID})."

# --- 5. launch ---
if [[ "$DO_LAUNCH" -eq 1 ]]; then
  step "Launching the app..."
  xcrun devicectl device process launch --device "$DEVICE_ID" "$BUNDLE_ID" >/dev/null
  ok "Launched. Check your phone."
else
  warn "Not launching (--no-launch). Tap the FlowOne Chat icon on your phone."
fi

echo ""
echo "${GREEN}On-device build done.${NC}"
echo "For an App Store / TestFlight release: open the workspace, select"
echo "'Any iOS Device', then Product > Archive and upload via the Organizer."
echo "  cd \"$MOBILE_DIR\" && npm run build:web && npx cap sync ios && npx cap open ios"
echo ""
echo "If the phone shows 'Untrusted Developer':"
echo "  Settings → General → VPN & Device Management → trust the developer cert."
