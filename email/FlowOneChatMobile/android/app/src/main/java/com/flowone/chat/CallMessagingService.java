package com.flowone.chat;

import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.media.AudioAttributes;
import android.media.RingtoneManager;
import android.os.Build;
import android.util.Log;

import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.app.Person;

import com.google.firebase.messaging.RemoteMessage;

import java.util.Map;

/**
 * FirebaseMessagingService that adds the native full-screen incoming-call UI
 * (the Android counterpart of iOS CallKit) on top of the Capacitor Firebase
 * Messaging plugin.
 *
 * It extends the plugin's service so that all NON-call messages are forwarded
 * to it via {@code super.onMessageReceived(...)} — keeping normal push → JS
 * delivery and token handling unchanged. Only data-only messages carrying
 * {@code callEvent} are intercepted here:
 *   - incoming_call -> post a high-importance notification with a full-screen
 *     intent that launches {@link IncomingCallActivity} (over the lock screen).
 *   - end_call      -> cancel that notification + dismiss the call activity.
 *
 * NOTE: the parent class is the @capacitor-firebase/messaging v6 service. If a
 * future plugin version moves it, update the `extends` below and rebuild.
 */
public class CallMessagingService
        extends io.capawesome.capacitorjs.plugins.firebase.messaging.MessagingService {

    private static final String TAG = "CallMessagingService";
    static final String CHANNEL_ID = "flowone_calls";
    static final int CALL_NOTIFICATION_ID = 4711;

    static final String EXTRA_CALL_ID = "callId";
    static final String EXTRA_CALL_TYPE = "callType";
    static final String EXTRA_CALLER_NAME = "callerName";
    static final String EXTRA_CALLER_EMAIL = "callerEmail";
    static final String EXTRA_CONVERSATION_ID = "conversationId";

    @Override
    public void onMessageReceived(RemoteMessage remoteMessage) {
        Map<String, String> data = remoteMessage.getData();
        String callEvent = data != null ? data.get("callEvent") : null;

        if (callEvent == null) {
            // Not a call control message — hand off to the plugin (push → JS).
            super.onMessageReceived(remoteMessage);
            return;
        }

        if ("end_call".equals(callEvent)) {
            cancelCall();
            return;
        }

        // incoming_call
        ensureChannel();
        showIncomingCall(
            data.get(EXTRA_CALL_ID),
            data.get(EXTRA_CALL_TYPE),
            data.get(EXTRA_CALLER_NAME),
            data.get(EXTRA_CALLER_EMAIL),
            data.get(EXTRA_CONVERSATION_ID)
        );

        // If the webview/JS is alive, mirror the ring into the call store so the
        // in-app modal stands down (the FSI is the visible UI).
        CallNativePlugin.notifyIncomingIfActive(data);
    }

    @Override
    public void onNewToken(String token) {
        // Keep the plugin's token handling (registers the FCM token with JS).
        super.onNewToken(token);
    }

    private void showIncomingCall(String callId, String callType, String callerName,
                                  String callerEmail, String conversationId) {
        if (callId == null) callId = "";
        if (callerName == null || callerName.isEmpty()) {
            callerName = (callerEmail != null && !callerEmail.isEmpty()) ? callerEmail : "Incoming call";
        }
        boolean isVideo = "video".equals(callType);

        Intent fullScreenIntent = new Intent(this, IncomingCallActivity.class)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP)
            .putExtra(EXTRA_CALL_ID, callId)
            .putExtra(EXTRA_CALL_TYPE, callType)
            .putExtra(EXTRA_CALLER_NAME, callerName)
            .putExtra(EXTRA_CALLER_EMAIL, callerEmail)
            .putExtra(EXTRA_CONVERSATION_ID, conversationId);

        int piFlags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) piFlags |= PendingIntent.FLAG_IMMUTABLE;

        PendingIntent fullScreenPending = PendingIntent.getActivity(this, 0, fullScreenIntent, piFlags);

        // Answer / Decline actions route through IncomingCallActivity so the
        // accept/decline reaches the JS plugin consistently.
        PendingIntent answerPending = activityActionIntent(callId, IncomingCallActivity.ACTION_ANSWER, 1, fullScreenIntent);
        PendingIntent declinePending = activityActionIntent(callId, IncomingCallActivity.ACTION_DECLINE, 2, fullScreenIntent);

        Person caller = new Person.Builder().setName(callerName).build();

        NotificationCompat.Builder builder = new NotificationCompat.Builder(this, CHANNEL_ID)
            .setSmallIcon(android.R.drawable.sym_call_incoming)
            .setContentTitle(callerName)
            .setContentText(isVideo ? "Incoming video call" : "Incoming voice call")
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setOngoing(true)
            .setAutoCancel(false)
            .setFullScreenIntent(fullScreenPending, true)
            .setContentIntent(fullScreenPending);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            builder.setStyle(
                NotificationCompat.CallStyle.forIncomingCall(caller, declinePending, answerPending)
            );
        } else {
            builder.addAction(android.R.drawable.sym_action_call, "Answer", answerPending);
            builder.addAction(android.R.drawable.ic_menu_close_clear_cancel, "Decline", declinePending);
        }

        try {
            NotificationManagerCompat.from(this).notify(CALL_NOTIFICATION_ID, builder.build());
        } catch (SecurityException e) {
            Log.w(TAG, "POST_NOTIFICATIONS not granted: " + e.getMessage());
        }
    }

    private PendingIntent activityActionIntent(String callId, String action, int requestCode, Intent base) {
        Intent intent = new Intent(this, IncomingCallActivity.class)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP)
            .putExtras(base)
            .putExtra(IncomingCallActivity.EXTRA_ACTION, action);
        int piFlags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) piFlags |= PendingIntent.FLAG_IMMUTABLE;
        return PendingIntent.getActivity(this, requestCode, intent, piFlags);
    }

    private void cancelCall() {
        NotificationManagerCompat.from(this).cancel(CALL_NOTIFICATION_ID);
        // Dismiss the full-screen activity if it's up.
        Intent intent = new Intent(IncomingCallActivity.ACTION_FINISH);
        intent.setPackage(getPackageName());
        sendBroadcast(intent);
        CallNativePlugin.notifyEndedIfActive();
    }

    private void ensureChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return;
        NotificationManager nm = (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
        if (nm == null || nm.getNotificationChannel(CHANNEL_ID) != null) return;

        NotificationChannel channel = new NotificationChannel(
            CHANNEL_ID, "Calls", NotificationManager.IMPORTANCE_HIGH);
        channel.setDescription("Incoming voice and video calls");
        channel.setLockscreenVisibility(android.app.Notification.VISIBILITY_PUBLIC);
        channel.enableVibration(true);
        channel.setVibrationPattern(new long[]{0, 1000, 800, 1000, 800});
        AudioAttributes attrs = new AudioAttributes.Builder()
            .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
            .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
            .build();
        channel.setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE), attrs);
        nm.createNotificationChannel(channel);
    }
}
