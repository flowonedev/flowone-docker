import Foundation
import AVFoundation
import LiveKitClient
import LiveKitWebRTC

/// Everything the native engine needs to join a call on its own. Pushed from JS
/// (`services/callKit.js` -> `CallNative.setSession`) and persisted so it also
/// survives a cold start (app killed, woken by a VoIP push).
struct NativeSession {
    let apiBase: String   // e.g. https://email.flowone.pro/api
    let wsUrl: String     // mailsync WebSocket (wss://email.flowone.pro/...)
    let token: String     // auth JWT (Bearer)
    let email: String     // LiveKit participant identity (must match the WebView)

    /// A session is only usable if BOTH endpoints are absolute. A half-baked
    /// session (e.g. JS pushed before the per-deployment base resolved -> apiBase
    /// "/api", wsUrl "") used to pass this check, so native would "answer" but
    /// silently never signal CALL_ANSWER (empty wsUrl) — the caller kept ringing.
    var isValid: Bool {
        apiBase.hasPrefix("http") && wsUrl.hasPrefix("ws")
            && !token.isEmpty && !email.isEmpty
    }

    /// Best-effort decode of the JWT `exp` claim (seconds since epoch). nil when
    /// the token isn't a parseable JWT — in that case we do NOT treat it as
    /// expired and fall through to the live connect/auth error handling.
    var tokenExpiry: Date? {
        let parts = token.split(separator: ".")
        guard parts.count >= 2 else { return nil }
        var b64 = String(parts[1])
            .replacingOccurrences(of: "-", with: "+")
            .replacingOccurrences(of: "_", with: "/")
        while b64.count % 4 != 0 { b64 += "=" }
        guard let data = Data(base64Encoded: b64),
              let obj = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let exp = obj["exp"] as? Double else { return nil }
        return Date(timeIntervalSince1970: exp)
    }

    /// Expired when we could read an `exp` and it's within 30s of now. JS
    /// re-pushes a fresh session on resume / token refresh, but a long
    /// background sleep can still leave the persisted JWT stale — answering or
    /// rejecting with it would fail LiveKit token fetch + WS auth (caller keeps
    /// ringing), so we skip the native path and let the in-app WebView handle it
    /// once the user unlocks.
    var isExpired: Bool {
        guard let exp = tokenExpiry else { return false }
        return exp.timeIntervalSinceNow < 30
    }

    /// Structurally valid AND not (knowably) expired — the bar for the native
    /// engine to act on its own.
    var isUsable: Bool { isValid && !isExpired }
}

/// Native LiveKit call engine. Answers a call entirely in the background while
/// the WebView is suspended (phone locked): fetches a LiveKit token over HTTP,
/// joins the room, publishes the mic, and plays remote audio. It also signals
/// `CALL_ANSWER` to the server over its own WebSocket so the caller stops
/// ringing immediately.
///
/// Why this exists: the in-app call stack runs inside WKWebView, and iOS refuses
/// WebView microphone capture unless the WebView is foreground — so a call
/// answered on the lock screen never connected until the user unlocked. A native
/// WebRTC stack (this) CAN capture the mic in the background during a CallKit
/// call, using CallKit's activated audio session.
///
/// Hand-off: native and the WebView both join LiveKit as the SAME identity (the
/// user's email). When the app is later foregrounded the WebView joins the same
/// room, the server replaces the duplicate identity, this native participant is
/// disconnected (see `room(_:didDisconnectWithError:)`), and we release our
/// resources — the live call continues seamlessly in the WebView.
final class NativeCallEngine: NSObject {
    @objc static let shared = NativeCallEngine()

    private var room: Room?
    private let signaling = CallSignalingClient()
    private var session: NativeSession?

    private(set) var activeCallId: String?
    private var roomConnected = false
    private var audioActive = false
    private var muted = false

    /// Short-lived signaling clients used for a fire-and-forget CALL_REJECT from a
    /// lock-screen decline (there is no active call to attach to). Retained until
    /// the reject has had time to authenticate + flush, then released.
    private var rejectClients: [CallSignalingClient] = []

    /// Invoked when the server (or remote) ends a call this engine owns, so
    /// CallManager can dismiss the CallKit UI. callId is passed back.
    var onRemoteEnd: ((String) -> Void)?

    private let defaultsKey = "flowone.nativeCallSession"

    private func diag(_ msg: String) {
        NSLog("[NativeCallEngine] \(msg)")
        print("[NativeCallEngine] \(msg)")
        CallDiagLog.write("NativeCallEngine", msg)
    }

    // MARK: - Launch config

    /// Take manual control of the WebRTC audio session so it cooperates with
    /// CallKit instead of racing it: the audio unit stays gated until CallKit
    /// activates the session (`audioSessionActivated`). Call once at launch.
    /// Also restores any persisted session so a cold-start (push-woken) answer
    /// has credentials before JS has had a chance to run.
    func configureForCallKit() {
        let rtcSession = LKRTCAudioSession.sharedInstance()
        rtcSession.useManualAudio = true
        rtcSession.isAudioEnabled = false
        loadPersistedSession()
        diag("configured: WebRTC manual audio ON; persistedSession=\(session?.isValid == true)")
    }

    func setSession(_ s: NativeSession) {
        session = s
        persistSession(s)
    }

    var hasSession: Bool { session?.isUsable == true }

    // MARK: - Answer

    /// Begin answering `callId` natively. Signals CALL_ANSWER immediately (so the
    /// caller stops ringing) and connects to LiveKit. The mic is enabled once the
    /// CallKit audio session is active (see `audioSessionActivated`).
    func answer(callId: String) {
        guard let session = session, session.isUsable else {
            diag("answer(\(callId)) skipped — no usable session (valid=\(session?.isValid ?? false) expired=\(session?.isExpired ?? true))")
            return
        }
        guard activeCallId != callId else {
            diag("answer(\(callId)) ignored — already active")
            return
        }
        activeCallId = callId
        muted = false
        roomConnected = false

        // 1) Tell the server we answered (caller stops ringing). The WS gates the
        //    answer until it is authenticated (CONNECTED), so this is race-safe.
        signaling.connect(wsUrl: session.wsUrl, token: session.token) { [weak self] type, _ in
            guard let self = self, let active = self.activeCallId else { return }
            // Only a genuine remote hang-up ends a call we ACTIVELY own. Do NOT
            // tear down on CALL_DISMISSED: the server fans that out to a user's
            // OTHER devices to stop them RINGING (reasons answered_/rejected_/
            // hung_up_elsewhere) and it also lands on this socket — the very one
            // that just answered. Treating our own "answered_elsewhere" dismiss
            // as an end was killing the call for everyone the instant we picked
            // up. (The web client guards the same way.) CALL_REJECT likewise
            // never targets the device that's already in the call.
            if type == "CALL_HANGUP" {
                self.diag("server CALL_HANGUP — ending native call \(active)")
                let id = active
                self.teardown()
                self.onRemoteEnd?(id)
            }
        }
        signaling.send(["type": "CALL_ANSWER", "callId": callId])

        // 2) Join LiveKit (token fetch + connect are async).
        Task { await self.joinRoom(callId: callId, session: session) }
    }

    private func joinRoom(callId: String, session: NativeSession) async {
        do {
            let creds = try await fetchToken(callId: callId, session: session)
            // Bail if the call was torn down while the token request was in flight.
            guard activeCallId == callId else {
                diag("joinRoom aborted — call \(callId) no longer active")
                return
            }
            let room = Room(delegate: self)
            self.room = room
            try await room.connect(url: creds.wsUrl, token: creds.token)
            roomConnected = true
            diag("LiveKit connected room=\(callId)")
            await enableMicIfReady()
        } catch {
            diag("joinRoom failed: \(error.localizedDescription)")
        }
    }

    /// Enable the mic only when BOTH the room is connected AND CallKit's audio
    /// session is active — starting the audio engine outside that window errors.
    private func enableMicIfReady() async {
        guard roomConnected, audioActive, let room = room else { return }
        do {
            try await room.localParticipant.setMicrophone(enabled: !muted)
            diag("microphone enabled (muted=\(muted))")
        } catch {
            diag("setMicrophone failed: \(error.localizedDescription)")
        }
    }

    // MARK: - CallKit audio-session bridge

    /// CallKit activated the shared audio session. Hand it to WebRTC and unblock
    /// the audio unit, then publish the mic if the room is already connected.
    /// No-op unless the native engine owns this call — a WebView-owned call (app
    /// in the foreground) runs its WebRTC in a separate process and must not be
    /// disturbed.
    func audioSessionActivated(_ audioSession: AVAudioSession) {
        guard activeCallId != nil else {
            diag("audio session activated — ignored (WebView owns the call)")
            return
        }
        let rtcSession = LKRTCAudioSession.sharedInstance()
        rtcSession.audioSessionDidActivate(audioSession)
        rtcSession.isAudioEnabled = true
        audioActive = true
        diag("audio session activated — WebRTC audio enabled (activeCall=\(activeCallId ?? "none"))")
        Task { await enableMicIfReady() }
    }

    /// CallKit deactivated the session.
    func audioSessionDeactivated(_ audioSession: AVAudioSession) {
        guard audioActive else { return }
        let rtcSession = LKRTCAudioSession.sharedInstance()
        rtcSession.isAudioEnabled = false
        rtcSession.audioSessionDidDeactivate(audioSession)
        audioActive = false
        diag("audio session deactivated — WebRTC audio disabled")
    }

    // MARK: - CallKit controls

    func setMuted(_ value: Bool) {
        muted = value
        guard roomConnected, let room = room else { return }
        Task {
            do {
                try await room.localParticipant.setMicrophone(enabled: !value)
                diag("setMuted(\(value)) applied")
            } catch {
                diag("setMuted failed: \(error.localizedDescription)")
            }
        }
    }

    /// End the native call (CallKit End tapped, or remote teardown). `sendHangup`
    /// posts CALL_HANGUP for a user-initiated end. No-op if native doesn't own
    /// this call (the WebView may have taken over).
    func end(callId: String, sendHangup: Bool) {
        guard let active = activeCallId, active == callId || callId.isEmpty else {
            return
        }
        if sendHangup {
            // Flush CALL_HANGUP, THEN close the socket (sendFinal owns the close).
            // Tearing down the media now is fine, but we must NOT close the
            // signaling socket here — doing so would cancel the in-flight
            // CALL_HANGUP and the remote party (PC) would never hang up.
            signaling.sendFinal(["type": "CALL_HANGUP", "callId": active])
            teardownMedia()
        } else {
            teardown()
        }
    }

    /// True while native currently owns `callId` (used by CallManager to decide
    /// whether End/Mute should be routed to native).
    func owns(_ callId: String) -> Bool {
        guard let active = activeCallId else { return false }
        return active == callId || callId.isEmpty
    }

    /// Decline a ringing call from the lock screen (Decline tapped before the
    /// call was ever answered). The WebView is suspended and won't run JS, so we
    /// MUST tell the server natively — otherwise the caller keeps ringing until
    /// the 30s no-answer timeout. Fire-and-forget: open a short-lived signaling
    /// socket, send CALL_REJECT once authenticated, then close it.
    func reject(callId: String, reason: String = "declined") {
        guard let session = session, session.isUsable else {
            diag("reject(\(callId)) skipped — no usable session (valid=\(session?.isValid ?? false) expired=\(session?.isExpired ?? true))")
            return
        }
        guard activeCallId == nil else {
            diag("reject(\(callId)) skipped — a call is active (use end instead)")
            return
        }
        guard !callId.isEmpty else {
            diag("reject skipped — empty callId")
            return
        }
        diag("reject(\(callId)) reason=\(reason) — signaling CALL_REJECT natively")
        let client = CallSignalingClient()
        rejectClients.append(client)
        client.connect(wsUrl: session.wsUrl, token: session.token) { _, _ in }
        client.send(["type": "CALL_REJECT", "callId": callId, "reason": reason])
        // A reject is not acked. Give the socket time to authenticate (await the
        // server CONNECTED) and flush the queued CALL_REJECT, then tear it down.
        DispatchQueue.main.asyncAfter(deadline: .now() + 5) { [weak self, weak client] in
            client?.close()
            if let client = client {
                self?.rejectClients.removeAll { $0 === client }
            }
        }
    }

    // MARK: - Private

    private func teardown() {
        teardownMedia()
        signaling.close()
    }

    /// Tear down the LiveKit room + audio and reset call state, WITHOUT touching
    /// the signaling socket. The hangup path (`end(sendHangup:)`) needs to flush
    /// CALL_HANGUP and close the socket itself (see `sendFinal`), so it calls
    /// this instead of `teardown()` to avoid cancelling the in-flight frame.
    private func teardownMedia() {
        let r = room
        room = nil
        roomConnected = false
        audioActive = false
        activeCallId = nil
        muted = false
        let rtcSession = LKRTCAudioSession.sharedInstance()
        rtcSession.isAudioEnabled = false
        Task { await r?.disconnect() }
    }

    // MARK: - Token fetch

    private struct Creds { let token: String; let wsUrl: String }

    private func fetchToken(callId: String, session: NativeSession) async throws -> Creds {
        guard let url = URL(string: session.apiBase + "/call/livekit-token") else {
            throw NSError(domain: "NativeCallEngine", code: 1,
                          userInfo: [NSLocalizedDescriptionKey: "bad apiBase"])
        }
        var req = URLRequest(url: url)
        req.httpMethod = "POST"
        req.timeoutInterval = 15
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.setValue("Bearer \(session.token)", forHTTPHeaderField: "Authorization")
        let displayName = session.email.split(separator: "@").first.map(String.init) ?? session.email
        req.httpBody = try JSONSerialization.data(withJSONObject: [
            "room_name": callId,
            "display_name": displayName,
        ])
        let (data, resp) = try await URLSession.shared.data(for: req)
        guard let http = resp as? HTTPURLResponse, (200..<300).contains(http.statusCode) else {
            let code = (resp as? HTTPURLResponse)?.statusCode ?? -1
            throw NSError(domain: "NativeCallEngine", code: 2,
                          userInfo: [NSLocalizedDescriptionKey: "token http \(code)"])
        }
        guard let obj = try JSONSerialization.jsonObject(with: data) as? [String: Any],
              let d = obj["data"] as? [String: Any],
              let token = d["token"] as? String,
              let wsUrl = d["ws_url"] as? String else {
            throw NSError(domain: "NativeCallEngine", code: 3,
                          userInfo: [NSLocalizedDescriptionKey: "token parse error"])
        }
        return Creds(token: token, wsUrl: wsUrl)
    }

    // MARK: - Session persistence (cold-start)

    private func persistSession(_ s: NativeSession) {
        UserDefaults.standard.set([
            "apiBase": s.apiBase, "wsUrl": s.wsUrl, "token": s.token, "email": s.email,
        ], forKey: defaultsKey)
    }

    private func loadPersistedSession() {
        guard session == nil,
              let d = UserDefaults.standard.dictionary(forKey: defaultsKey) else { return }
        let restored = NativeSession(
            apiBase: d["apiBase"] as? String ?? "",
            wsUrl: d["wsUrl"] as? String ?? "",
            token: d["token"] as? String ?? "",
            email: d["email"] as? String ?? ""
        )
        if restored.isValid { session = restored }
    }
}

// MARK: - RoomDelegate

// MARK: - On-device diagnostics

/// Appends timestamped diagnostics to a file inside the app container
/// (`Documents/callkit-diag.log`) so the locked-screen call flow — which never
/// surfaces in `idevicesyslog` and drops out of `devicectl --console` the moment
/// the screen locks — can be pulled off the device AFTER the fact with:
///
///   xcrun devicectl device copy from --device <udid> \
///     --domain-type appDataContainer --domain-identifier com.flowone.chat \
///     --source Documents/callkit-diag.log --destination ./callkit-diag.log
///
/// The file self-trims so it can never grow without bound.
enum CallDiagLog {
    private static let queue = DispatchQueue(label: "flowone.calldiag")
    private static let maxBytes = 256 * 1024

    private static var fileURL: URL? = {
        FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)
            .first?.appendingPathComponent("callkit-diag.log")
    }()

    private static let stamp: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "HH:mm:ss.SSS"
        return f
    }()

    static func write(_ tag: String, _ msg: String) {
        let line = "\(stamp.string(from: Date())) [\(tag)] \(msg)\n"
        queue.async {
            guard let url = fileURL, let data = line.data(using: .utf8) else { return }
            let fm = FileManager.default
            if let attrs = try? fm.attributesOfItem(atPath: url.path),
               let size = attrs[.size] as? Int, size > maxBytes {
                try? fm.removeItem(at: url)
            }
            if let fh = try? FileHandle(forWritingTo: url) {
                fh.seekToEndOfFile()
                fh.write(data)
                try? fh.close()
            } else {
                try? data.write(to: url)
            }
        }
    }
}

// MARK: - RoomDelegate

extension NativeCallEngine: RoomDelegate {
    func roomDidConnect(_ room: Room) {
        diag("roomDidConnect")
    }

    /// Disconnected after a successful connect. The common cause here is the
    /// hand-off: the WebView joined the same identity and the server replaced us.
    /// Release native resources; the live call continues in the WebView (we do
    /// NOT end the CallKit call — JS owns its lifecycle now).
    func room(_ room: Room, didDisconnectWithError error: LiveKitError?) {
        diag("room disconnected (error=\(error?.localizedDescription ?? "none")) — likely WebView hand-off; cleaning up native side")
        teardown()
    }
}
