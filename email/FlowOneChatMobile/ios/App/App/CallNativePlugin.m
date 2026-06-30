#import <Foundation/Foundation.h>
#import <Capacitor/Capacitor.h>

// Registers the Swift CallNativePlugin with the Capacitor bridge and declares
// the JS-callable methods. The plugin name "CallNative" must match
// registerPlugin('CallNative') on the JS side.
CAP_PLUGIN(CallNativePlugin, "CallNative",
    CAP_PLUGIN_METHOD(getVoipToken, CAPPluginReturnPromise);
    CAP_PLUGIN_METHOD(endCall, CAPPluginReturnPromise);
    CAP_PLUGIN_METHOD(setSession, CAPPluginReturnPromise);
    CAP_PLUGIN_METHOD(nativeLog, CAPPluginReturnPromise);
)
