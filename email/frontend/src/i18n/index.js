/**
 * i18n plugin setup
 * 
 * Initializes vue-i18n with English as default locale.
 * Hungarian locale is lazy-loaded when selected.
 * Locale files are split by module for maintainability.
 */
import { createI18n } from 'vue-i18n'

// Import all English locale files eagerly (default language, always available)
import commonEn from './locales/en/common.json'
import emailEn from './locales/en/email.json'
import boardsEn from './locales/en/boards.json'
import boardProEn from './locales/en/board-pro.json'
import crmEn from './locales/en/crm.json'
import chatEn from './locales/en/chat.json'
import moodboardsEn from './locales/en/moodboards.json'
import calendarEn from './locales/en/calendar.json'
import contactsEn from './locales/en/contacts.json'
import driveEn from './locales/en/drive.json'
import collabEn from './locales/en/collab.json'
import settingsEn from './locales/en/settings.json'
import clientsEn from './locales/en/clients.json'
import portalEn from './locales/en/portal.json'
import timeTrackerEn from './locales/en/time-tracker.json'
import onboardingEn from './locales/en/onboarding.json'
import feedbackEn from './locales/en/feedback.json'
import featureGuidesEn from './locales/en/feature-guides.json'
import newsReaderEn from './locales/en/news-reader.json'

// Deep-merge locale modules so overlapping section keys combine rather than overwrite
function mergeModules(...modules) {
  const result = {}
  for (const mod of modules) {
    for (const [key, value] of Object.entries(mod)) {
      if (result[key] && typeof result[key] === 'object' && typeof value === 'object' && !Array.isArray(value)) {
        result[key] = { ...result[key], ...value }
      } else {
        result[key] = value
      }
    }
  }
  return result
}

const enMessages = mergeModules(
  commonEn,
  emailEn,
  boardsEn,
  boardProEn,
  crmEn,
  chatEn,
  moodboardsEn,
  calendarEn,
  contactsEn,
  driveEn,
  collabEn,
  settingsEn,
  clientsEn,
  portalEn,
  timeTrackerEn,
  onboardingEn,
  feedbackEn,
  featureGuidesEn,
  newsReaderEn,
)

const i18n = createI18n({
  legacy: false, // use Composition API mode
  locale: localStorage.getItem('app_locale') || 'en',
  fallbackLocale: 'en',
  messages: {
    en: enMessages,
  },
  // Silently fall back to key if translation is missing (no console warnings)
  missingWarn: false,
  fallbackWarn: false,
})

/**
 * Load Hungarian locale lazily when user switches language.
 * Called once, then cached by vue-i18n.
 */
let huLoaded = false
export async function loadHungarianLocale() {
  if (huLoaded) return
  const modules = await Promise.all([
    import('./locales/hu/common.json'),
    import('./locales/hu/email.json'),
    import('./locales/hu/boards.json'),
    import('./locales/hu/board-pro.json'),
    import('./locales/hu/crm.json'),
    import('./locales/hu/chat.json'),
    import('./locales/hu/moodboards.json'),
    import('./locales/hu/calendar.json'),
    import('./locales/hu/contacts.json'),
    import('./locales/hu/drive.json'),
    import('./locales/hu/collab.json'),
    import('./locales/hu/settings.json'),
    import('./locales/hu/clients.json'),
    import('./locales/hu/portal.json'),
    import('./locales/hu/time-tracker.json'),
    import('./locales/hu/onboarding.json'),
    import('./locales/hu/feedback.json'),
    import('./locales/hu/feature-guides.json'),
    import('./locales/hu/news-reader.json'),
  ])
  const huMessages = mergeModules(...modules.map(m => m.default))
  i18n.global.setLocaleMessage('hu', huMessages)
  huLoaded = true
}

/**
 * Switch the active locale. Loads Hungarian lazily if needed.
 */
export async function setLocale(locale) {
  if (locale === 'hu') {
    await loadHungarianLocale()
  }
  i18n.global.locale.value = locale
  localStorage.setItem('app_locale', locale)
  document.documentElement.setAttribute('lang', locale)
}

// If user previously selected Hungarian, load it on startup
if (i18n.global.locale.value === 'hu') {
  loadHungarianLocale()
}

export default i18n

