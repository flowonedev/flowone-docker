/**
 * Runtime platform detection helpers.
 *
 * Used to gate third-party (Google/Microsoft) OAuth and multi-account UI on the
 * native iOS build for App Store compliance (Guideline 4 / 4.8): on iOS those
 * flows open the system browser and constitute third-party login, both of which
 * Apple rejects. Web, Electron desktop, and Android are unaffected.
 */

export function isIOSNativePlatform() {
  return typeof window !== 'undefined' && window.Capacitor?.getPlatform?.() === 'ios';
}
