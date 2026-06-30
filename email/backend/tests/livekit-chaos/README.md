# LiveKit Chaos Suite — Phase C2

End-to-end resilience tests for the unified `/guest/call/{token}` meeting flow, driven by Playwright + Chromium. Validates LiveKit reconnect, wake-lock, visibility, dup-tab detection, kick, revoke-all, waiting room, and workshop mode end-to-end against a real (or staging) FlowOne deployment.

This suite is **gated behind `RUN_MEETING_LIVEKIT_CHAOS=1`** so it cannot accidentally fire during cron or CI.

---

## Quick start

```bash
cd /var/www/vps-email/backend/tests/livekit-chaos
npm install
npx playwright install chromium

cp .env.example .env
# Edit .env with FLOWONE_ADMIN_EMAIL / PASSWORD / CRM_CLIENT_ID

RUN_MEETING_LIVEKIT_CHAOS=1 \
/usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/meeting-livekit-chaos-test.php \
    --base-url=https://flowone.pro --verbose
```

## Recommended PHP wrapper invocations

| Goal | Command |
|------|---------|
| Smoke check (CI-friendly) | `RUN_MEETING_LIVEKIT_CHAOS=1 php meeting-livekit-chaos-test.php --smoke --json` |
| Single scenario | `RUN_MEETING_LIVEKIT_CHAOS=1 php meeting-livekit-chaos-test.php --only=waiting_room_flow --verbose` |
| Full suite, JSON output | `RUN_MEETING_LIVEKIT_CHAOS=1 php meeting-livekit-chaos-test.php --json` |
| Pre-existing room (skip provisioning) | `FLOWONE_GUEST_TOKEN=… FLOWONE_ADMIN_TOKEN=… RUN_MEETING_LIVEKIT_CHAOS=1 php meeting-livekit-chaos-test.php --skip-send` |

## Scenarios

Each `.spec.js` under `scenarios/` is one assertion-focused test:

| File | Asserts |
|------|---------|
| `reconnect_wifi_switch.spec.js` | 5s offline → LiveKit auto-reconnect within 10s. `@smoke` |
| `reconnect_long_pause.spec.js` | 60s offline → fresh-token rejoin via `reconnectFn`; `/join` called ≥2× |
| `mobile_background.spec.js` | iOS-style `visibilitychange` for 30s → video resumes, wake-lock re-acquired. `@mobile` |
| `dup_tab_warn.spec.js` | Two contexts on same `/guest/call/{token}` → second shows duplicate warning |
| `kick_disconnects.spec.js` | Admin kick → `Disconnected(ParticipantRemoved)` within 3s; reload returns 410 |
| `revoke_disconnects_all.spec.js` | 3 participants, full revoke → all 3 kicked with overlay |
| `waiting_room_flow.spec.js` | Pending → admin admits → guest connected within 5s |
| `waiting_room_data_channel.spec.js` | Admin already in room → admission_request DataReceived in 3s |
| `workshop_mode_visibility.spec.js` | Guests see only admin in participants list; admin sees both guests |
| `workshop_mode_publish_blocked.spec.js` | Server-enforced `canPublish:false` rejects `setMicrophoneEnabled(true)` |

## Architecture

```
livekit-chaos/
├── package.json            # @playwright/test + dotenv
├── playwright.config.js    # 90s timeout, fake media, 2 projects (desktop, mobile)
├── run.js                  # Node orchestrator wrapping `npx playwright test`
├── scenarios/              # one *.spec.js per chaos scenario
├── lib/
│   ├── api.js              # REST helpers (login, createPortalCall, kick, lobby…)
│   ├── fixtures.js         # Playwright fixtures (admin context, provisionRoom)
│   └── livekit.js          # Page-side hooks exposing window.__flowoneChaos for state
└── .env.example            # Credentials template (copy to .env, never commit real)
```

### Provisioning

`provisionRoom({ waiting_room, participants_hidden })` creates a real portal call as the admin fixture user, returns `{ adminUrl, guestUrl, adminToken, guestToken, roomName, cleanup }`. Every fixture registers a teardown that revokes the room afterwards regardless of test outcome. All test data is marked `[FLOWONE-TEST] livekit-chaos` so it is easily distinguished from production rows.

If you want to point the suite at a pre-existing room (e.g. for debugging a stuck participant), set `FLOWONE_GUEST_TOKEN` + `FLOWONE_ADMIN_TOKEN` in `.env` and pass `--skip-send` to the wrapper; provisioning is short-circuited.

### Chaos hooks

The `installChaosHooks(page)` helper injects an init-script that listens for `RoomEvent.{Connected, Reconnecting, Reconnected, Disconnected, ParticipantConnected, ParticipantDisconnected, DataReceived}` on the `window.flowoneCallRoom` global the `VideoCallRoom` component publishes for tests. State is exposed as `window.__flowoneChaos.getState()` for assertions; `waitForEvent(page, 'reconnected', 15_000)` blocks until the relevant event arrives or times out.

## Safety / non-destructive guarantee

- All provisioning runs against fresh portal-call rows; teardown unconditionally revokes the room.
- All test data uses the `[FLOWONE-TEST] livekit-chaos` marker.
- Gate flag (`RUN_MEETING_LIVEKIT_CHAOS=1`) prevents accidental execution.
- The PHP wrapper enforces a hard wall-clock timeout (default 1800s) and kills the Node runner if exceeded.
- Pre-flight checks (Node version, Playwright install, env vars) fail fast before any browser launches.

## Troubleshooting

- "Playwright not installed" → `cd email/backend/tests/livekit-chaos && npm install && npx playwright install chromium`
- "Node 18+ is required" → install or `nvm use 18`
- "FLOWONE_CRM_CLIENT_ID is required" → set in `.env` to a client id that the admin user can see
- Tests time out at "wait for connected" → check the deployment's LiveKit credentials and `MeetingRoomService::ensureRoom` (verbose mode shows the actual `RoomEvent` stream)
