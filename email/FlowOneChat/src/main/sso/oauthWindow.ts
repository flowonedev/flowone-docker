import { BrowserWindow } from 'electron'

export interface OAuthTokenData {
  access_token: string
  refresh_token: string
  session_token: string | null
  device_token: string | null
  expires_in: number
  user: {
    email: string
    display_name: string
  }
}

export interface OAuthResult {
  tokens: OAuthTokenData
  provider: string
}

const OAUTH_TIMEOUT = 5 * 60 * 1000 // 5 minutes
const MAX_PAYLOAD_SIZE = 8192 // 8KB

export function openOAuthWindow(authUrl: string, callbackHost: string, provider: string): Promise<OAuthResult> {
  return new Promise((resolve, reject) => {
    let settled = false

    const win = new BrowserWindow({
      width: 800,
      height: 700,
      show: true,
      autoHideMenuBar: true,
      title: `Sign in with ${provider === 'google' ? 'Google' : 'Microsoft'}`,
      webPreferences: {
        contextIsolation: true,
        nodeIntegration: false,
        sandbox: true,
      },
    })

    // Deny popups
    win.webContents.setWindowOpenHandler(() => ({ action: 'deny' as const }))

    const cleanup = () => {
      if (!win.isDestroyed()) {
        win.close()
      }
    }

    const timeout = setTimeout(() => {
      if (!settled) {
        settled = true
        cleanup()
        reject(new Error('OAUTH_TIMEOUT'))
      }
    }, OAUTH_TIMEOUT)

    win.on('closed', () => {
      clearTimeout(timeout)
      if (!settled) {
        settled = true
        reject(new Error('OAUTH_CANCELLED'))
      }
    })

    const handleNavigation = (url: string) => {
      try {
        if (!url.includes('oauth_success=') && !url.includes('oauth_error=')) return

        const parsed = new URL(url)

        // Validate callback host
        if (callbackHost && !url.includes(callbackHost)) return

        const errorParam = parsed.searchParams.get('oauth_error')
        if (errorParam) {
          if (!settled) {
            settled = true
            clearTimeout(timeout)
            cleanup()
            reject(new Error(`OAUTH_PROVIDER_ERROR:${errorParam}`))
          }
          return
        }

        const successParam = parsed.searchParams.get('oauth_success')
        if (successParam) {
          if (successParam.length > MAX_PAYLOAD_SIZE) {
            if (!settled) {
              settled = true
              clearTimeout(timeout)
              cleanup()
              reject(new Error('OAUTH_INVALID_CALLBACK'))
            }
            return
          }

          try {
            const decoded = JSON.parse(Buffer.from(successParam, 'base64').toString('utf-8'))
            if (!decoded.access_token || !decoded.user?.email) {
              throw new Error('Invalid token data')
            }

            if (!settled) {
              settled = true
              clearTimeout(timeout)
              cleanup()
              resolve({ tokens: decoded, provider })
            }
          } catch (e) {
            if (!settled) {
              settled = true
              clearTimeout(timeout)
              cleanup()
              reject(new Error('OAUTH_INVALID_CALLBACK'))
            }
          }
        }
      } catch (e) {
        // URL parse error -- ignore
      }
    }

    win.webContents.on('will-redirect', (_event, url) => handleNavigation(url))
    win.webContents.on('will-navigate', (_event, url) => handleNavigation(url))
    win.webContents.on('did-navigate', (_event, url) => handleNavigation(url))

    win.loadURL(authUrl).catch((err) => {
      if (!settled) {
        settled = true
        clearTimeout(timeout)
        reject(new Error(`OAUTH_PROVIDER_ERROR:${err.message}`))
      }
    })
  })
}
