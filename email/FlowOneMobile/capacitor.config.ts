import type { CapacitorConfig } from '@capacitor/cli'

const config: CapacitorConfig = {
  appId: 'com.flowone.pro',
  appName: 'FlowOne Pro',
  webDir: '../frontend/dist',
  server: {
    // To load from live server instead of bundled assets, uncomment:
    // url: 'https://flowone.pro/login',
    androidScheme: 'https',
    iosScheme: 'https',
    allowNavigation: ['flowone.pro', '*.flowone.pro'],
  },
  plugins: {
    CapacitorHttp: {
      enabled: true,
    },
    PushNotifications: {
      presentationOptions: ['badge', 'sound', 'alert'],
    },
    Keyboard: {
      resize: 'native',
      resizeOnFullScreen: true,
    },
    StatusBar: {
      style: 'dark',
      backgroundColor: '#1c1c22',
    },
  },
}

export default config
