#!/bin/bash
#
# deploy-email-ios.command
# One-shot: build the FlowOne web frontend, sync it into the Capacitor iOS
# project, build + code-sign for the connected iPhone, install and launch it.
#
# Double-click in Finder, or run:  ./deploy-email-ios.command
#
# Options:
#   --device <udid>   Target a specific device UDID (default: first connected)
#   --app-store       Export a signed IPA for App Store upload (no device needed)
#   --no-launch       Install but do not auto-launch the app
#   --skip-build      Skip the frontend build (just sync + install existing dist)
#   -h | --help       Show this help
#
set -euo pipefail

# --- locate project dirs relative to this script (works from Finder too) ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRONTEND_DIR="$SCRIPT_DIR/email/frontend"
MOBILE_DIR="$SCRIPT_DIR/email/FlowOneMobile"
IOS_APP_DIR="$MOBILE_DIR/ios/App"
BUNDLE_ID="com.flowone.pro"
TEAM_ID="9CWY396X76"
PROD_DIR="$MOBILE_DIR/ios/ProdBuild"
ARCHIVE="$PROD_DIR/App.xcarchive"
EXPORT_DIR="$PROD_DIR/export"
PLIST="$PROD_DIR/exportOptions.plist"

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
APP_STORE=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --device)    DEVICE_ID="${2:-}"; shift 2 ;;
    --app-store) APP_STORE=1; shift ;;
    --no-launch) DO_LAUNCH=0; shift ;;
    --skip-build) DO_BUILD=0; shift ;;
    -h|--help)
      sed -n '3,14p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) die "Unknown option: $1 (use --help)" ;;
  esac
done

# --- choose export method names (Xcode 15.3 renamed ad-hoc/app-store) ---
if [[ "$APP_STORE" -eq 1 ]]; then
  XCODE_VER="$( { xcodebuild -version 2>/dev/null || true; } | awk 'NR==1{print $2}' )"
  xc_major="${XCODE_VER%%.*}"
  xc_rest="${XCODE_VER#*.}"; xc_minor="${xc_rest%%.*}"; xc_minor="${xc_minor:-0}"
  if [[ "${xc_major:-0}" -gt 15 || ( "${xc_major:-0}" -eq 15 && "${xc_minor:-0}" -ge 3 ) ]]; then
    EXPORT_METHOD="app-store-connect"
  else
    EXPORT_METHOD="app-store"
  fi
  ok "Xcode ${XCODE_VER:-unknown} -> export method: ${EXPORT_METHOD}"
fi

# --- preflight ---
command -v xcodebuild >/dev/null || die "xcodebuild not found. Install Xcode."
command -v npx >/dev/null        || die "npx/node not found. Install Node.js."
[[ -d "$FRONTEND_DIR" ]] || die "Frontend dir not found: $FRONTEND_DIR"
[[ -d "$IOS_APP_DIR" ]]  || die "iOS project not found: $IOS_APP_DIR"

# --- detect connected device (physical iOS UDID, not simulator/Mac) ---
# Skipped for App Store export, which does not touch a device.
if [[ "$APP_STORE" -eq 0 && -z "$DEVICE_ID" ]]; then
  step "Looking for a connected iPhone..."
  DEVICE_LINE="$(xcrun xctrace list devices 2>/dev/null \
    | grep -E '\(([0-9A-Fa-f]{8}-[0-9A-Fa-f]{16}|[0-9A-Fa-f]{40})\)' \
    | head -1 || true)"
  [[ -n "$DEVICE_LINE" ]] || die "No iPhone detected. Plug it in via cable, unlock it, and tap 'Trust'."
  DEVICE_ID="$(echo "$DEVICE_LINE" | sed -E 's/.*\(([0-9A-Fa-f]{8}-[0-9A-Fa-f]{16}|[0-9A-Fa-f]{40})\).*/\1/')"
  ok "Found: $(echo "$DEVICE_LINE" | sed -E 's/ *\([0-9A-Fa-f-]+\) *$//')  (${DEVICE_ID})"
fi

# --- 1. build the web frontend ---
if [[ "$DO_BUILD" -eq 1 ]]; then
  step "Building web frontend (this takes ~20s)..."
  ( cd "$FRONTEND_DIR" && npm run build )
  ok "Frontend built."
else
  warn "Skipping frontend build (--skip-build)."
fi

# --- 2. sync into the iOS Capacitor project ---
step "Syncing web assets into iOS project..."
( cd "$MOBILE_DIR" && npx cap sync ios )
ok "Synced."

# --- App Store path: archive Release (distribution signed) + export an IPA ---
if [[ "$APP_STORE" -eq 1 ]]; then
  rm -rf "$PROD_DIR"
  mkdir -p "$PROD_DIR"
  step "Archiving Release (distribution signed; can take a few minutes)..."
  ( cd "$IOS_APP_DIR" && xcodebuild \
      -workspace App.xcworkspace \
      -scheme App \
      -configuration Release \
      -destination 'generic/platform=iOS' \
      -allowProvisioningUpdates \
      -archivePath "$ARCHIVE" \
      DEVELOPMENT_TEAM="$TEAM_ID" \
      CODE_SIGN_STYLE=Automatic \
      archive )
  [[ -d "$ARCHIVE" ]] || die "Archive failed: $ARCHIVE not found"
  ok "Archived."

  cat > "$PLIST" <<PLIST_EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>method</key>
  <string>${EXPORT_METHOD}</string>
  <key>teamID</key>
  <string>${TEAM_ID}</string>
  <key>signingStyle</key>
  <string>automatic</string>
  <key>stripSwiftSymbols</key>
  <true/>
  <key>compileBitcode</key>
  <false/>
  <key>destination</key>
  <string>export</string>
</dict>
</plist>
PLIST_EOF

  step "Exporting signed build (${EXPORT_METHOD})..."
  ( cd "$IOS_APP_DIR" && xcodebuild \
      -exportArchive \
      -archivePath "$ARCHIVE" \
      -exportOptionsPlist "$PLIST" \
      -exportPath "$EXPORT_DIR" \
      -allowProvisioningUpdates )
  IPA="$(ls "$EXPORT_DIR"/*.ipa 2>/dev/null | head -1 || true)"
  [[ -n "$IPA" ]] || die "Export produced no .ipa in $EXPORT_DIR"
  ok "Exported: $(basename "$IPA")"

  echo ""
  echo "${GREEN}App Store build exported:${NC} $IPA"
  echo "Upload it with Transporter, or:"
  echo "  xcrun altool --upload-app -f \"$IPA\" -t ios --apiKey <KEY_ID> --apiIssuer <ISSUER_ID>"
  echo "Or open the archive in Xcode Organizer (Window > Organizer) and Distribute:"
  echo "  $ARCHIVE"
  exit 0
fi

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
  warn "Not launching (--no-launch). Tap the FlowOne Pro icon on your phone."
fi

echo ""
echo "${GREEN}All done.${NC} If the phone shows 'Untrusted Developer':"
echo "  Settings → General → VPN & Device Management → trust the developer cert."
