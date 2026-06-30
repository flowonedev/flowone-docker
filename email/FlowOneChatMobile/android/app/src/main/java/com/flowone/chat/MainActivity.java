package com.flowone.chat;

import android.os.Bundle;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        // Register the custom CallNative plugin before the bridge initializes.
        registerPlugin(CallNativePlugin.class);
        super.onCreate(savedInstanceState);
    }
}
