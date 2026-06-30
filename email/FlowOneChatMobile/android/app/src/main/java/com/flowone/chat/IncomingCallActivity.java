package com.flowone.chat;

import android.app.KeyguardManager;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.os.Build;
import android.os.Bundle;
import android.view.WindowManager;
import android.widget.TextView;

import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.NotificationManagerCompat;

/**
 * Full-screen incoming-call screen shown over the lock screen (the Android
 * counterpart of iOS CallKit). Launched by {@link CallMessagingService} via a
 * full-screen intent. Accept/Decline relaunch {@link MainActivity} with an
 * action extra so the {@code CallNative} plugin can forward the decision to the
 * JS call store (join / reject the LiveKit call).
 */
public class IncomingCallActivity extends AppCompatActivity {

    public static final String EXTRA_ACTION = "callnative_extra_action";
    public static final String ACTION_ANSWER = "answer";
    public static final String ACTION_DECLINE = "decline";
    public static final String ACTION_FINISH = "com.flowone.chat.FINISH_CALL";

    private String callId;
    private String conversationId;
    private String callType;
    private BroadcastReceiver finishReceiver;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        showOverLockScreen();

        Intent intent = getIntent();
        callId = intent.getStringExtra(CallMessagingService.EXTRA_CALL_ID);
        conversationId = intent.getStringExtra(CallMessagingService.EXTRA_CONVERSATION_ID);
        callType = intent.getStringExtra(CallMessagingService.EXTRA_CALL_TYPE);

        // If launched directly by a notification action button, act and exit.
        String preAction = intent.getStringExtra(EXTRA_ACTION);
        if (ACTION_ANSWER.equals(preAction)) { answer(); return; }
        if (ACTION_DECLINE.equals(preAction)) { decline(); return; }

        setContentView(R.layout.activity_incoming_call);

        String callerName = intent.getStringExtra(CallMessagingService.EXTRA_CALLER_NAME);
        if (callerName == null || callerName.isEmpty()) callerName = "Incoming call";
        ((TextView) findViewById(R.id.caller_name)).setText(callerName);
        ((TextView) findViewById(R.id.call_subtitle))
            .setText("video".equals(callType) ? "Incoming video call" : "Incoming voice call");

        findViewById(R.id.btn_answer).setOnClickListener(v -> answer());
        findViewById(R.id.btn_decline).setOnClickListener(v -> decline());

        // Dismiss this screen if the call is cancelled/missed while it rings.
        finishReceiver = new BroadcastReceiver() {
            @Override public void onReceive(Context c, Intent i) { finishAndRemoveTask(); }
        };
        IntentFilter filter = new IntentFilter(ACTION_FINISH);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            registerReceiver(finishReceiver, filter, Context.RECEIVER_NOT_EXPORTED);
        } else {
            registerReceiver(finishReceiver, filter);
        }
    }

    private void answer() {
        clearNotification();
        launchApp(ACTION_ANSWER);
        finishAndRemoveTask();
    }

    private void decline() {
        clearNotification();
        launchApp(ACTION_DECLINE);
        finishAndRemoveTask();
    }

    /** Bring the webview app forward (or start it) with the call decision. */
    private void launchApp(String action) {
        Intent intent = new Intent(this, MainActivity.class)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK
                | Intent.FLAG_ACTIVITY_SINGLE_TOP
                | Intent.FLAG_ACTIVITY_CLEAR_TOP)
            .putExtra(CallNativePlugin.INTENT_ACTION_KEY, action)
            .putExtra(CallNativePlugin.INTENT_CALLID_KEY, callId != null ? callId : "");
        startActivity(intent);
    }

    private void clearNotification() {
        NotificationManagerCompat.from(this).cancel(CallMessagingService.CALL_NOTIFICATION_ID);
    }

    @SuppressWarnings("deprecation")
    private void showOverLockScreen() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true);
            setTurnScreenOn(true);
            KeyguardManager km = (KeyguardManager) getSystemService(Context.KEYGUARD_SERVICE);
            if (km != null) km.requestDismissKeyguard(this, null);
        } else {
            getWindow().addFlags(
                WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED
                    | WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON
                    | WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON
                    | WindowManager.LayoutParams.FLAG_DISMISS_KEYGUARD);
        }
    }

    @Override
    protected void onDestroy() {
        if (finishReceiver != null) {
            try { unregisterReceiver(finishReceiver); } catch (IllegalArgumentException ignored) {}
            finishReceiver = null;
        }
        super.onDestroy();
    }
}
