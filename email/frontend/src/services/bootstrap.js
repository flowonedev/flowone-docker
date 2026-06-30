import api from './api'

let bootstrapPromise = null
let bootstrapData = null

const MAX_RETRIES = 3
const BASE_DELAY = 1000

/**
 * Fetch all app boot data in a single request.
 * Singleton -- no matter how many callers, only one HTTP request fires.
 * Retries up to 3 times with exponential backoff on transient failures.
 */
export async function bootstrap() {
  if (bootstrapPromise) return bootstrapPromise

  bootstrapPromise = (async () => {
    let lastError
    for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
      try {
        const res = await api.get('/bootstrap')
        if (res.data?.success) {
          bootstrapData = res.data.data
          return bootstrapData
        }
        throw new Error(res.data?.message || 'Bootstrap failed')
      } catch (err) {
        lastError = err
        const is401 = err.response?.status === 401
        const is403 = err.response?.status === 403
        if (is401 || is403) throw err

        if (attempt < MAX_RETRIES) {
          const delay = BASE_DELAY * Math.pow(2, attempt)
          await new Promise(r => setTimeout(r, delay))
        }
      }
    }
    bootstrapPromise = null
    throw lastError
  })()

  bootstrapPromise.catch(() => { bootstrapPromise = null })

  return bootstrapPromise
}

/**
 * Get already-fetched bootstrap data (null if not yet loaded).
 */
export function getBootstrapData() {
  return bootstrapData
}

/**
 * Reset bootstrap state (call on logout or account switch).
 */
export function resetBootstrap() {
  bootstrapPromise = null
  bootstrapData = null
}

/**
 * Hydrate all stores from bootstrap data.
 * Imports stores lazily to avoid circular dependencies.
 */
export async function hydrateStores(data) {
  const { useAuthStore } = await import('@/stores/auth')
  const { useSettingsStore } = await import('@/stores/settings')
  const { useAccountsStore } = await import('@/stores/accounts')
  const { useLabelsStore } = await import('@/stores/labels')
  const { useNotificationsStore } = await import('@/stores/notifications')
  const { useThemeStore } = await import('@/stores/theme')
  const { useLayoutStore } = await import('@/stores/layout')
  const { usePerspectiveStore } = await import('@/stores/perspective')
  const { useAddons } = await import('@/composables/useAddons')

  const authStore = useAuthStore()
  const settingsStore = useSettingsStore()
  const accountsStore = useAccountsStore()
  const labelsStore = useLabelsStore()
  const notificationsStore = useNotificationsStore()
  const themeStore = useThemeStore()
  const layoutStore = useLayoutStore()
  const perspectiveStore = usePerspectiveStore()
  const { hydrateAddons } = useAddons()

  // Auth: full user profile replaces /auth/me
  if (data.user) {
    authStore.hydrateFromBootstrap(data.user)
  }

  // Settings (must be first -- theme/layout/perspective read from it)
  if (data.settings) {
    settingsStore.hydrateFromBootstrap(data.settings, data.mail?.trusted_senders)
  }

  // Theme / layout / perspective apply from settings (no separate fetch)
  if (data.settings) {
    themeStore.applySettings(data.settings)
    layoutStore.applyFromBootstrap(data.settings)
    perspectiveStore.applySettings(data.settings)
  }

  // Accounts
  if (data.accounts) {
    accountsStore.hydrateFromBootstrap(data.accounts)
  }

  // Mail: labels + filters + folder identity version (Wave 2 P2)
  if (data.mail) {
    labelsStore.hydrateFromBootstrap(data.mail.labels, data.mail.label_colors)

    if (data.mail.filters) {
      try {
        const { useFiltersStore } = await import('@/stores/filters')
        const filtersStore = useFiltersStore()
        filtersStore.hydrateFromBootstrap(data.mail.filters)
      } catch (e) { /* non-critical */ }
    }

    // Folder identity version: baseline that the mail-sync layer compares
    // against on every WebSocket FOLDER_CHANGED event and on reconnect.
    // Always set, even when 0 (= "unknown"), so the mailbox store's
    // setFolderIdentityVersion guard kicks in deterministically.
    if (data.mail.folder_identity_version !== undefined) {
      try {
        const { useMailboxStore } = await import('@/stores/mailbox')
        const mailboxStore = useMailboxStore()
        mailboxStore.setFolderIdentityVersion(data.mail.folder_identity_version)
      } catch (e) { /* non-critical */ }
    }
  }

  // Notifications
  if (data.notifications) {
    notificationsStore.hydrateFromBootstrap(data.notifications)
  }

  // Addons
  if (data.addons) {
    hydrateAddons(data.addons)
  }

  // Team: colleagues + groups (conditional on addon)
  if (data.team) {
    try {
      const { useColleaguesStore } = await import('@/addons/team/stores/colleagues')
      const colleaguesStore = useColleaguesStore()
      colleaguesStore.hydrateFromBootstrap(data.team, authStore.userEmail)
    } catch (e) { /* team addon may not be available */ }
  }

  // Todos (conditional -- addon may be disabled)
  if (data.todos) {
    try {
      const { useTodosStore } = await import('@/addons/tasks/stores/todos')
      const todosStore = useTodosStore()
      todosStore.hydrateFromBootstrap(data.todos)
    } catch (e) { /* tasks addon may not be available */ }
  }

  // Push: set VAPID key so pushNotifications.init() skips the API call
  if (data.push?.vapid_key) {
    try {
      const { default: pushNotifications } = await import('@/services/pushNotifications')
      pushNotifications.setVapidKey(data.push.vapid_key)
    } catch (e) { /* non-critical */ }
  }
}
