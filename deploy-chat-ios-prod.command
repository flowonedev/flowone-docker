#!/bin/bash
#
# deploy-chat-ios-prod.command
# PRODUCTION one-shot for FlowOne Chat (iOS).
#
# Builds the chat web bundle, archives the Release configuration (distribution
# signed), exports a PRODUCTION-signed build, and installs it on your cabled
# iPhone via an Ad Hoc export. This is the build that uses PRODUCTION APNs, so
# the full-screen CallKit incoming-call UI actually works. Ship the SAME Release
# archive to the App Store with --app-store (or via Xcode Organizer).
#
# Double-click in Finder, or run:  ./deploy-chat-ios-prod.command
#
# Options:
#   --device <udid>   Target a specific device UDID (default: first connected)
#   --app-store       Export for App Store upload instead of installing on device
#   --no-launch       Install but do not auto-launch the app
#   --skip-build      Skip the web build (reuse existing dist)
#   --logs            After install, launch with native console capture (~50s)
#                     and print the PushKit/CallManager VoIP-token diagnostics
#   --logs-only       Skip build/install entirely; just relaunch the already
#                     installed app and capture the console (fast diagnostics)
#   -h | --help       Show this help
#
# Requirements:
#   - Paid Apple Developer Program membership (Team 9CWY396X76) for distribution
#     signing. -allowProvisioningUpdates registers the device + creates the
#     Ad Hoc / App Store provisioning profile automatically.
#   - App.entitlements aps-environment = production (already set).
#   - Server mailsync .env: APNS_VOIP_PRODUCTION=true (must match this build).
#
set -Eeuo pipefail

# --- locate project dirs relative to this script (works from Finder too) ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MOBILE_DIR="$SCRIPT_DIR/email/FlowOneChatMobile"
IOS_DIR="$MOBILE_DIR/ios"
IOS_APP_DIR="$IOS_DIR/App"
PROD_DIR="$IOS_DIR/ProdBuild"
ARCHIVE="$PROD_DIR/App.xcarchive"
EXPORT_DIR="$PROD_DIR/export"
PLIST="$PROD_DIR/exportOptions.plist"
BUNDLE_ID="com.flowone.chat"
TEAM_ID="9CWY396X76"

# --- robustness: mirror ALL output to a log file + never fail silently ---
# Double-clicking a .command in Finder closes the Terminal on exit, so this log
# is the source of truth for what happened.
LOG_FILE="$SCRIPT_DIR/deploy-chat-ios-prod.log"
exec > >(tee "$LOG_FILE") 2>&1
trap 'echo ">>> FAILED (exit $?) near line ${LINENO}. Full log: $LOG_FILE" >&2' ERR

# Finder-launched .command files start with a minimal PATH (no Homebrew/nvm),
# so node/npx vanish. Pull in the user's real login-shell PATH first, then add
# the usual fallbacks (incl. versioned Homebrew node kegs like node@20).
export PATH="/usr/bin:/bin:/usr/sbin:/sbin:$PATH"
USER_PATH="$(/bin/zsh -lic 'printf %s "$PATH"' 2>/dev/null || true)"
[ -n "${USER_PATH:-}" ] && export PATH="$USER_PATH:$PATH"
export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
for _d in /opt/homebrew/opt/node@*/bin /usr/local/opt/node@*/bin; do
  [ -d "$_d" ] && export PATH="$_d:$PATH"
done
if [ -d "$HOME/.nvm/versions/node" ]; then
  _nvm_bin="$(ls -d "$HOME"/.nvm/versions/node/*/bin 2>/dev/null | sort -V | tail -1 || true)"
  [ -n "${_nvm_bin:-}" ] && export PATH="$_nvm_bin:$PATH"
fi

echo "=== deploy-chat-ios-prod  $(date '+%Y-%m-%d %H:%M:%S') ==="
echo "script dir : $SCRIPT_DIR"
echo "node       : $(command -v node || echo 'NOT FOUND')"
echo "npm        : $(command -v npm || echo 'NOT FOUND')"
echo "npx        : $(command -v npx || echo 'NOT FOUND')"
echo "xcodebuild : $(command -v xcodebuild || echo 'NOT FOUND')"
echo "------------------------------------------------------------"

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
LOG_STREAM=0
LOGS_ONLY=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --device)    DEVICE_ID="${2:-}"; shift 2 ;;
    --app-store) APP_STORE=1; shift ;;
    --no-launch) DO_LAUNCH=0; shift ;;
    --skip-build) DO_BUILD=0; shift ;;
    --logs)      LOG_STREAM=1; shift ;;
    --logs-only) LOGS_ONLY=1; LOG_STREAM=1; shift ;;
    -h|--help)
      sed -n '3,26p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
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
  warn "Add it for com.flowone.chat from the Firebase console."
fi

# --- choose export method names (Xcode 15.3 renamed ad-hoc/app-store) ---
XCODE_VER="$( { xcodebuild -version 2>/dev/null || true; } | awk 'NR==1{print $2}' )"
xc_major="${XCODE_VER%%.*}"
xc_rest="${XCODE_VER#*.}"; xc_minor="${xc_rest%%.*}"; xc_minor="${xc_minor:-0}"
NEW_NAMES=0
if [[ "${xc_major:-0}" -gt 15 || ( "${xc_major:-0}" -eq 15 && "${xc_minor:-0}" -ge 3 ) ]]; then
  NEW_NAMES=1
fi
if [[ "$APP_STORE" -eq 1 ]]; then
  [[ "$NEW_NAMES" -eq 1 ]] && EXPORT_METHOD="app-store-connect" || EXPORT_METHOD="app-store"
else
  [[ "$NEW_NAMES" -eq 1 ]] && EXPORT_METHOD="release-testing" || EXPORT_METHOD="ad-hoc"
fi
ok "Xcode ${XCODE_VER:-unknown} -> export method: ${EXPORT_METHOD}"

# --- detect connected device (skip for App Store export) ---
if [[ "$APP_STORE" -eq 0 && -z "$DEVICE_ID" ]]; then
  step "Looking for a connected iPhone..."
  DEVICE_LINE="$(xcrun xctrace list devices 2>/dev/null \
    | grep -E '\(([0-9A-Fa-f]{8}-[0-9A-Fa-f]{16}|[0-9A-Fa-f]{40})\)' \
    | head -1 || true)"
  [[ -n "$DEVICE_LINE" ]] || die "No iPhone detected. Plug it in via cable, unlock it, and tap 'Trust'."
  DEVICE_ID="$(echo "$DEVICE_LINE" | sed -E 's/.*\(([0-9A-Fa-f]{8}-[0-9A-Fa-f]{16}|[0-9A-Fa-f]{40})\).*/\1/')"
  ok "Found: $(echo "$DEVICE_LINE" | sed -E 's/ *\([0-9A-Fa-f-]+\) *$//')  (${DEVICE_ID})"
fi

if [[ "$LOGS_ONLY" -eq 1 ]]; then
  warn "Logs-only mode: skipping build/archive/export/install; relaunching the installed app."
fi

# --- 1. build the chat web bundle (vite -> dist) ---
if [[ "$LOGS_ONLY" -eq 0 ]]; then
if [[ "$DO_BUILD" -eq 1 ]]; then
  step "Building chat web bundle (~20s)..."
  ( cd "$MOBILE_DIR" && npm run build:web )
  ok "Web bundle built."
else
  warn "Skipping web build (--skip-build)."
fi

# --- 2. sync into the iOS Capacitor project ---
step "Syncing web assets into iOS project..."
( cd "$MOBILE_DIR" && npx cap sync ios )
ok "Synced."

# --- 3. archive Release (distribution signed) ---
# Auto-bump the build number every prod deploy. iOS only treats an install as a
# NEW build when CFBundleVersion changes; a fixed value (the project ships "2")
# is why a redeploy could look like "nothing refreshed". A monotonic timestamp
# guarantees a fresh, unique, App-Store-valid build each run — and confirms the
# device is actually running this bundle (Settings > General > About, or the
# version shown in-app).
BUILD_NUMBER="$(date +%Y%m%d%H%M)"
ok "Build number (CFBundleVersion) for this deploy: ${BUILD_NUMBER}"

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
    CURRENT_PROJECT_VERSION="$BUILD_NUMBER" \
    archive )
[[ -d "$ARCHIVE" ]] || die "Archive failed: $ARCHIVE not found"
ok "Archived."

# --- 4. write exportOptions.plist ---
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

# --- 5. export the signed build ---
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

# --- App Store path stops at the exported .ipa ---
if [[ "$APP_STORE" -eq 1 ]]; then
  echo ""
  echo "${GREEN}App Store build exported:${NC} $IPA"
  echo "Upload it with Transporter, or:"
  echo "  xcrun altool --upload-app -f \"$IPA\" -t ios --apiKey <KEY_ID> --apiIssuer <ISSUER_ID>"
  echo "Or open the archive in Xcode Organizer (Window > Organizer) and Distribute:"
  echo "  $ARCHIVE"
  exit 0
fi

# --- 6. install on device (extract .app for max devicectl compatibility) ---
step "Installing on your iPhone..."
UNZIP_DIR="$EXPORT_DIR/extracted"
rm -rf "$UNZIP_DIR"; mkdir -p "$UNZIP_DIR"
unzip -oq "$IPA" -d "$UNZIP_DIR"
APP_PATH="$(ls -d "$UNZIP_DIR"/Payload/*.app 2>/dev/null | head -1 || true)"
[[ -n "$APP_PATH" ]] || die "Could not find .app inside $IPA"
xcrun devicectl device install app --device "$DEVICE_ID" "$APP_PATH"
ok "Installed (${BUNDLE_ID})."
fi  # end LOGS_ONLY guard

# --- 7. launch ---
if [[ "$DO_LAUNCH" -eq 1 ]]; then
  if [[ "$LOG_STREAM" -eq 1 ]]; then
    echo ""
    echo "${RED}>>> UNLOCK YOUR PHONE NOW and keep the screen ON. <<<${NC}"
    echo "${YELLOW}The webview/JS only boots when the app is on-screen & unlocked.${NC}"
    echo "${YELLOW}When the app opens, KEEP LOOKING AT IT — do not lock the phone.${NC}"
    for n in 8 7 6 5 4 3 2 1; do printf "\r  launching capture in %ss... " "$n"; sleep 1; done
    echo ""
    step "Launching with native console capture (~50s). Keep the app foregrounded."
    echo "------------------------- DEVICE CONSOLE -------------------------"
    ( xcrun devicectl device process launch --console --terminate-existing \
        --device "$DEVICE_ID" "$BUNDLE_ID" & _lp=$!; sleep 50; kill "$_lp" 2>/dev/null || true ) 2>&1 \
      | grep --line-buffered -E 'CallManager|CallNative|CallKit|VoIP|PushKit|[Vv]oip' || true
    echo "------------------------ END DEVICE CONSOLE ----------------------"
    ok "Console capture done."
    echo "Expected, in order:"
    echo "  [CallManager] ... didUpdate VoIP token received   (native got the token)"
    echo "  [CallNative] plugin load()                         (webview/bridge booted)"
    echo "  [CallNative] JS> [CallKit] init() starting         (callKit.init ran)"
    echo "  [CallNative] getVoipToken() called by JS ...       (JS reached the bridge)"
    echo "  [CallNative] JS> [CallKit] ... registered OK / FAILED status=NNN"
    warn "If you see ONLY [CallManager] lines (no [CallNative]/[CallKit]),"
    warn "the app never came to the foreground — rerun and keep it on-screen."
  else
    step "Launching the app..."
    xcrun devicectl device process launch --device "$DEVICE_ID" "$BUNDLE_ID" >/dev/null
    ok "Launched. Check your phone."
  fi
else
  warn "Not launching (--no-launch). Tap the FlowOne Chat icon on your phone."
fi

echo ""
echo "${GREEN}Production build on device.${NC}"
echo "This build uses PRODUCTION APNs — the mailsync server .env MUST have"
echo "APNS_VOIP_PRODUCTION=true for the full-screen CallKit ring to work."
echo "To ship to the App Store: re-run with --app-store (or Xcode Organizer > Distribute)."
echo ""
echo "If the phone shows 'Untrusted Developer':"
echo "  Settings > General > VPN & Device Management > trust the developer cert."
