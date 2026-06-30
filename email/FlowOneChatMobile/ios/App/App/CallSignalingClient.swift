import Foundation

/// Minimal mailsync WebSocket client used ONLY by the native call engine while
/// the WebView is suspended (phone locked). It mirrors the JS handshake in
/// `services/mailSyncSocket.js`:
///
///   1. open the socket
///   2. send `{ type: "AUTHENTICATE", token }` as the FIRST message
///   3. wait for the server's `{ type: "CONNECTED" }` reply (auth committed)
///   4. send `{ type: "CALL_ANSWER", callId }` / `{ type: "CALL_HANGUP", callId }`
///
/// Step 3 matters: the server flips its `isAuthenticated` flag synchronously but
/// registers the client (so `clientInfo.userEmail` resolves) one `await` later —
/// a CALL_ANSWER sent too early would be attributed to an unknown user and
/// dropped. We therefore queue outbound messages until CONNECTED arrives.
final class CallSignalingClient {
    private var task: URLSessionWebSocketTask?
    private var session: URLSession?
    private var authenticated = false
    private var queued: [[String: Any]] = []
    private var onEvent: ((String, [String: Any]) -> Void)?

    private func diag(_ msg: String) {
        NSLog("[CallSignaling] \(msg)")
        print("[CallSignaling] \(msg)")
        CallDiagLog.write("CallSignaling", msg)
    }

    /// Open + authenticate. `onEvent(type, payload)` fires for inbound server
    /// messages (e.g. CALL_HANGUP / CALL_DISMISSED) so the engine can react.
    func connect(wsUrl: String, token: String,
                 onEvent: @escaping (String, [String: Any]) -> Void) {
        close()
        guard !wsUrl.isEmpty, let url = URL(string: wsUrl) else {
            diag("connect skipped — empty/invalid wsUrl '\(wsUrl)'")
            return
        }
        self.onEvent = onEvent
        let s = URLSession(configuration: .default)
        let t = s.webSocketTask(with: url)
        session = s
        task = t
        t.resume()
        diag("connecting to \(url.absoluteString)")
        rawSend(["type": "AUTHENTICATE", "token": token])
        receiveLoop()
    }

    /// Queue (or send, once authenticated) a signaling message.
    func send(_ obj: [String: Any]) {
        let kind = (obj["type"] as? String) ?? "?"
        if authenticated {
            diag("send \(kind) (authenticated)")
            rawSend(obj)
        } else {
            diag("queue \(kind) (not yet authenticated)")
            queued.append(obj)
        }
    }

    /// Send a FINAL frame, then close the socket only AFTER it has flushed over
    /// the wire (the send completion fires). A plain `send` + `close` races: the
    /// close cancels the still-queued frame, so a CALL_HANGUP never reaches the
    /// server and the remote party (PC) is stranded in a dead call. Falls back
    /// to an immediate close when there's nothing we can flush (no task / not
    /// authenticated / encode failure).
    func sendFinal(_ obj: [String: Any]) {
        let kind = (obj["type"] as? String) ?? "?"
        guard authenticated, let task = task,
              let data = try? JSONSerialization.data(withJSONObject: obj),
              let str = String(data: data, encoding: .utf8) else {
            diag("sendFinal \(kind) — cannot flush (authenticated=\(authenticated)); closing now")
            close()
            return
        }
        diag("sendFinal \(kind) — flush then close")
        task.send(.string(str)) { [weak self] err in
            if let err = err { self?.diag("sendFinal error: \(err.localizedDescription)") }
            self?.close()
        }
    }

    func close() {
        task?.cancel(with: .goingAway, reason: nil)
        task = nil
        session?.invalidateAndCancel()
        session = nil
        authenticated = false
        queued.removeAll()
        onEvent = nil
    }

    // MARK: - Private

    private func rawSend(_ obj: [String: Any]) {
        guard let task = task,
              let data = try? JSONSerialization.data(withJSONObject: obj),
              let str = String(data: data, encoding: .utf8) else { return }
        task.send(.string(str)) { [weak self] err in
            if let err = err { self?.diag("send error: \(err.localizedDescription)") }
        }
    }

    private func receiveLoop() {
        task?.receive { [weak self] result in
            guard let self = self else { return }
            switch result {
            case .failure(let err):
                self.diag("receive ended: \(err.localizedDescription)")
            case .success(let message):
                self.handle(message)
                self.receiveLoop()
            }
        }
    }

    private func handle(_ message: URLSessionWebSocketTask.Message) {
        guard case let .string(text) = message,
              let data = text.data(using: .utf8),
              let obj = try? JSONSerialization.jsonObject(with: data) as? [String: Any]
        else { return }
        let type = (obj["type"] as? String) ?? ""
        diag("recv \(type)")
        if type == "ERROR" {
            let p = (obj["payload"] as? [String: Any]) ?? [:]
            diag("server ERROR code=\((p["code"] as? String) ?? "?") msg=\((p["message"] as? String) ?? "?")")
        }
        if type == "CONNECTED", !authenticated {
            authenticated = true
            let pending = queued
            queued.removeAll()
            diag("authenticated — flushing \(pending.count) queued message(s)")
            for m in pending { rawSend(m) }
            return
        }
        let payload = (obj["payload"] as? [String: Any]) ?? obj
        onEvent?(type, payload)
    }
}
