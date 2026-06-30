import { vi, beforeEach } from 'vitest'
import { ref, computed } from 'vue'

export const mockApi = {
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
  patch: vi.fn(),
  request: vi.fn(),
  defaults: { headers: { common: {} } },
  interceptors: {
    request: { use: vi.fn() },
    response: { use: vi.fn() },
  },
}

vi.mock('@/services/api', () => ({ default: mockApi }))

vi.mock('@/services/nativeConfig', () => ({
  isNative: false,
  getApiOrigin: () => '',
}))

vi.mock('@/services/serverRegistry', () => ({
  isNative: false,
  domainPart: (e) =>
    typeof e === 'string' && e.includes('@') ? e.split('@').pop().toLowerCase() : '',
  deriveBaseFromEmail: (e) =>
    typeof e === 'string' && e.includes('@')
      ? `https://email.${e.split('@').pop().toLowerCase()}`
      : '',
  getServerBase: () => '',
  setServerBase: vi.fn(),
  clearServerBase: vi.fn(),
  getCachedBase: () => '',
  setCachedBase: vi.fn(),
  resolveServerBase: vi.fn(async () => ''),
  getApiOrigin: () => '',
  getWsUrl: () => 'wss://localhost/mailsync_ws',
}))

vi.mock('@/services/offlineMailbox', () => ({
  withOfflineFallback: vi.fn(async (onlineFn) => {
    try { return await onlineFn() } catch { return null }
  }),
  getOfflineFolders: vi.fn(() => []),
  getOfflineMessages: vi.fn(() => []),
  getOfflineMessage: vi.fn(() => null),
}))

vi.mock('@/services/offlineData', () => ({
  withOfflineFallback: vi.fn(async (onlineFn) => {
    try { return await onlineFn() } catch { return null }
  }),
  getOfflineCalendars: vi.fn(() => []),
  getOfflineEvents: vi.fn(() => []),
  getOfflineClients: vi.fn(() => []),
  getOfflineTodos: vi.fn(() => []),
}))

vi.mock('@/utils/debug', () => ({
  isDebugEnabled: vi.fn(() => false),
  debugLog: vi.fn(),
  setDebugEnabled: vi.fn(),
}))

vi.mock('@/services/browserNotifications', () => ({
  showNotification: vi.fn(),
  requestPermission: vi.fn(),
}))

vi.mock('@/composables/useAddons', () => ({
  useAddons: vi.fn(() => ({
    isEnabled: vi.fn(() => true),
    calendarEnabled: ref(true),
    addons: ref([]),
  })),
}))

export function resetAllMocks() {
  mockApi.get.mockReset()
  mockApi.post.mockReset()
  mockApi.put.mockReset()
  mockApi.delete.mockReset()
  mockApi.patch.mockReset()
}
