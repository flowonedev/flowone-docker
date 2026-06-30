import Foundation
import Capacitor

/// Capacitor bridge for the native CallKit/PushKit engine (`CallManager`).
///
/// Exposes to JS (`services/callKit.js`):
///   methods: getVoipToken(), endCall({ callId })
///   events:  voipToken, incomingCall, callAnswered, callDeclined, callEnded
///
/// CallManager is started in AppDelegate (so VoIP pushes work before the
/// webview exists); this plugin just connects its event stream to JS and
/// drains anything buffered before JS attached its listeners.
@objc(CallNativePlugin)
public class CallNativePlugin: CAPPlugin, CAPBridgedPlugin {
    // Capacitor 6 bridges plugin methods via CAPBridgedPlugin. The legacy `.m`
    // CAP_PLUGIN macro alone registers the plugin name but no longer exposes its
    // methods, so without this conformance every JS call to CallNative silently
    // no-ops and the VoIP/PushKit token never reaches the backend.
    public let identifier = "CallNativePlugin"
    public let jsName = "CallNative"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "getVoipToken", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "endCall", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "setSession", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "nativeLog", returnType: CAPPluginReturnPromise),
    ]

    private func diag(_ msg: String) {
        NSLog("[CallNative] \(msg)")
        print("[CallNative] \(msg)")
    }

    override public func load() {
        diag("plugin load() — wiring eventSink + flushPending")
        CallManager.shared.eventSink = { [weak self] name, data in
            self?.notifyListeners(name, data: data)
        }
        // Deliver the token + any incoming-call/answer events that fired during
        // a cold start (push woke the app before JS booted).
        CallManager.shared.flushPending()
    }

    @objc func getVoipToken(_ call: CAPPluginCall) {
        let token = CallManager.shared.voipToken
        diag("getVoipToken() called by JS — token present=\(token != nil)")
        if let token = token {
            call.resolve(["token": token])
        } else {
            call.resolve([:])
        }
    }

    @objc func endCall(_ call: CAPPluginCall) {
        let callId = call.getString("callId") ?? ""
        CallManager.shared.endCall(callId: callId)
        call.resolve()
    }

    /// JS (services/callKit.js) hands us the live session so the native call
    /// engine can answer a call on its own while the WebView is suspended:
    /// fetch a LiveKit token, join the room, publish the mic, and signal
    /// CALL_ANSWER over the mailsync WebSocket.
    @objc func setSession(_ call: CAPPluginCall) {
        CallManager.shared.setSession(
            apiBase: call.getString("apiBase") ?? "",
            wsUrl: call.getString("wsUrl") ?? "",
            token: call.getString("token") ?? "",
            email: call.getString("email") ?? ""
        )
        call.resolve()
    }

    /// Lets JS (services/callKit.js) pipe a diagnostic line to the native log so
    /// it appears under `devicectl process launch --console` on release builds
    /// (where Safari Web Inspector can't attach).
    @objc func nativeLog(_ call: CAPPluginCall) {
        diag("JS> \(call.getString("msg") ?? "")")
        call.resolve()
    }
}
