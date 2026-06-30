import { powerMonitor, net } from 'electron'

export interface TokenRefreshOptions {
  getTokens: () => { accessToken: string; refreshToken: string; sessionToken: string } | null
  onRefreshed: (newTokens: { access_token: string; refresh_token: string; session_token?: string }) => void
  onFailed: () => void
  apiBaseUrl: string
}

let refreshTimer: ReturnType<typeof setTimeout> | null = null
let currentOptions: TokenRefreshOptions | null = null
let isRefreshing = false

function decodeJwtExp(token: string): number | null {
  try {
    const parts = token.split('.')
    if (parts.length !== 3) return null
    const payload = JSON.parse(Buffer.from(parts[1], 'base64url').toString('utf-8'))
    return payload.exp ?? null
  } catch {
    return null
  }
}

async function doRefresh(opts: TokenRefreshOptions): Promise<boolean> {
  if (isRefreshing) return false
  isRefreshing = true

  try {
    const tokens = opts.getTokens()
    if (!tokens) {
      isRefreshing = false
      return false
    }

    const response = await fetch(`${opts.apiBaseUrl}/api/auth/refresh`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${tokens.accessToken}`,
        'X-Session-Token': tokens.sessionToken,
      },
      body: JSON.stringify({ refresh_token: tokens.refreshToken }),
    })

    if (!response.ok) {
      console.error(`[TokenRefresh] Refresh failed: ${response.status}`)
      if (response.status === 401) {
        opts.onFailed()
      }
      isRefreshing = false
      return false
    }

    const data: any = await response.json()
    if (data.success && data.data) {
      opts.onRefreshed(data.data as any)
      console.log('[TokenRefresh] Token refreshed successfully')
      isRefreshing = false
      return true
    }

    isRefreshing = false
    return false
  } catch (e) {
    console.error('[TokenRefresh] Refresh error:', e)
    isRefreshing = false
    return false
  }
}

function scheduleRefresh(opts: TokenRefreshOptions): void {
  if (refreshTimer) {
    clearTimeout(refreshTimer)
    refreshTimer = null
  }

  const tokens = opts.getTokens()
  if (!tokens) return

  const exp = decodeJwtExp(tokens.accessToken)
  if (!exp) return

  const now = Math.floor(Date.now() / 1000)
  const remaining = exp - now

  // Jitter: +-30 seconds to prevent thundering herd
  const jitter = (Math.random() * 60 - 30)

  if (remaining < 300) {
    // Less than 5 minutes remaining, refresh immediately (with small jitter)
    const delay = Math.max(100, Math.random() * 5000)
    refreshTimer = setTimeout(async () => {
      const success = await doRefresh(opts)
      if (success) scheduleRefresh(opts)
    }, delay)
  } else {
    // Schedule 5 minutes before expiry + jitter
    const delay = (remaining - 300 + jitter) * 1000
    refreshTimer = setTimeout(async () => {
      const success = await doRefresh(opts)
      if (success) scheduleRefresh(opts)
    }, Math.max(1000, delay))
  }
}

export function startTokenRefreshTimer(opts: TokenRefreshOptions): { stop: () => void } {
  currentOptions = opts

  // Initial schedule
  scheduleRefresh(opts)

  // Handle resume from sleep
  const resumeHandler = () => {
    console.log('[TokenRefresh] System resumed, checking token expiry')
    if (currentOptions) {
      const tokens = currentOptions.getTokens()
      if (tokens) {
        const exp = decodeJwtExp(tokens.accessToken)
        if (exp) {
          const remaining = exp - Math.floor(Date.now() / 1000)
          if (remaining < 300) {
            doRefresh(currentOptions).then(success => {
              if (success && currentOptions) scheduleRefresh(currentOptions)
            })
          } else {
            scheduleRefresh(currentOptions)
          }
        }
      }
    }
  }

  powerMonitor.on('resume', resumeHandler)

  return {
    stop: () => {
      if (refreshTimer) {
        clearTimeout(refreshTimer)
        refreshTimer = null
      }
      currentOptions = null
      powerMonitor.removeListener('resume', resumeHandler)
    },
  }
}

export function stopTokenRefreshTimer(): void {
  if (refreshTimer) {
    clearTimeout(refreshTimer)
    refreshTimer = null
  }
  currentOptions = null
}
