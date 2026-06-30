package com.flowone.chat;

import android.content.Intent;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import java.util.Map;

/**
 * Android half of the CallNative plugin (mirrors the iOS CallKit bridge).
 *
 * Android has no separate VoIP push token — the full-screen call UI is driven
 * by a data-only FCM message handled in {@link CallMessagingService}, which
 * launches {@link IncomingCallActivity}. Accept/Decline there relaunch
 * {@link MainActivity} with an action extra that arrives here via
 * {@link #handleOnNewIntent}, which we forward to JS as callAnswered /
 * callDeclined.
 *
 * getVoipToken() resolves empty on Android (the server rings Android devices
 * with the regular FCM token); endCall() cancels the FSI notification/activity.
 */
@CapacitorPlugin(name = "CallNative")
public class CallNativePlugin extends Plugin {

    public static final String INTENT_ACTION_KEY = "callnative_action";
    public static final String INTENT_CALLID_KEY = "callnative_callId";

    private static CallNativePlugin instance;

    @Override
    public void load() {
        instance = this;
        // Cold start: the launching intent may already carry a call decision.
        handleCallIntent(getActivity() != null ? getActivity().getIntent() : null);
    }

    @Override
    protected void handleOnNewIntent(Intent intent) {
        super.handleOnNewIntent(intent);
        handleCallIntent(intent);
    }

    @Override
    protected void handleOnDestroy() {
        if (instance == this) instance = null;
        super.handleOnDestroy();
    }

    private void handleCallIntent(Intent intent) {
        if (intent == null) return;
        String action = intent.getStringExtra(INTENT_ACTION_KEY);
        if (action == null) return;
        String callId = intent.getStringExtra(INTENT_CALLID_KEY);
        if (callId == null) callId = "";

        // Consume so a relaunch/rotation doesn't re-fire it.
        intent.removeExtra(INTENT_ACTION_KEY);

        JSObject data = new JSObject();
        data.put("callId", callId);
        if (IncomingCallActivity.ACTION_ANSWER.equals(action)) {
            notifyListeners("callAnswered", data);
        } else if (IncomingCallActivity.ACTION_DECLINE.equals(action)) {
            notifyListeners("callDeclined", data);
        }
    }

    @PluginMethod
    public void getVoipToken(PluginCall call) {
        // Android rings via the regular FCM token; no separate VoIP token.
        call.resolve(new JSObject());
    }

    @PluginMethod
    public void endCall(PluginCall call) {
        String callId = call.getString("callId", "");
        if (getContext() != null) {
            androidx.core.app.NotificationManagerCompat.from(getContext())
                .cancel(CallMessagingService.CALL_NOTIFICATION_ID);
            Intent finish = new Intent(IncomingCallActivity.ACTION_FINISH);
            finish.setPackage(getContext().getPackageName());
            getContext().sendBroadcast(finish);
        }
        call.resolve();
    }

    // ---- Called from CallMessagingService when the webview/JS is alive ----

    /** Mirror an incoming ring into the JS call store (suppresses in-app modal). */
    static void notifyIncomingIfActive(Map<String, String> data) {
        CallNativePlugin p = instance;
        if (p == null || data == null) return;
        JSObject obj = new JSObject();
        obj.put("callId", data.get("callId"));
        obj.put("conversationId", data.get("conversationId"));
        obj.put("callType", data.get("callType"));
        obj.put("callerEmail", data.get("callerEmail"));
        obj.put("callerName", data.get("callerName"));
        p.notifyListeners("incomingCall", obj);
    }

    /** Tell JS the system ring was torn down (call cancelled/missed). */
    static void notifyEndedIfActive() {
        CallNativePlugin p = instance;
        if (p == null) return;
        p.notifyListeners("callEnded", new JSObject());
    }
}
