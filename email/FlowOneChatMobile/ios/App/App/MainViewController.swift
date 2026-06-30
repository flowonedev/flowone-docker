import UIKit
import Capacitor

/// Capacitor bridge controller for the Chat app.
///
/// Capacitor 6 on iOS registers ONLY the plugins listed in the bundled
/// `capacitor.config.json` `packageClassList`, and `npx cap copy/sync`
/// regenerates that list from npm packages exclusively — app-embedded plugins
/// are never added. So our local `CallNativePlugin` (PushKit/CallKit bridge)
/// must be registered explicitly here, mirroring Android's
/// `MainActivity.registerPlugin(CallNativePlugin.class)`.
///
/// `capacitorDidLoad()` runs right after the bridge is created and before the
/// webview loads, so the plugin is exported to JS before `callKit.init()` (in
/// services/callKit.js) calls `CallNative.addListener` / `getVoipToken`.
class MainViewController: CAPBridgeViewController {
    override func capacitorDidLoad() {
        bridge?.registerPluginInstance(CallNativePlugin())
    }
}
