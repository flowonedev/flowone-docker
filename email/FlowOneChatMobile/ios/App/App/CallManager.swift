import Foundation
import PushKit
import CallKit
import AVFoundation
import UIKit

/// Native incoming-call engine for the Chat app.
///
/// Owns the PushKit VoIP registry and the CallKit provider so the system
/// full-screen call screen rings even when the app is killed or backgrounded.
/// It is intentionally decoupled from Capacitor: `AppDelegate` starts it on
/// launch (PushKit pushes can arrive before the webview/JS exists), it buffers
/// the VoIP token + call events, and `CallNativePlugin` attaches later to drain
/// them into JS and to drive `endCall`.
///
/// Apple rule honored here: EVERY VoIP push must report a call to CallKit or
/// iOS will throttle/kill the app — so even an `end_call` push reports then
/// immediately ends a call.
@objc public class CallManager: NSObject {
    @objc public static let shared = CallManager()

    private var voipRegistry: PKPushRegistry?
    private var provider: CXProvider?
    private let callController = CXCallController()

    /// callId <-> CallKit UUID, and whether a given call has been answered.
    private var uuidByCallId: [String: UUID] = [:]
    private var callIdByUuid: [UUID: String] = [:]
    private var answeredUuids: Set<UUID> = []

    /// Full incoming-call info by callId. The CXAnswerCallAction only carries
    /// the CallKit UUID, so we stash the push payload here and hand it to the
    /// webview on answer — the JS can then join the call straight from the push
    /// WITHOUT waiting for the WS CALL_INITIATE, which never arrives once
    /// CallKit has backgrounded the app and suspended its socket.
    private var infoByCallId: [String: [String: Any]] = [:]

    /// Latest VoIP token (hex), forwarded to JS when the plugin attaches.
    private(set) var voipToken: String?

    /// Event sink — set by CallNativePlugin.load(). Until then events buffer.
    var eventSink: ((String, [String: Any]) -> Void)?
    private var pending: [(String, [String: Any])] = []

    private static let providerConfig: CXProviderConfiguration = {
        let config = CXProviderConfiguration(localizedName: "FlowOne Chat")
        config.supportsVideo = true
        config.maximumCallGroups = 1
        config.maximumCallsPerCallGroup = 1
        config.supportedHandleTypes = [.generic]
        return config
    }()

    /// Dual-log to both the unified log (Console.app) and stdout so it also
    /// shows under `devicectl process launch --console`.
    private func diag(_ msg: String) {
        NSLog("[CallManager] \(msg)")
        print("[CallManager] \(msg)")
        CallDiagLog.write("CallManager", msg)
    }

    /// Start PushKit + CallKit. Safe to call multiple times.
    @objc public func start() {
        diag("start() called — setting up CallKit provider + PushKit VoIP registry")
        if provider == nil {
            let p = CXProvider(configuration: CallManager.providerConfig)
            p.setDelegate(self, queue: nil)
            provider = p
        }
        // Prepare the native LiveKit engine (manual WebRTC audio so it cooperates
        // with CallKit) and let it dismiss the CallKit UI when the server/remote
        // ends a call native owns.
        NativeCallEngine.shared.configureForCallKit()
        NativeCallEngine.shared.onRemoteEnd = { [weak self] callId in
            DispatchQueue.main.async { self?.endCall(callId: callId) }
        }
        if voipRegistry == nil {
            let registry = PKPushRegistry(queue: .main)
            registry.delegate = self
            registry.desiredPushTypes = [.voIP]
            voipRegistry = registry
            diag("PKPushRegistry created, desiredPushTypes=[.voIP]; awaiting didUpdate token")
        } else {
            diag("PKPushRegistry already exists; current voipToken present=\(voipToken != nil)")
        }
    }

    // MARK: - Plugin bridge

    /// Drain buffered events into JS once the plugin is attached.
    func flushPending() {
        guard let sink = eventSink else { return }
        if let token = voipToken { sink("voipToken", ["token": token]) }
        let buffered = pending
        pending.removeAll()
        for (name, data) in buffered { sink(name, data) }
    }

    private func emit(_ name: String, _ data: [String: Any]) {
        if let sink = eventSink {
            sink(name, data)
        } else {
            pending.append((name, data))
        }
    }

    // MARK: - UUID mapping

    private func uuid(for callId: String) -> UUID {
        if let existing = uuidByCallId[callId] { return existing }
        let u = UUID()
        uuidByCallId[callId] = u
        callIdByUuid[u] = callId
        return u
    }

    private func forget(_ uuid: UUID) {
        if let callId = callIdByUuid[uuid] {
            uuidByCallId.removeValue(forKey: callId)
            infoByCallId.removeValue(forKey: callId)
        }
        callIdByUuid.removeValue(forKey: uuid)
        answeredUuids.remove(uuid)
    }

    // MARK: - Incoming / cancel

    /// Present (or, for an end payload, tear down) a call from a VoIP push.
    private func handlePush(_ payload: [AnyHashable: Any], completion: (() -> Void)?) {
        let type = (payload["type"] as? String) ?? "incoming_call"
        let callId = (payload["callId"] as? String) ?? UUID().uuidString
        diag("handlePush type=\(type) callId=\(callId)")

        if type == "end_call" {
            let reason = (payload["reason"] as? String) ?? ""
            // "answered_elsewhere" tells a user's OTHER devices to stop ringing
            // after they picked up on one device. The server fans this dismiss
            // out to ALL the user's VoIP tokens, so it also lands on the device
            // that actually answered — which would tear the live call down
            // ~0.3s after pickup. If WE answered this call, the dismiss is not
            // for us: ignore it (but still satisfy PushKit's completion).
            if let answered = uuidByCallId[callId],
               answeredUuids.contains(answered),
               reason == "answered_elsewhere" {
                diag("end_call(answered_elsewhere) for call WE answered \(callId) — ignoring")
                completion?()
                return
            }
            // Apple rule: EVERY VoIP push must call reportNewIncomingCall, or iOS
            // terminates the app and THROTTLES future VoIP delivery (incoming
            // calls then silently stop ringing). A cancel push is the dangerous
            // case because it carries no call to show.
            if let existing = uuidByCallId[callId] {
                // The call is already on screen (app was alive when it rang) —
                // iOS is lenient here, so just dismiss it. No phantom call entry.
                diag("end_call push for on-screen call \(callId) — dismissing")
                provider?.reportCall(with: existing, endedAt: Date(), reason: .remoteEnded)
                forget(existing)
                completion?()
            } else {
                // We were likely woken from a killed state by this cancel push for
                // a call we never presented. We MUST still report a call for THIS
                // push, then immediately end it, or iOS throttles us.
                let u = uuid(for: callId)
                let update = CXCallUpdate()
                update.remoteHandle = CXHandle(type: .generic,
                                               value: (payload["callerName"] as? String) ?? "Call")
                diag("end_call push for unknown call \(callId) — report+end to satisfy PushKit")
                provider?.reportNewIncomingCall(with: u, update: update) { [weak self] error in
                    if let error = error {
                        NSLog("[CallManager] end_call report failed: \(error.localizedDescription)")
                    }
                    self?.provider?.reportCall(with: u, endedAt: Date(), reason: .remoteEnded)
                    self?.forget(u)
                    completion?()
                }
            }
            return
        }

        let callType = (payload["callType"] as? String) ?? "voice"
        let callerName = (payload["callerName"] as? String)
            ?? (payload["callerEmail"] as? String)
            ?? "Unknown"

        // Stash the full call info so the Answer action can hand it to JS.
        let callInfo: [String: Any] = [
            "callId": callId,
            "conversationId": payload["conversationId"] as? String ?? "",
            "callType": callType,
            "callerEmail": payload["callerEmail"] as? String ?? "",
            "callerName": callerName
        ]
        infoByCallId[callId] = callInfo

        let u = uuid(for: callId)
        let update = CXCallUpdate()
        update.remoteHandle = CXHandle(type: .generic, value: callerName)
        update.hasVideo = (callType == "video")
        update.localizedCallerName = callerName
        update.supportsHolding = false
        update.supportsGrouping = false
        update.supportsUngrouping = false

        diag("reporting new incoming call to CallKit: callId=\(callId) callType=\(callType)")
        provider?.reportNewIncomingCall(with: u, update: update) { [weak self] error in
            if let error = error {
                NSLog("[CallManager] reportNewIncomingCall FAILED: \(error.localizedDescription)")
                self?.forget(u)
            } else {
                self?.diag("reportNewIncomingCall OK — CallKit should present \(callId)")
                self?.emit("incomingCall", callInfo)
            }
            completion?()
        }
    }

    /// Tear down the system call UI for a call (server cancel / answered
    /// elsewhere / hung up in-app). Reports remote-ended so CallKit dismisses.
    @objc public func endCall(callId: String) {
        guard !callId.isEmpty else {
            // Empty id => end the only/last known call.
            if let any = callIdByUuid.keys.first {
                provider?.reportCall(with: any, endedAt: Date(), reason: .remoteEnded)
                forget(any)
            }
            return
        }
        let u = uuid(for: callId)
        provider?.reportCall(with: u, endedAt: Date(), reason: .remoteEnded)
        forget(u)
    }

    /// JS (services/callKit.js) hands us the live session so the native engine
    /// can answer a call on its own while the WebView is suspended (phone locked).
    @objc public func setSession(apiBase: String, wsUrl: String, token: String, email: String) {
        let s = NativeSession(apiBase: apiBase, wsUrl: wsUrl, token: token, email: email)
        diag("setSession apiBase=\(apiBase) wsUrl=\(wsUrl.isEmpty ? "EMPTY" : "set") email=\(email) valid=\(s.isValid)")
        NativeCallEngine.shared.setSession(s)
    }
}

// MARK: - PKPushRegistryDelegate

extension CallManager: PKPushRegistryDelegate {
    public func pushRegistry(_ registry: PKPushRegistry,
                             didUpdate pushCredentials: PKPushCredentials,
                             for type: PKPushType) {
        guard type == .voIP else { return }
        let token = pushCredentials.token.map { String(format: "%02x", $0) }.joined()
        voipToken = token
        diag("didUpdate VoIP token received (\(token.count) hex chars): \(token.prefix(16))…")
        emit("voipToken", ["token": token])
    }

    public func pushRegistry(_ registry: PKPushRegistry,
                             didInvalidatePushTokenFor type: PKPushType) {
        if type == .voIP {
            voipToken = nil
            diag("didInvalidate VoIP token")
        }
    }

    // iOS 11+ signature with completion — we MUST report a call before returning.
    public func pushRegistry(_ registry: PKPushRegistry,
                             didReceiveIncomingPushWith payload: PKPushPayload,
                             for type: PKPushType,
                             completion: @escaping () -> Void) {
        diag("didReceiveIncomingPush — type=\(type.rawValue) keys=\(Array(payload.dictionaryPayload.keys).map { "\($0)" })")
        guard type == .voIP else { completion(); return }
        handlePush(payload.dictionaryPayload, completion: completion)
    }
}

// MARK: - CXProviderDelegate

extension CallManager: CXProviderDelegate {
    public func providerDidReset(_ provider: CXProvider) {
        uuidByCallId.removeAll()
        callIdByUuid.removeAll()
        answeredUuids.removeAll()
    }

    public func provider(_ provider: CXProvider, perform action: CXAnswerCallAction) {
        let callId = callIdByUuid[action.callUUID] ?? ""
        answeredUuids.insert(action.callUUID)
        // Hand JS the FULL call info from the push (conversationId/callType/
        // caller) so it can bootstrap + join the call immediately — it must NOT
        // wait for a WS CALL_INITIATE, which never arrives once CallKit has
        // backgrounded the app. See stores/call.js acceptFromNative.
        var info = infoByCallId[callId] ?? [:]
        info["callId"] = callId
        // Always buffer the answer for JS — when the app is (or becomes)
        // foreground, the WebView replays this and joins the same LiveKit
        // identity, seamlessly taking over from the native engine below.
        emit("callAnswered", info)

        // If the app isn't foreground, the WebView is suspended and CANNOT
        // capture the mic (iOS blocks WebView mic in the background). Answer
        // natively instead: the LiveKit Swift engine connects + publishes the
        // mic + signals CALL_ANSWER, so a call answered on the lock screen
        // connects with two-way audio without waiting for an unlock.
        let appActive = UIApplication.shared.applicationState == .active
        diag("CXAnswerCallAction callId=\(callId) appActive=\(appActive) hasSession=\(NativeCallEngine.shared.hasSession) hasInfo=\(infoByCallId[callId] != nil)")
        if !appActive && NativeCallEngine.shared.hasSession {
            NativeCallEngine.shared.answer(callId: callId)
        }
        action.fulfill()
        // Best-effort foreground: succeeds only when the device is unlocked. When
        // it does, the WebView resumes and joins the same identity — which the
        // server replaces, disconnecting the native participant (hand-off).
        bringAppToForeground(callId: callId)
    }

    /// Bring the Chat app to the foreground by opening our own registered URL
    /// scheme (Info.plist CFBundleURLSchemes: `flowone-chat`). There is no public
    /// API to force-foreground from the background; opening a URL that resolves
    /// to this app is the documented CallKit pattern. Must run on the main thread.
    private func bringAppToForeground(callId: String) {
        let encoded = callId.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? ""
        guard let url = URL(string: "flowone-chat://call/answer?callId=\(encoded)") else { return }
        DispatchQueue.main.async {
            UIApplication.shared.open(url, options: [:]) { ok in
                NSLog("[CallManager] foreground-on-answer open(\(url)) -> \(ok)")
            }
        }
    }

    public func provider(_ provider: CXProvider, perform action: CXEndCallAction) {
        let callId = callIdByUuid[action.callUUID] ?? ""
        let wasAnswered = answeredUuids.contains(action.callUUID)
        let appActive = UIApplication.shared.applicationState == .active
        if NativeCallEngine.shared.owns(callId) {
            // Native engine owns the live call (answered while locked): tear down
            // its LiveKit connection and tell the server we hung up.
            NativeCallEngine.shared.end(callId: callId, sendHangup: true)
        } else if !wasAnswered && !appActive {
            // Decline before answer while the app is backgrounded/locked: the
            // WebView is suspended and the buffered `callDeclined` below won't be
            // processed, so the caller would ring until the 30s timeout. Signal
            // CALL_REJECT natively. When the app IS foreground, JS handles the
            // reject from the buffered event instead (avoids a double send).
            NativeCallEngine.shared.reject(callId: callId, reason: "declined")
        }
        // Distinguish a user decline (never answered) from a normal hang-up.
        let event = wasAnswered ? "callEnded" : "callDeclined"
        emit(event, ["callId": callId])
        forget(action.callUUID)
        action.fulfill()
    }

    public func provider(_ provider: CXProvider, perform action: CXSetMutedCallAction) {
        // Apply to the native mic when native owns the call; also forward to JS
        // so the in-app UI reflects the mute after a hand-off.
        NativeCallEngine.shared.setMuted(action.isMuted)
        emit("callMuted", ["muted": action.isMuted])
        action.fulfill()
    }

    public func provider(_ provider: CXProvider, didActivate audioSession: AVAudioSession) {
        NSLog("[CallManager] audio session activated")
        // Hand the activated session to the native WebRTC engine (no-op for
        // WebView-owned calls — its WebRTC runs in a separate process).
        NativeCallEngine.shared.audioSessionActivated(audioSession)
    }

    public func provider(_ provider: CXProvider, didDeactivate audioSession: AVAudioSession) {
        NSLog("[CallManager] audio session deactivated")
        NativeCallEngine.shared.audioSessionDeactivated(audioSession)
    }
}
