<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import { useSettingsStore } from '@/stores/settings'
import { usePerspectiveStore, PERSPECTIVES } from '@/stores/perspective'
import { useLayoutStore } from '@/stores/layout'
import { useAddons } from '@/composables/useAddons'
import { useI18n } from 'vue-i18n'
import { setLocale } from '@/i18n'
import api from '@/services/api'
import { NEWS_READER_SEED_URLS } from '@/addons/news-reader/data/curatedFeeds'
import browserNotifications from '@/services/browserNotifications'
import SetupWorkMode from './SetupWorkMode.vue'
import SetupRole from './SetupRole.vue'
import SetupFeatures from './SetupFeatures.vue'
import logoUrl from '@/assets/flowone-logo.png'

const emit = defineEmits(['complete'])

const auth = useAuthStore()
const theme = useThemeStore()
const layout = useLayoutStore()
const settingsStore = useSettingsStore()
const perspectiveStore = usePerspectiveStore()
const { refreshAddons } = useAddons()
const { t, locale } = useI18n()

const currentStep = ref(0)
const direction = ref(1)
const transitioning = ref(false)
const avatarFile = ref(null)
const avatarPreview = ref(null)
const avatarInput = ref(null)
const saving = ref(false)
const mounted = ref(false)
const wizardNotifDeniedHint = ref(false)

function wizardNotifPermissionLabel() {
  if (!('Notification' in window)) return t('setupWizard.notifPermUnsupported')
  const p = Notification.permission
  if (p === 'granted') return t('setupWizard.notifPermGranted')
  if (p === 'denied') return t('setupWizard.notifPermDenied')
  return t('setupWizard.notifPermDefault')
}

async function toggleWizardDesktopNotifications() {
  if (choices.value.notifications) {
    choices.value.notifications = false
    wizardNotifDeniedHint.value = false
    return
  }
  const granted = await browserNotifications.requestPermission()
  choices.value.notifications = granted
  wizardNotifDeniedHint.value = !granted && Notification.permission === 'denied'
}

// NOTE: the watch(currentStepId, …) used to live here, but `currentStepId`
// is a computed declared further down. Calling watch on it before its `const`
// initializer ran caused a TDZ ReferenceError ("Cannot access 'O' before
// initialization" once minified). The watcher is now registered immediately
// after currentStepId is defined.

const choices = ref({
  workMode: '',
  role: '',
  addons: {
    kanban_boards: true,
    board_pro: false,
    project_hub: false,
    crm_pro: false,
    chat: false,
    team: false,
    automation_hub: false,
    email_marketing: false,
    moodboards: false,
    news_reader: false,
  },
  news_reader_region: 'HU',
  perspective: 'operations',
  accentColor: theme.accentColor || 'green',
  theme: 'light',
  language: locale.value || 'en',
  density: 'cosy',
  emailLayout: 'columns',
  messagesPerPage: 50,
  refreshInterval: 60,
  displayName: auth.user?.display_name || auth.displayName || '',
  notifications: true,
  notificationSound: true,
  ambientBackground: false,
  composeStyle: 'inline',
  undoSendDelay: 10,
})

function toggleAddon(slug) {
  choices.value.addons[slug] = !choices.value.addons[slug]
}

const perspectives = computed(() => [
  {
    id: 'executive',
    icon: 'query_stats',
    gradient: 'from-purple-500 via-violet-500 to-indigo-500',
    glowColor: 'rgba(168, 85, 247, 0.4)',
    features: [t('setupWizard.feat_exec_1'), t('setupWizard.feat_exec_2'), t('setupWizard.feat_exec_3'), t('setupWizard.feat_exec_4')],
  },
  {
    id: 'delivery',
    icon: 'engineering',
    gradient: 'from-emerald-500 via-teal-500 to-cyan-500',
    glowColor: 'rgba(20, 184, 166, 0.4)',
    features: [t('setupWizard.feat_del_1'), t('setupWizard.feat_del_2'), t('setupWizard.feat_del_3'), t('setupWizard.feat_del_4')],
  },
  {
    id: 'operations',
    icon: 'hub',
    gradient: 'from-blue-500 via-sky-500 to-cyan-500',
    glowColor: 'rgba(59, 130, 246, 0.4)',
    features: [t('setupWizard.feat_ops_1'), t('setupWizard.feat_ops_2'), t('setupWizard.feat_ops_3'), t('setupWizard.feat_ops_4')],
  },
])

const accentColors = [
  { id: 'green', name: 'Emerald', color: '#22c55e' },
  { id: 'blue', name: 'Sky', color: '#3b82f6' },
  { id: 'purple', name: 'Violet', color: '#a855f7' },
  { id: 'teal', name: 'Teal', color: '#14b8a6' },
  { id: 'orange', name: 'Sunset', color: '#f97316' },
  { id: 'red', name: 'Rose', color: '#ef4444' },
  { id: 'gold', name: 'Amber', color: '#eab308' },
  { id: 'mono', name: 'Mono', color: '#71717a' },
  { id: 'gradient', name: 'Aurora', color: 'linear-gradient(135deg, #a855f7, #ec4899, #f97316)' },
]

const themes = [
  { id: 'light', icon: 'light_mode', gradient: 'from-amber-200 to-orange-200' },
  { id: 'dark', icon: 'dark_mode', gradient: 'from-slate-700 to-slate-900' },
  { id: 'system', icon: 'contrast', gradient: 'from-blue-300 to-violet-300' },
]

const languages = [
  { code: 'en', label: 'English', shortCode: 'EN', icon: 'language', gradient: 'from-blue-500 to-indigo-500' },
  { code: 'hu', label: 'Magyar', shortCode: 'HU', icon: 'translate', gradient: 'from-red-500 via-white to-emerald-500' },
]

const steps = computed(() => {
  const base = [
    { id: 'welcome' },
    { id: 'workMode' },
  ]
  if (choices.value.workMode === 'team') {
    base.push({ id: 'role' })
  }
  base.push(
    { id: 'features' },
    { id: 'perspective' },
    { id: 'layout' },
    { id: 'color' },
    { id: 'theme' },
    { id: 'language' },
    { id: 'density' },
    { id: 'email' },
    { id: 'compose' },
    { id: 'notifications' },
    { id: 'profile' },
    { id: 'ready' },
  )
  return base
})

const totalSteps = computed(() => steps.value.length)
const progress = computed(() => ((currentStep.value + 1) / totalSteps.value) * 100)
const isFirst = computed(() => currentStep.value === 0)
const isLast = computed(() => currentStep.value === totalSteps.value - 1)
const currentStepId = computed(() => steps.value[currentStep.value]?.id)

// Reset the "permission denied" hint whenever the user lands on the
// notifications step. Registered here (right after currentStepId is defined)
// so we don't read it inside the temporal dead zone.
watch(currentStepId, (id) => {
  if (id === 'notifications') wizardNotifDeniedHint.value = false
})

const derivedPerspective = computed(() => {
  const isTeam = choices.value.workMode === 'team'
  const role = choices.value.role
  if (isTeam && ['business_owner', 'admin'].includes(role) && choices.value.addons.crm_pro) {
    return 'executive'
  }
  if (isTeam) return 'delivery'
  return 'operations'
})

// Auto-suggest perspective when onboarding answers change
watch(derivedPerspective, (val) => {
  choices.value.perspective = val
}, { immediate: true })

// Auto-configure addons and clear role when switching work mode
watch(() => choices.value.workMode, (mode) => {
  if (mode === 'solo') {
    choices.value.role = ''
    choices.value.addons.team = false
    choices.value.addons.project_hub = false
    choices.value.addons.chat = false
  } else if (mode === 'team') {
    choices.value.addons.team = true
    choices.value.addons.project_hub = true
    choices.value.addons.chat = true
  }
})

// Clamp step index when the steps list shrinks (e.g. switching team -> solo removes role)
watch(totalSteps, (len) => {
  if (currentStep.value >= len) {
    currentStep.value = len - 1
  }
})

const isDark = computed(() => theme.isDark)

const baseBg = computed(() => isDark.value ? '#08080e' : '#f2f0f7')
const cardBg = computed(() => isDark.value
  ? 'rgba(15, 15, 25, 0.82)'
  : 'rgba(255, 255, 255, 0.85)')

const selectedAccentColor = computed(() => accentColors.find(c => c.id === choices.value.accentColor))

const previousTheme = ref(null)

onMounted(async () => {
  previousTheme.value = theme.currentTheme
  if (theme.isDark) {
    theme.setTheme('light', false)
    choices.value.theme = 'light'
  }

  // Pre-fill from saved onboarding profile (for re-running the wizard)
  try {
    const { data } = await api.get('/onboarding/addon-profile')
    if (data?.data?.profile) {
      const p = data.data.profile
      if (p.work_mode) choices.value.workMode = p.work_mode
      if (p.role) choices.value.role = p.role
      if (p.perspective) choices.value.perspective = p.perspective
      if (p.addons && typeof p.addons === 'object') {
        for (const [slug, enabled] of Object.entries(p.addons)) {
          if (slug in choices.value.addons) {
            choices.value.addons[slug] = !!enabled
          }
        }
      }
    }
  } catch (e) {
    // No saved profile -- first time, that's fine
  }

  requestAnimationFrame(() => { mounted.value = true })
})

function toggleSetupTheme() {
  const next = isDark.value ? 'light' : 'dark'
  choices.value.theme = next
  theme.setTheme(next, false)
}


const canProceed = computed(() => {
  const step = currentStepId.value
  if (step === 'workMode') return !!choices.value.workMode
  if (step === 'role') return !!choices.value.role
  return true
})

async function nextStep() {
  if (transitioning.value || !canProceed.value) return
  if (isLast.value) {
    await finishSetup()
    return
  }
  direction.value = 1
  transitioning.value = true
  currentStep.value = Math.min(currentStep.value + 1, totalSteps.value - 1)
  await nextTick()
  setTimeout(() => { transitioning.value = false }, 700)
}

async function prevStep() {
  if (transitioning.value || isFirst.value) return
  direction.value = -1
  transitioning.value = true
  currentStep.value = Math.max(currentStep.value - 1, 0)
  await nextTick()
  setTimeout(() => { transitioning.value = false }, 700)
}

function selectPerspective(id) {
  choices.value.perspective = id
}

function selectAccent(id) {
  choices.value.accentColor = id
  theme.setAccentColor(id, false)
}

function selectTheme(id) {
  choices.value.theme = id
  theme.setTheme(id, false)
}

async function selectLanguage(code) {
  choices.value.language = code
  await setLocale(code)
}

function selectDensity(val) {
  choices.value.density = val
  theme.setDisplayDensity(val, false)
}

function triggerAvatarUpload() {
  avatarInput.value?.click()
}

function handleAvatarSelected(event) {
  const file = event.target.files?.[0]
  if (!file) return
  const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
  if (!validTypes.includes(file.type)) return
  if (file.size > 5 * 1024 * 1024) return
  avatarFile.value = file
  avatarPreview.value = URL.createObjectURL(file)
}

async function finishSetup() {
  saving.value = true
  try {
    const finalPerspective = choices.value.perspective || derivedPerspective.value
    perspectiveStore.setPerspective(finalPerspective, false)

    // Push addon preferences to Panel via backend
    if (choices.value.workMode) {
      try {
        await api.post('/onboarding/addon-profile', {
          work_mode: choices.value.workMode,
          role: choices.value.workMode === 'team' ? choices.value.role : null,
          addons: choices.value.addons,
        })
      } catch (e) {
        console.error('Addon profile save failed:', e)
      }
    }

    await settingsStore.updateSettings({
      perspective: finalPerspective,
      theme: choices.value.theme,
      accent_color: choices.value.accentColor,
      display_density: choices.value.density,
      layout_mode: choices.value.emailLayout,
      locale: choices.value.language,
      messages_per_page: choices.value.messagesPerPage,
      refresh_interval: choices.value.refreshInterval,
      display_name: choices.value.displayName,
      notifications_enabled: choices.value.notifications,
      notification_sound: choices.value.notificationSound,
      ambient_background: choices.value.ambientBackground,
      compose_style: choices.value.composeStyle,
      undo_send_delay: choices.value.undoSendDelay,
      setup_completed: true,
    })

    browserNotifications.setEnabled(choices.value.notifications)
    localStorage.setItem('notification_desktop', JSON.stringify(choices.value.notifications))

    theme.setTheme(choices.value.theme, false)
    theme.setAccentColor(choices.value.accentColor, false)
    theme.setDisplayDensity(choices.value.density, false)
    theme.setAmbientBackground(choices.value.ambientBackground, false)

    if (avatarFile.value) {
      try {
        const formData = new FormData()
        formData.append('avatar', avatarFile.value)
        // Let the browser set the multipart Content-Type (with boundary) automatically
        const response = await api.post('/colleagues/me/avatar', formData)
        if (response.data.success && auth.user) {
          auth.user.avatar_url = response.data.data.avatar_url
        }
      } catch (e) {
        console.error('Avatar upload failed during setup:', e)
      }
    }

    // Refresh addon state so the UI picks up changes immediately
    try {
      await refreshAddons()
    } catch (e) {
      console.error('Addon refresh after setup failed:', e)
    }

    if (choices.value.addons.news_reader) {
      const region = choices.value.news_reader_region || 'HU'
      const seeds = NEWS_READER_SEED_URLS[region] || NEWS_READER_SEED_URLS.HU
      for (const url of seeds) {
        try {
          await api.post('/news/subscriptions', { feed_url: url })
        } catch (e) {
          console.warn('[SetupWizard] news_reader seed skipped:', url, e?.message || e)
        }
      }
    }

    await new Promise(r => setTimeout(r, 600))
    saving.value = false
    emit('complete')
  } catch (e) {
    console.error('Setup wizard save failed:', e)
    saving.value = false
  }
}

const selectedPerspective = computed(() => perspectives.value.find(p => p.id === choices.value.perspective))

const headingCls = computed(() => isDark.value ? 'text-white' : 'text-surface-900')
const subCls = computed(() => isDark.value ? 'text-surface-400' : 'text-surface-500')
const labelCls = computed(() => isDark.value ? 'text-surface-300' : 'text-surface-600')

function optionCls(active) {
  if (active) return isDark.value
    ? 'border-white/20 bg-white/[0.08] scale-[1.03]'
    : 'border-primary-300 bg-primary-50/60 scale-[1.03]'
  return isDark.value
    ? 'border-white/[0.05] bg-white/[0.02] hover:bg-white/[0.05] hover:border-white/10'
    : 'border-surface-200 bg-surface-50/50 hover:bg-surface-100/60 hover:border-surface-300'
}

function pillCls(active) {
  if (active) return isDark.value
    ? 'border-white/20 bg-white/10 text-white'
    : 'border-primary-300 bg-primary-50 text-primary-700'
  return isDark.value
    ? 'border-white/[0.05] bg-white/[0.02] text-surface-500 hover:text-surface-300 hover:bg-white/[0.05]'
    : 'border-surface-200 bg-surface-50/50 text-surface-500 hover:text-surface-700 hover:bg-surface-100'
}

function chipCls() {
  return isDark.value
    ? 'bg-white/[0.06] border border-white/[0.08] text-surface-300'
    : 'bg-surface-100 border border-surface-200 text-surface-600'
}

function si(index) {
  return { style: `animation-delay: ${index * 80 + 80}ms` }
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[99999] flex items-center justify-center overflow-hidden">
      <!-- Background -->
      <div class="absolute inset-0 transition-colors duration-700" :style="{ background: baseBg }">
        <div class="blob blob-1" :class="{ 'blob-visible': mounted }"></div>
        <div class="blob blob-2" :class="{ 'blob-visible': mounted }"></div>
        <div class="blob blob-3" :class="{ 'blob-visible': mounted }"></div>
        <div class="blob blob-4" :class="{ 'blob-visible': mounted }"></div>
        <div class="blob blob-5" :class="{ 'blob-visible': mounted }"></div>
        <div class="blob blob-6" :class="{ 'blob-visible': mounted }"></div>
        <div
          class="absolute inset-0"
          :class="isDark ? 'opacity-[0.02]' : 'opacity-[0.04]'"
          :style="{
            backgroundImage: isDark
              ? 'radial-gradient(circle, rgba(255,255,255,0.6) 1px, transparent 1px)'
              : 'radial-gradient(circle, rgba(0,0,0,0.12) 1px, transparent 1px)',
            backgroundSize: '48px 48px',
          }"
        ></div>
      </div>

      <!-- Progress bar -->
      <div class="absolute top-0 left-0 right-0 h-[3px] z-10">
        <div
          class="h-full transition-all duration-700 ease-out rounded-r-full"
          style="background: linear-gradient(90deg, #a855f7, #ec4899, #f97316);"
          :style="{ width: progress + '%' }"
        ></div>
      </div>

      <!-- Content card -->
      <div class="relative z-10 w-full max-w-2xl mx-2 sm:mx-4 max-h-[calc(100dvh-24px)] sm:max-h-[calc(100dvh-48px)] flex flex-col">
        <div
          class="relative rounded-2xl sm:rounded-3xl border overflow-hidden transition-shadow duration-500 flex flex-col max-h-full"
          :class="isDark ? 'setup-card-dark border-white/[0.08]' : 'setup-card-light border-surface-200/80'"
          :style="{ background: cardBg, backdropFilter: 'blur(48px) saturate(1.6)' }"
        >
          <!-- Theme toggle (always visible) -->
          <button
            @click="toggleSetupTheme"
            class="absolute top-3 right-3 sm:top-4 sm:right-4 z-20 w-8 h-8 sm:w-9 sm:h-9 rounded-full flex items-center justify-center transition-all duration-300 hover:scale-110"
            :class="isDark
              ? 'bg-white/[0.08] hover:bg-white/[0.14] text-surface-400 hover:text-yellow-300'
              : 'bg-surface-100 hover:bg-surface-200 text-surface-500 hover:text-surface-800'"
            :title="isDark ? 'Switch to light' : 'Switch to dark'"
          >
            <span class="material-symbols-rounded text-lg transition-transform duration-500" :class="isDark ? 'rotate-0' : 'rotate-180'">
              {{ isDark ? 'dark_mode' : 'light_mode' }}
            </span>
          </button>

          <div class="relative min-h-0 flex-1 flex flex-col overflow-y-auto overscroll-contain">
            <Transition
              :name="direction > 0 ? 'step-next' : 'step-prev'"
              mode="out-in"
            >
              <!-- STEP: Welcome -->
              <div v-if="currentStepId === 'welcome'" key="welcome" class="flex-1 flex flex-col items-center justify-center px-5 py-8 sm:px-8 sm:py-12 text-center">
                <div class="si" v-bind="si(0)">
                  <img
                    :src="logoUrl"
                    alt="FlowOne"
                    class="w-16 h-16 sm:w-20 sm:h-20 mb-6 sm:mb-8 mx-auto animate-float object-contain"
                  />
                </div>
                <h1 class="si text-2xl sm:text-3xl font-bold mb-2 sm:mb-3" :class="[headingCls]" v-bind="si(1)">{{ t('setupWizard.welcomeTitle') }}</h1>
                <p class="si text-base sm:text-lg max-w-md leading-relaxed" :class="[subCls]" v-bind="si(2)">{{ t('setupWizard.welcomeSubtitle') }}</p>
                <p class="si text-xs sm:text-sm mt-4 sm:mt-6" :class="[isDark ? 'text-surface-500' : 'text-surface-400']" v-bind="si(3)">{{ t('setupWizard.welcomeHint') }}</p>
              </div>

              <!-- STEP: Work Mode -->
              <SetupWorkMode
                v-else-if="currentStepId === 'workMode'"
                key="workMode"
                v-model="choices.workMode"
                :is-dark="isDark"
                :heading-cls="headingCls"
                :sub-cls="subCls"
              />

              <!-- STEP: Role (team only) -->
              <SetupRole
                v-else-if="currentStepId === 'role'"
                key="role"
                v-model="choices.role"
                :is-dark="isDark"
                :heading-cls="headingCls"
                :sub-cls="subCls"
              />

              <!-- STEP: Features -->
              <SetupFeatures
                v-else-if="currentStepId === 'features'"
                key="features"
                :addons="choices.addons"
                :news-reader-region="choices.news_reader_region"
                :is-team="choices.workMode === 'team'"
                :is-dark="isDark"
                :heading-cls="headingCls"
                :sub-cls="subCls"
                @toggle="toggleAddon"
                @set-news-reader-region="(r) => (choices.news_reader_region = r)"
              />

              <!-- STEP: Perspective -->
              <div v-else-if="currentStepId === 'perspective'" key="perspective" class="flex-1 flex flex-col px-4 py-6 sm:px-8 sm:py-10">
                <div class="text-center mb-5 sm:mb-8">
                  <h2 class="si text-xl sm:text-2xl font-bold mb-1 sm:mb-2" :class="[headingCls]" v-bind="si(0)">{{ t('setupWizard.perspectiveTitle') }}</h2>
                  <p class="si text-sm sm:text-base" :class="[subCls]" v-bind="si(1)">{{ t('setupWizard.perspectiveSubtitle') }}</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 flex-1">
                  <button
                    v-for="(p, pi) in perspectives"
                    :key="p.id"
                    @click="selectPerspective(p.id)"
                    class="si group relative flex sm:flex-col items-center sm:items-center gap-3 sm:gap-0 p-3.5 sm:p-5 rounded-2xl border transition-all duration-300 text-left hover:-translate-y-1"
                    :class="[optionCls(choices.perspective === p.id)]"
                    v-bind="si(pi + 2)"
                  >
                    <div
                      class="w-10 h-10 sm:w-14 sm:h-14 rounded-xl bg-gradient-to-br flex items-center justify-center sm:mb-4 shrink-0 transition-all duration-300"
                      :class="[p.gradient, choices.perspective === p.id ? 'shadow-lg' : 'opacity-70 group-hover:opacity-100']"
                      :style="choices.perspective === p.id ? { boxShadow: `0 8px 30px ${p.glowColor}` } : {}"
                    >
                      <span class="material-symbols-rounded text-white text-lg sm:text-2xl">{{ p.icon }}</span>
                    </div>
                    <div class="flex-1 min-w-0">
                      <span class="text-sm font-semibold mb-1 sm:mb-2 block" :class="isDark ? 'text-white' : 'text-surface-800'">{{ t(`perspective.${p.id}`) }}</span>
                      <ul class="space-y-0.5 sm:space-y-1 hidden sm:block">
                        <li v-for="f in p.features" :key="f" class="text-[11px] flex items-center gap-1.5 text-surface-500">
                          <span class="material-symbols-rounded text-[10px]" :class="choices.perspective === p.id ? 'text-primary-400' : (isDark ? 'text-surface-600' : 'text-surface-400')">check</span>
                          {{ f }}
                        </li>
                      </ul>
                      <p class="sm:hidden text-[11px] text-surface-500 line-clamp-1">{{ p.features[0] }}</p>
                    </div>
                    <div v-if="choices.perspective === p.id" class="absolute top-2.5 right-2.5 sm:top-3 sm:right-3 w-5 h-5 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center">
                      <span class="material-symbols-rounded text-white text-xs">check</span>
                    </div>
                  </button>
                </div>
              </div>

              <!-- STEP: Layout -->
              <div v-else-if="currentStepId === 'layout'" key="layout" class="flex-1 flex flex-col items-center justify-center px-5 py-6 sm:px-8 sm:py-10">
                <div class="text-center mb-6 sm:mb-10">
                  <h2 class="si text-xl sm:text-2xl font-bold mb-1 sm:mb-2" :class="[headingCls]" v-bind="si(0)">{{ t('setupWizard.layoutTitle') }}</h2>
                  <p class="si text-sm sm:text-base" :class="[subCls]" v-bind="si(1)">{{ t('setupWizard.layoutSubtitle') }}</p>
                </div>
                <div class="flex gap-4 sm:gap-6">
                  <button
                    v-for="(lo, li) in [
                      { id: 'columns', icon: 'view_column_2', label: 'setupWizard.layout_2col' },
                      { id: 'three-column', icon: 'view_week', label: 'setupWizard.layout_3col' },
                    ]"
                    :key="lo.id"
                    @click="choices.emailLayout = lo.id"
                    class="si group flex flex-col items-center gap-2 sm:gap-3 flex-1 max-w-[10rem] py-6 sm:py-8 rounded-2xl border transition-all duration-300 overflow-hidden hover:-translate-y-1"
                    :class="[optionCls(choices.emailLayout === lo.id)]"
                    v-bind="si(li + 2)"
                  >
                    <span class="material-symbols-rounded text-2xl sm:text-3xl" :class="choices.emailLayout === lo.id ? (isDark ? 'text-white' : 'text-surface-800') : 'text-surface-400'">{{ lo.icon }}</span>
                    <span class="text-sm font-medium" :class="choices.emailLayout === lo.id ? (isDark ? 'text-white' : 'text-surface-800') : 'text-surface-500'">{{ t(lo.label) }}</span>
                    <div v-if="choices.emailLayout === lo.id" class="absolute top-2.5 right-2.5 w-5 h-5 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center">
                      <span class="material-symbols-rounded text-white text-xs">check</span>
                    </div>
                  </button>
                </div>
              </div>

              <!-- STEP: Accent Color -->
              <div v-else-if="currentStepId === 'color'" key="color" class="flex-1 flex flex-col items-center justify-center px-5 py-6 sm:px-8 sm:py-10">
                <div class="text-center mb-4 sm:mb-6">
                  <h2 class="si text-xl sm:text-2xl font-bold mb-1 sm:mb-2" :class="[headingCls]" v-bind="si(0)">{{ t('setupWizard.colorTitle') }}</h2>
                  <p class="si text-sm sm:text-base" :class="[subCls]" v-bind="si(1)">{{ t('setupWizard.colorSubtitle') }}</p>
                </div>
                <!-- Live preview circle -->
                <div class="si mb-6 sm:mb-8 flex flex-col items-center gap-2 sm:gap-3" v-bind="si(2)">
                  <div
                    class="w-16 h-16 sm:w-24 sm:h-24 rounded-full transition-all duration-500 shadow-lg color-preview-pulse"
                    :style="{ background: selectedAccentColor?.color }"
                  ></div>
                  <span class="text-sm font-semibold transition-all duration-300" :class="headingCls">{{ selectedAccentColor?.name }}</span>
                </div>
                <!-- Color grid -->
                <div class="flex flex-wrap justify-center gap-3 sm:gap-4 max-w-md">
                  <button
                    v-for="(c, ci) in accentColors"
                    :key="c.id"
                    @click="selectAccent(c.id)"
                    class="si group flex flex-col items-center transition-all duration-300"
                    :class="[choices.accentColor === c.id ? 'scale-110' : 'hover:scale-110 opacity-60 hover:opacity-100']"
                    v-bind="si(ci + 3)"
                  >
                    <div
                      class="w-10 h-10 sm:w-11 sm:h-11 rounded-full transition-all duration-300"
                      :class="choices.accentColor === c.id
                        ? (isDark ? 'ring-2 ring-white ring-offset-2 ring-offset-[#0f0f19]' : 'ring-2 ring-surface-800 ring-offset-2 ring-offset-white')
                        : 'ring-1 ring-transparent'"
                      :style="{ background: c.color }"
                    ></div>
                  </button>
                </div>
              </div>

              <!-- STEP: Theme -->
              <div v-else-if="currentStepId === 'theme'" key="theme" class="flex-1 flex flex-col items-center justify-center px-5 py-6 sm:px-8 sm:py-10">
                <div class="text-center mb-6 sm:mb-10">
                  <h2 class="si text-xl sm:text-2xl font-bold mb-1 sm:mb-2" :class="[headingCls]" v-bind="si(0)">{{ t('setupWizard.themeTitle') }}</h2>
                  <p class="si text-sm sm:text-base" :class="[subCls]" v-bind="si(1)">{{ t('setupWizard.themeSubtitle') }}</p>
                </div>
                <div class="flex gap-4 sm:gap-6">
                  <button
                    v-for="(th, ti) in themes"
                    :key="th.id"
                    @click="selectTheme(th.id)"
                    class="si group flex flex-col items-center gap-2 sm:gap-3 transition-all duration-300 hover:-translate-y-1"
                    :class="[choices.theme === th.id ? 'scale-105' : 'opacity-60 hover:opacity-100 hover:scale-105']"
                    v-bind="si(ti + 2)"
                  >
                    <div
                      class="w-18 h-18 sm:w-24 sm:h-24 rounded-2xl bg-gradient-to-br flex items-center justify-center transition-all duration-300"
                      :class="[th.gradient, choices.theme === th.id
                        ? (isDark ? 'ring-2 ring-white ring-offset-2 ring-offset-[#0f0f19] shadow-xl' : 'ring-2 ring-surface-800 ring-offset-2 ring-offset-white shadow-xl')
                        : '']"
                    >
                      <span class="material-symbols-rounded text-2xl sm:text-3xl" :class="th.id === 'dark' ? 'text-surface-300' : 'text-surface-800'">{{ th.icon }}</span>
                    </div>
                    <span class="text-xs sm:text-sm font-medium" :class="choices.theme === th.id ? (isDark ? 'text-white' : 'text-surface-900') : 'text-surface-500'">{{ t(`setupWizard.theme_${th.id}`) }}</span>
                  </button>
                </div>
              </div>

              <!-- STEP: Language -->
              <div v-else-if="currentStepId === 'language'" key="language" class="flex-1 flex flex-col items-center justify-center px-5 py-6 sm:px-8 sm:py-10">
                <div class="text-center mb-6 sm:mb-10">
                  <h2 class="si text-xl sm:text-2xl font-bold mb-1 sm:mb-2" :class="[headingCls]" v-bind="si(0)">{{ t('setupWizard.languageTitle') }}</h2>
                  <p class="si text-sm sm:text-base" :class="[subCls]" v-bind="si(1)">{{ t('setupWizard.languageSubtitle') }}</p>
                </div>
                <div class="flex gap-3 sm:gap-5 w-full max-w-sm justify-center">
                  <button
                    v-for="(lang, li) in languages"
                    :key="lang.code"
                    @click="selectLanguage(lang.code)"
                    class="si group relative flex flex-col items-center gap-3 sm:gap-4 flex-1 max-w-[10rem] py-6 sm:py-8 rounded-2xl border transition-all duration-300 overflow-hidden hover:-translate-y-1"
                    :class="[optionCls(choices.language === lang.code)]"
                    v-bind="si(li + 2)"
                  >
                    <!-- Gradient accent strip at top -->
                    <div
                      class="absolute top-0 inset-x-0 h-1 transition-opacity duration-300"
                      :class="choices.language === lang.code ? 'opacity-100' : 'opacity-0'"
                      :style="{ background: `linear-gradient(90deg, ${lang.code === 'en' ? '#3b82f6, #6366f1' : '#ef4444, #ffffff, #22c55e'})` }"
                    ></div>
                    <span class="text-3xl sm:text-4xl font-bold tracking-wide leading-none" :class="choices.language === lang.code ? (isDark ? 'text-white' : 'text-surface-800') : (isDark ? 'text-surface-400' : 'text-surface-500')">{{ lang.shortCode }}</span>
                    <div class="flex flex-col items-center gap-0.5">
                      <span class="text-sm sm:text-base font-semibold" :class="choices.language === lang.code ? (isDark ? 'text-white' : 'text-surface-900') : (isDark ? 'text-surface-300' : 'text-surface-600')">{{ lang.label }}</span>
                      <span class="text-[11px]" :class="isDark ? 'text-surface-500' : 'text-surface-400'">{{ lang.shortCode }}</span>
                    </div>
                    <!-- Check indicator -->
                    <div v-if="choices.language === lang.code" class="absolute top-2.5 right-2.5 sm:top-3 sm:right-3 w-5 h-5 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center">
                      <span class="material-symbols-rounded text-white text-xs">check</span>
                    </div>
                  </button>
                </div>
              </div>

              <!-- STEP: Density -->
              <div v-else-if="currentStepId === 'density'" key="density" class="flex-1 flex flex-col items-center justify-center px-5 py-6 sm:px-8 sm:py-10">
                <div class="text-center mb-6 sm:mb-10">
                  <h2 class="si text-xl sm:text-2xl font-bold mb-1 sm:mb-2" :class="[headingCls]" v-bind="si(0)">{{ t('setupWizard.densityTitle') }}</h2>
                  <p class="si text-sm sm:text-base" :class="[subCls]" v-bind="si(1)">{{ t('setupWizard.densitySubtitle') }}</p>
                </div>
                <div class="flex gap-4 sm:gap-6 w-full max-w-sm justify-center">
                  <button
                    v-for="(d, di) in [{ id: 'cosy', icon: 'density_medium' }, { id: 'compact', icon: 'density_small' }]"
                    :key="d.id"
                    @click="selectDensity(d.id)"
                    class="si flex flex-col items-center gap-2 sm:gap-3 flex-1 py-6 sm:py-8 rounded-2xl border transition-all duration-300 hover:-translate-y-1"
                    :class="[optionCls(choices.density === d.id)]"
                    v-bind="si(di + 2)"
                  >
                    <span class="material-symbols-rounded text-2xl sm:text-3xl" :class="choices.density === d.id ? (isDark ? 'text-white' : 'text-surface-800') : 'text-surface-400'">{{ d.icon }}</span>
                    <span class="text-sm font-medium" :class="choices.density === d.id ? (isDark ? 'text-white' : 'text-surface-800') : 'text-surface-500'">{{ t(`setupWizard.density_${d.id}`) }}</span>
                    <span class="text-[11px]" :class="isDark ? 'text-surface-600' : 'text-surface-400'">{{ t(`setupWizard.density_${d.id}_desc`) }}</span>
                  </button>
                </div>
              </div>

              <!-- STEP: Email Preferences -->
              <div v-else-if="currentStepId === 'email'" key="email" class="flex-1 flex flex-col items-center justify-center px-5 py-6 sm:px-8 sm:py-10">
                <div class="text-center mb-6 sm:mb-10">
                  <h2 class="si text-xl sm:text-2xl font-bold mb-1 sm:mb-2" :class="[headingCls]" v-bind="si(0)">{{ t('setupWizard.emailTitle') }}</h2>
                  <p class="si text-sm sm:text-base" :class="[subCls]" v-bind="si(1)">{{ t('setupWizard.emailSubtitle') }}</p>
                </div>
                <div class="w-full max-w-sm space-y-6 sm:space-y-8">
                  <div class="si" v-bind="si(2)">
                    <label class="block text-sm font-medium mb-2 sm:mb-3" :class="labelCls">{{ t('setupWizard.messagesPerPage') }}</label>
                    <div class="flex gap-2 sm:gap-3">
                      <button
                        v-for="n in [25, 50, 75, 100]"
                        :key="n"
                        @click="choices.messagesPerPage = n"
                        class="flex-1 py-2 sm:py-2.5 rounded-xl text-sm font-medium border transition-all duration-200"
                        :class="pillCls(choices.messagesPerPage === n)"
                      >{{ n }}</button>
                    </div>
                  </div>
                  <div class="si" v-bind="si(3)">
                    <label class="block text-sm font-medium mb-2 sm:mb-3" :class="labelCls">{{ t('setupWizard.refreshInterval') }}</label>
                    <div class="flex flex-wrap gap-2 sm:gap-3">
                      <button
                        v-for="r in [{ val: 30, label: '30s' }, { val: 60, label: '1m' }, { val: 120, label: '2m' }, { val: 300, label: '5m' }, { val: 0, label: 'Off' }]"
                        :key="r.val"
                        @click="choices.refreshInterval = r.val"
                        class="flex-1 min-w-[3.5rem] py-2 sm:py-2.5 rounded-xl text-sm font-medium border transition-all duration-200"
                        :class="pillCls(choices.refreshInterval === r.val)"
                      >{{ r.label }}</button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- STEP: Compose & Send -->
              <div v-else-if="currentStepId === 'compose'" key="compose" class="flex-1 flex flex-col items-center justify-center px-4 py-6 sm:px-8 sm:py-10">
                <div class="text-center mb-6 sm:mb-10">
                  <h2 class="si text-xl sm:text-2xl font-bold mb-1 sm:mb-2" :class="[headingCls]" v-bind="si(0)">{{ t('setupWizard.composeTitle') }}</h2>
                  <p class="si text-sm sm:text-base" :class="[subCls]" v-bind="si(1)">{{ t('setupWizard.composeSubtitle') }}</p>
                </div>
                <div class="w-full max-w-sm space-y-3 sm:space-y-5">
                  <!-- Gmail-style composer toggle -->
                  <button
                    @click="choices.composeStyle = choices.composeStyle === 'inline' ? 'modal' : 'inline'"
                    class="si w-full flex items-center justify-between px-3.5 py-3 sm:px-5 sm:py-4 rounded-2xl border transition-all duration-300 hover:-translate-y-0.5"
                    :class="[optionCls(choices.composeStyle === 'inline')]"
                    v-bind="si(2)"
                  >
                    <div class="flex items-center gap-2.5 sm:gap-3 min-w-0">
                      <span class="material-symbols-rounded text-lg sm:text-xl shrink-0" :class="choices.composeStyle === 'inline' ? 'text-primary-500' : (isDark ? 'text-surface-500' : 'text-surface-400')">edit_note</span>
                      <div class="text-left min-w-0">
                        <span class="block text-sm font-medium" :class="headingCls">{{ t('setupWizard.gmailComposer') }}</span>
                        <span class="block text-[11px] truncate" :class="subCls">{{ t('setupWizard.gmailComposerDesc') }}</span>
                      </div>
                    </div>
                    <div
                      class="relative w-11 h-6 rounded-full transition-colors duration-300 shrink-0 ml-4"
                      :class="choices.composeStyle === 'inline' ? 'bg-primary-500' : (isDark ? 'bg-surface-600' : 'bg-surface-300')"
                    >
                      <div
                        class="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform duration-300"
                        :class="choices.composeStyle === 'inline' ? 'translate-x-[22px]' : 'translate-x-0.5'"
                      ></div>
                    </div>
                  </button>

                  <!-- Undo Send delay -->
                  <div class="si rounded-2xl border px-3.5 py-3 sm:px-5 sm:py-4 transition-colors duration-300"
                    :class="isDark ? 'border-white/[0.05] bg-white/[0.02]' : 'border-surface-200 bg-surface-50/50'"
                    v-bind="si(3)"
                  >
                    <div class="flex items-center gap-2.5 sm:gap-3 mb-3">
                      <span class="material-symbols-rounded text-lg sm:text-xl shrink-0" :class="choices.undoSendDelay > 0 ? 'text-primary-500' : (isDark ? 'text-surface-500' : 'text-surface-400')">undo</span>
                      <div class="text-left min-w-0">
                        <span class="block text-sm font-medium" :class="headingCls">{{ t('setupWizard.undoSendTitle') }}</span>
                        <span class="block text-[11px]" :class="subCls">{{ t('setupWizard.undoSendDesc') }}</span>
                      </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                      <button
                        v-for="r in [{ val: 0, label: t('setupWizard.undoSend_off') }, { val: 10, label: '10s' }, { val: 20, label: '20s' }, { val: 30, label: '30s' }, { val: 60, label: '1m' }]"
                        :key="r.val"
                        @click="choices.undoSendDelay = r.val"
                        class="flex-1 min-w-[3.25rem] py-2 sm:py-2.5 rounded-xl text-sm font-medium border transition-all duration-200"
                        :class="pillCls(choices.undoSendDelay === r.val)"
                      >{{ r.label }}</button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- STEP: Notifications -->
              <div v-else-if="currentStepId === 'notifications'" key="notifications" class="flex-1 flex flex-col items-center justify-center px-4 py-6 sm:px-8 sm:py-10">
                <div class="text-center mb-6 sm:mb-10">
                  <h2 class="si text-xl sm:text-2xl font-bold mb-1 sm:mb-2" :class="[headingCls]" v-bind="si(0)">{{ t('setupWizard.notificationsTitle') }}</h2>
                  <p class="si text-sm sm:text-base" :class="[subCls]" v-bind="si(1)">{{ t('setupWizard.notificationsSubtitle') }}</p>
                </div>
                <div class="w-full max-w-sm space-y-3 sm:space-y-5">
                  <button
                    @click="toggleWizardDesktopNotifications"
                    class="si w-full flex items-center justify-between px-3.5 py-3 sm:px-5 sm:py-4 rounded-2xl border transition-all duration-300 hover:-translate-y-0.5"
                    :class="[optionCls(choices.notifications)]"
                    v-bind="si(2)"
                  >
                    <div class="flex items-center gap-2.5 sm:gap-3 min-w-0">
                      <span class="material-symbols-rounded text-lg sm:text-xl shrink-0" :class="choices.notifications ? 'text-primary-500' : (isDark ? 'text-surface-500' : 'text-surface-400')">notifications_active</span>
                      <div class="text-left min-w-0">
                        <span class="block text-sm font-medium" :class="headingCls">{{ t('setupWizard.desktopNotifications') }}</span>
                        <span class="block text-[11px] truncate" :class="subCls">{{ t('setupWizard.desktopNotificationsDesc') }}</span>
                      </div>
                    </div>
                    <div
                      class="relative w-11 h-6 rounded-full transition-colors duration-300 shrink-0 ml-4"
                      :class="choices.notifications ? 'bg-primary-500' : (isDark ? 'bg-surface-600' : 'bg-surface-300')"
                    >
                      <div
                        class="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform duration-300"
                        :class="choices.notifications ? 'translate-x-[22px]' : 'translate-x-0.5'"
                      ></div>
                    </div>
                  </button>

                  <p class="si text-xs mt-2 px-1" :class="subCls" v-bind="si(3)">{{ wizardNotifPermissionLabel() }}</p>
                  <p v-if="wizardNotifDeniedHint" class="si text-xs mt-1 px-1 text-amber-600 dark:text-amber-400" v-bind="si(4)">{{ t('setupWizard.notifPermDeniedHint') }}</p>

                  <button
                    @click="choices.notificationSound = !choices.notificationSound"
                    class="si w-full flex items-center justify-between px-3.5 py-3 sm:px-5 sm:py-4 rounded-2xl border transition-all duration-300 hover:-translate-y-0.5"
                    :class="[optionCls(choices.notificationSound)]"
                    v-bind="si(5)"
                  >
                    <div class="flex items-center gap-2.5 sm:gap-3 min-w-0">
                      <span class="material-symbols-rounded text-lg sm:text-xl shrink-0" :class="choices.notificationSound ? 'text-primary-500' : (isDark ? 'text-surface-500' : 'text-surface-400')">{{ choices.notificationSound ? 'volume_up' : 'volume_off' }}</span>
                      <div class="text-left min-w-0">
                        <span class="block text-sm font-medium" :class="headingCls">{{ t('setupWizard.soundAlerts') }}</span>
                        <span class="block text-[11px] truncate" :class="subCls">{{ t('setupWizard.soundAlertsDesc') }}</span>
                      </div>
                    </div>
                    <div
                      class="relative w-11 h-6 rounded-full transition-colors duration-300 shrink-0 ml-4"
                      :class="choices.notificationSound ? 'bg-primary-500' : (isDark ? 'bg-surface-600' : 'bg-surface-300')"
                    >
                      <div
                        class="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform duration-300"
                        :class="choices.notificationSound ? 'translate-x-[22px]' : 'translate-x-0.5'"
                      ></div>
                    </div>
                  </button>

                  <button
                    @click="choices.ambientBackground = !choices.ambientBackground"
                    class="si w-full flex items-center justify-between px-3.5 py-3 sm:px-5 sm:py-4 rounded-2xl border transition-all duration-300 hover:-translate-y-0.5"
                    :class="[optionCls(choices.ambientBackground)]"
                    v-bind="si(6)"
                  >
                    <div class="flex items-center gap-2.5 sm:gap-3 min-w-0">
                      <span class="material-symbols-rounded text-lg sm:text-xl shrink-0" :class="choices.ambientBackground ? 'text-primary-500' : (isDark ? 'text-surface-500' : 'text-surface-400')">blur_on</span>
                      <div class="text-left min-w-0">
                        <span class="block text-sm font-medium" :class="headingCls">{{ t('setupWizard.ambientBackground') }}</span>
                        <span class="block text-[11px] truncate" :class="subCls">{{ t('setupWizard.ambientBackgroundDesc') }}</span>
                      </div>
                    </div>
                    <div
                      class="relative w-11 h-6 rounded-full transition-colors duration-300 shrink-0 ml-4"
                      :class="choices.ambientBackground ? 'bg-primary-500' : (isDark ? 'bg-surface-600' : 'bg-surface-300')"
                    >
                      <div
                        class="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform duration-300"
                        :class="choices.ambientBackground ? 'translate-x-[22px]' : 'translate-x-0.5'"
                      ></div>
                    </div>
                  </button>
                </div>
              </div>

              <!-- STEP: Profile -->
              <div v-else-if="currentStepId === 'profile'" key="profile" class="flex-1 flex flex-col items-center justify-center px-5 py-6 sm:px-8 sm:py-10">
                <div class="text-center mb-5 sm:mb-8">
                  <h2 class="si text-xl sm:text-2xl font-bold mb-1 sm:mb-2" :class="[headingCls]" v-bind="si(0)">{{ t('setupWizard.profileTitle') }}</h2>
                  <p class="si text-sm sm:text-base" :class="[subCls]" v-bind="si(1)">{{ t('setupWizard.profileSubtitle') }}</p>
                </div>
                <div class="flex flex-col items-center gap-5 sm:gap-6 w-full max-w-sm">
                  <button
                    @click="triggerAvatarUpload"
                    class="si group w-22 h-22 sm:w-28 sm:h-28 rounded-full border-2 border-dashed flex items-center justify-center transition-all duration-300 overflow-hidden hover:scale-105"
                    :class="[avatarPreview
                      ? (isDark ? 'border-solid border-white/20' : 'border-solid border-surface-300')
                      : (isDark ? 'border-white/10 hover:border-white/30' : 'border-surface-300 hover:border-surface-400')]"
                    v-bind="si(2)"
                  >
                    <img v-if="avatarPreview" :src="avatarPreview" class="w-full h-full object-cover" />
                    <div v-else class="flex flex-col items-center gap-1 transition-colors" :class="isDark ? 'text-surface-500 group-hover:text-surface-300' : 'text-surface-400 group-hover:text-surface-600'">
                      <span class="material-symbols-rounded text-2xl">add_a_photo</span>
                      <span class="text-[10px]">{{ t('setupWizard.addPhoto') }}</span>
                    </div>
                  </button>
                  <input ref="avatarInput" type="file" accept="image/jpeg,image/png,image/gif,image/webp" class="hidden" @change="handleAvatarSelected" />
                  <div class="si w-full" v-bind="si(3)">
                    <label class="block text-sm font-medium mb-2" :class="labelCls">{{ t('setupWizard.displayName') }}</label>
                    <input
                      v-model="choices.displayName"
                      type="text"
                      :placeholder="auth.userEmail?.split('@')[0]"
                      class="w-full px-3.5 py-2.5 sm:px-4 sm:py-3 rounded-xl border focus:outline-none focus:ring-1 transition-all text-sm sm:text-base"
                      :class="isDark
                        ? 'bg-white/[0.05] border-white/[0.08] text-white placeholder-surface-600 focus:border-white/20 focus:ring-white/10'
                        : 'bg-surface-50 border-surface-200 text-surface-900 placeholder-surface-400 focus:border-primary-300 focus:ring-primary-200'"
                    />
                  </div>
                </div>
              </div>

              <!-- STEP: Ready -->
              <div v-else-if="currentStepId === 'ready'" key="ready" class="flex-1 flex flex-col items-center justify-center px-5 py-8 sm:px-8 sm:py-12 text-center">
                <div class="si" v-bind="si(0)">
                  <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-500 flex items-center justify-center mb-6 sm:mb-8 mx-auto glow-green animate-float">
                    <span class="material-symbols-rounded text-white text-3xl sm:text-4xl">rocket_launch</span>
                  </div>
                </div>
                <h2 class="si text-2xl sm:text-3xl font-bold mb-2 sm:mb-3" :class="[headingCls]" v-bind="si(1)">{{ t('setupWizard.readyTitle') }}</h2>
                <p class="si text-base sm:text-lg max-w-md leading-relaxed mb-6 sm:mb-8" :class="[subCls]" v-bind="si(2)">{{ t('setupWizard.readySubtitle') }}</p>
                <div class="si flex flex-wrap justify-center gap-2" v-bind="si(3)">
                  <div v-if="choices.workMode" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs" :class="chipCls()">
                    <span class="material-symbols-rounded text-sm">{{ choices.workMode === 'solo' ? 'person' : 'groups' }}</span>
                    {{ t(`setupWizard.workMode_${choices.workMode}`) }}
                  </div>
                  <div v-if="choices.workMode === 'team' && choices.role" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs" :class="chipCls()">
                    <span class="material-symbols-rounded text-sm">badge</span>
                    {{ t(`setupWizard.role_${choices.role}`) }}
                  </div>
                  <template v-for="(enabled, slug) in choices.addons" :key="slug">
                    <div v-if="enabled" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs" :class="chipCls()">
                      <span class="material-symbols-rounded text-sm">check_circle</span>
                      {{ t(`setupWizard.mod_${slug}`) }}
                    </div>
                  </template>
                  <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs" :class="chipCls()">
                    <span class="material-symbols-rounded text-sm">{{ selectedPerspective?.icon }}</span>
                    {{ t(`perspective.${choices.perspective}`) }}
                  </div>
                  <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs" :class="chipCls()">
                    <span class="w-3 h-3 rounded-full" :style="{ background: selectedAccentColor?.color }"></span>
                    {{ selectedAccentColor?.name }}
                  </div>
                  <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs" :class="chipCls()">
                    <span class="material-symbols-rounded text-sm">{{ themes.find(th => th.id === choices.theme)?.icon }}</span>
                    {{ t(`setupWizard.theme_${choices.theme}`) }}
                  </div>
                  <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs" :class="chipCls()">
                    <span class="text-xs font-bold">{{ languages.find(l => l.code === choices.language)?.shortCode }}</span>
                    {{ languages.find(l => l.code === choices.language)?.label }}
                  </div>
                  <div v-if="choices.composeStyle === 'inline'" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs" :class="chipCls()">
                    <span class="material-symbols-rounded text-sm">edit_note</span>
                    {{ t('setupWizard.gmailComposer') }}
                  </div>
                  <div v-if="choices.undoSendDelay > 0" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs" :class="chipCls()">
                    <span class="material-symbols-rounded text-sm">undo</span>
                    {{ t('setupWizard.undoSendChip', { seconds: choices.undoSendDelay }) }}
                  </div>
                </div>
              </div>
            </Transition>
          </div>

          <!-- Footer -->
          <div class="px-4 py-3 sm:px-8 sm:py-5 border-t flex items-center justify-between shrink-0" :class="isDark ? 'border-white/[0.06]' : 'border-surface-200/60'">
            <button
              v-if="!isFirst"
              @click="prevStep"
              class="flex items-center gap-1 sm:gap-1.5 px-2.5 py-1.5 sm:px-4 sm:py-2 rounded-full text-sm transition-colors"
              :class="isDark ? 'text-surface-400 hover:text-white' : 'text-surface-500 hover:text-surface-800'"
            >
              <span class="material-symbols-rounded text-lg">chevron_left</span>
              <span class="hidden sm:inline">{{ t('setupWizard.back') }}</span>
            </button>
            <div v-else></div>

            <div class="flex gap-1 sm:gap-1.5">
              <div
                v-for="(s, i) in steps"
                :key="s.id"
                class="h-1.5 rounded-full transition-all duration-500"
                :class="i === currentStep
                  ? (isDark ? 'w-4 sm:w-6 bg-white' : 'w-4 sm:w-6 bg-surface-800')
                  : i < currentStep
                    ? (isDark ? 'w-1.5 bg-white/40' : 'w-1.5 bg-surface-400')
                    : (isDark ? 'w-1.5 bg-white/10' : 'w-1.5 bg-surface-200')"
              ></div>
            </div>

            <button
              @click="nextStep"
              :disabled="saving || !canProceed"
              class="flex items-center gap-1 sm:gap-1.5 px-4 py-2 sm:px-5 sm:py-2.5 rounded-full text-sm font-medium transition-all duration-300"
              :class="[
                isLast
                  ? 'bg-gradient-to-r from-purple-500 via-pink-500 to-orange-500 text-white shadow-lg hover:shadow-xl hover:scale-105'
                  : isDark ? 'bg-white/10 text-white hover:bg-white/20' : 'bg-surface-800 text-white hover:bg-surface-900',
                !canProceed ? 'opacity-40 cursor-not-allowed' : '',
              ]"
            >
              <span v-if="saving" class="material-symbols-rounded text-lg animate-spin">progress_activity</span>
              <template v-else>
                {{ isLast ? t('setupWizard.launch') : isFirst ? t('setupWizard.getStarted') : t('setupWizard.next') }}
                <span class="material-symbols-rounded text-lg">{{ isLast ? 'rocket_launch' : 'chevron_right' }}</span>
              </template>
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
/* ── Stagger Items (animation-based, like landing page) ── */
.si {
  opacity: 0;
  animation: revealUp 0.55s cubic-bezier(0.22, 1, 0.36, 1) forwards;
}
@keyframes revealUp {
  from {
    opacity: 0;
    transform: translateY(22px) scale(0.98);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

/* ── Color preview pulse ── */
.color-preview-pulse {
  animation: colorPulse 2.5s ease-in-out infinite;
}
@keyframes colorPulse {
  0%, 100% { box-shadow: 0 0 0 0 currentColor; transform: scale(1); }
  50% { transform: scale(1.04); }
}

/* ── Blobs ── */
.blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(100px);
  opacity: 0;
  transition: opacity 1.8s ease;
  will-change: transform;
}
.blob-visible { opacity: 1; }

.blob-1 {
  width: 300px; height: 300px;
  background: radial-gradient(circle, rgba(168, 85, 247, 0.22), transparent 70%);
  top: -15%; left: -10%;
  animation: drift1 28s ease-in-out infinite;
}
.blob-2 {
  width: 250px; height: 250px;
  background: radial-gradient(circle, rgba(236, 72, 153, 0.18), transparent 70%);
  bottom: -15%; right: -8%;
  animation: drift2 32s ease-in-out infinite;
}
.blob-3 {
  width: 220px; height: 220px;
  background: radial-gradient(circle, rgba(59, 130, 246, 0.16), transparent 70%);
  top: 35%; right: 25%;
  animation: drift3 26s ease-in-out infinite;
}
.blob-4 {
  width: 200px; height: 200px;
  background: radial-gradient(circle, rgba(20, 184, 166, 0.14), transparent 70%);
  bottom: 20%; left: 15%;
  animation: drift4 30s ease-in-out infinite;
}
.blob-5 {
  width: 180px; height: 180px;
  background: radial-gradient(circle, rgba(249, 115, 22, 0.12), transparent 70%);
  top: 10%; right: 5%;
  animation: drift5 24s ease-in-out infinite;
}
.blob-6 {
  width: 200px; height: 200px;
  background: radial-gradient(circle, rgba(234, 179, 8, 0.10), transparent 70%);
  bottom: 5%; left: 40%;
  animation: drift6 34s ease-in-out infinite;
}

@media (min-width: 640px) {
  .blob-1 { width: 600px; height: 600px; }
  .blob-2 { width: 500px; height: 500px; }
  .blob-3 { width: 450px; height: 450px; }
  .blob-4 { width: 380px; height: 380px; }
  .blob-5 { width: 300px; height: 300px; }
  .blob-6 { width: 350px; height: 350px; }
}

@keyframes drift1 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33%      { transform: translate(60px, 40px) scale(1.08); }
  66%      { transform: translate(-30px, 70px) scale(0.95); }
}
@keyframes drift2 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33%      { transform: translate(-50px, -40px) scale(1.06); }
  66%      { transform: translate(40px, -60px) scale(0.97); }
}
@keyframes drift3 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33%      { transform: translate(40px, -50px) scale(1.1); }
  66%      { transform: translate(-60px, 20px) scale(0.93); }
}
@keyframes drift4 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33%      { transform: translate(-40px, -30px) scale(1.05); }
  66%      { transform: translate(50px, 40px) scale(0.96); }
}
@keyframes drift5 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33%      { transform: translate(30px, 50px) scale(1.07); }
  66%      { transform: translate(-50px, -20px) scale(0.94); }
}
@keyframes drift6 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  33%      { transform: translate(-40px, 30px) scale(1.04); }
  66%      { transform: translate(30px, -50px) scale(0.98); }
}

/* ── Glow & Float ── */
.glow-purple {
  box-shadow: 0 0 40px rgba(168, 85, 247, 0.3), 0 0 80px rgba(168, 85, 247, 0.15);
}
.glow-green {
  box-shadow: 0 0 40px rgba(16, 185, 129, 0.3), 0 0 80px rgba(16, 185, 129, 0.15);
}
.animate-float {
  animation: gentleFloat 4s ease-in-out infinite;
}
@keyframes gentleFloat {
  0%, 100% { transform: translateY(0); }
  50%      { transform: translateY(-8px); }
}

/* ── Selection pulse ring (landing page style) ── */
.selection-pulse {
  position: relative;
}
.selection-pulse::after {
  content: '';
  position: absolute;
  inset: -4px;
  border-radius: inherit;
  border: 2px solid currentColor;
  opacity: 0;
  animation: selectionPulse 2s ease-in-out infinite;
}
@keyframes selectionPulse {
  0%, 100% { opacity: 0; transform: scale(1); }
  50% { opacity: 0.2; transform: scale(1.02); }
}

/* ── Card shadows ── */
.setup-card-dark {
  box-shadow:
    0 0 0 1px rgba(255, 255, 255, 0.05),
    0 25px 60px -12px rgba(0, 0, 0, 0.5),
    0 0 120px rgba(168, 85, 247, 0.05);
}
.setup-card-light {
  box-shadow:
    0 0 0 1px rgba(0, 0, 0, 0.04),
    0 25px 60px -12px rgba(0, 0, 0, 0.12),
    0 0 120px rgba(168, 85, 247, 0.04);
}

/* ── Step transitions ── */
.step-next-enter-active,
.step-prev-enter-active {
  transition: transform 0.35s cubic-bezier(0.22, 1, 0.36, 1);
}
.step-next-leave-active,
.step-prev-leave-active {
  transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}
.step-next-enter-from {
  transform: translateX(40px);
}
.step-next-leave-to {
  opacity: 0;
  transform: translateX(-40px) scale(0.97);
}
.step-prev-enter-from {
  transform: translateX(-40px);
}
.step-prev-leave-to {
  opacity: 0;
  transform: translateX(40px) scale(0.97);
}

/* Custom sizes not in default Tailwind scale */
.w-18 { width: 4.5rem; }
.h-18 { height: 4.5rem; }
.w-22 { width: 5.5rem; }
.h-22 { height: 5.5rem; }
</style>
