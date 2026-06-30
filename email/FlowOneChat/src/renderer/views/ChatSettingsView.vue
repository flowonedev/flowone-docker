<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import { useToastStore } from '@/stores/toast'
import { useSettingsStore } from '@/stores/settings'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import api from '@/services/api'
import AppHeader from '@/components/shared/AppHeader.vue'
import UserAvatar from '@/components/shared/UserAvatar.vue'

const router = useRouter()
const auth = useAuthStore()
const theme = useThemeStore()
const toast = useToastStore()
const settingsStore = useSettingsStore()
const colleaguesStore = useColleaguesStore()

const loading = ref(true)
const saving = ref(false)
const avatarUploading = ref(false)
const avatarFileInput = ref(null)
const avatarPreview = ref(null)

const settings = ref({
  display_name: '',
  theme: 'system',
  accent_color: 'green',
})

const currentAvatarUrl = computed(() => {
  return avatarPreview.value || auth.user?.avatar_url || colleaguesStore.getAvatarUrl(colleaguesStore.currentColleague) || null
})

onMounted(async () => {
  try {
    await settingsStore.fetchSettings()
    settings.value.display_name = settingsStore.settings?.display_name || auth.displayName || ''
    settings.value.theme = theme.theme || 'system'
    settings.value.accent_color = theme.accentColor || 'green'
  } catch (e) {
    console.error('Failed to load settings', e)
  } finally {
    loading.value = false
  }
})

function triggerAvatarUpload() {
  avatarFileInput.value?.click()
}

async function handleAvatarFileSelected(event) {
  const file = event.target.files?.[0]
  if (!file) return

  const maxSize = 5 * 1024 * 1024
  if (file.size > maxSize) {
    toast.error('File too large. Maximum 5 MB.')
    return
  }

  avatarPreview.value = URL.createObjectURL(file)
  avatarUploading.value = true
  try {
    const formData = new FormData()
    formData.append('avatar', file)
    // Let the browser set the multipart Content-Type (with boundary) automatically
    const response = await api.post('/colleagues/me/avatar', formData)
    if (response.data.success) {
      toast.success('Avatar updated')
      if (auth.user) {
        auth.user.avatar_url = response.data.data.avatar_url
      }
      colleaguesStore.fetchMe()
      avatarPreview.value = null
    } else {
      toast.error(response.data.message || 'Failed to upload avatar')
      avatarPreview.value = null
    }
  } catch (e) {
    toast.error('Failed to upload avatar')
    avatarPreview.value = null
  } finally {
    avatarUploading.value = false
    if (avatarFileInput.value) avatarFileInput.value.value = ''
  }
}

async function removeAvatar() {
  avatarUploading.value = true
  try {
    const response = await api.delete('/colleagues/me/avatar')
    if (response.data.success) {
      toast.success('Avatar removed')
      avatarPreview.value = null
      if (auth.user) {
        auth.user.avatar_url = null
      }
      colleaguesStore.fetchMe()
    }
  } catch (e) {
    toast.error('Failed to remove avatar')
  } finally {
    avatarUploading.value = false
  }
}

async function saveSettings() {
  saving.value = true
  try {
    const settingsToSave = {
      ...settingsStore.settings,
      display_name: settings.value.display_name,
      theme: theme.theme,
      accent_color: theme.accentColor,
    }
    const result = await settingsStore.updateSettings(settingsToSave)
    if (result.success) {
      toast.success('Settings saved')
    } else {
      toast.error(result.error || 'Failed to save settings')
    }
  } catch (e) {
    toast.error('Failed to save settings')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="h-full flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint">
    <AppHeader current-view="settings" icon="settings" title="Settings" />

    <div class="flex-1 overflow-y-auto">
      <div class="max-w-2xl mx-auto px-4 py-6 space-y-8">
        <div v-if="loading" class="flex items-center justify-center py-12">
          <span class="spinner text-primary-500"></span>
        </div>

        <template v-else>
          <!-- PROFILE SECTION -->
          <section>
            <div class="flex items-center gap-2 mb-4">
              <span class="material-symbols-rounded text-primary-500">account_circle</span>
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Profile</h2>
            </div>

            <div class="card p-6">
              <div class="flex items-center gap-6 mb-6">
                <div class="relative group">
                  <UserAvatar
                    :email="auth.userEmail"
                    :name="auth.displayName"
                    :avatar-url="currentAvatarUrl || ''"
                    size="3xl"
                  />
                  <button
                    @click="triggerAvatarUpload"
                    class="absolute inset-0 rounded-full bg-black/50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer"
                  >
                    <span class="material-symbols-rounded text-white text-2xl">photo_camera</span>
                  </button>
                  <div
                    v-if="avatarUploading"
                    class="absolute inset-0 rounded-full bg-black/60 flex items-center justify-center"
                  >
                    <span class="spinner text-white"></span>
                  </div>
                </div>

                <div class="flex-1">
                  <h3 class="text-base font-medium text-surface-900 dark:text-surface-100">
                    {{ auth.displayName }}
                  </h3>
                  <p class="text-sm text-surface-500 mb-3">{{ auth.userEmail }}</p>

                  <div class="flex items-center gap-2">
                    <button
                      @click="triggerAvatarUpload"
                      :disabled="avatarUploading"
                      class="px-4 py-1.5 text-sm font-medium rounded-full bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-50"
                    >
                      <span class="material-symbols-rounded text-sm mr-1 align-text-bottom">upload</span>
                      {{ currentAvatarUrl ? 'Change Photo' : 'Upload Photo' }}
                    </button>
                    <button
                      v-if="currentAvatarUrl"
                      @click="removeAvatar"
                      :disabled="avatarUploading"
                      class="px-4 py-1.5 text-sm font-medium rounded-full border border-surface-300 dark:border-surface-600 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors disabled:opacity-50"
                    >
                      <span class="material-symbols-rounded text-sm mr-1 align-text-bottom">delete</span>
                      Remove
                    </button>
                  </div>
                  <p class="mt-2 text-xs text-surface-400">JPEG, PNG, GIF or WebP. Max 5 MB.</p>
                </div>
              </div>

              <input
                ref="avatarFileInput"
                type="file"
                accept="image/jpeg,image/png,image/gif,image/webp"
                class="hidden"
                @change="handleAvatarFileSelected"
              />

              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  Display Name
                </label>
                <input
                  v-model="settings.display_name"
                  type="text"
                  class="input"
                  placeholder="Your name"
                />
              </div>
            </div>
          </section>

          <!-- APPEARANCE SECTION -->
          <section>
            <div class="flex items-center gap-2 mb-4">
              <span class="material-symbols-rounded text-primary-500">palette</span>
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Appearance</h2>
            </div>

            <div class="card p-6">
              <div class="mb-6">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  Theme
                </label>
                <select v-model="settings.theme" class="input" @change="theme.setTheme(settings.theme)">
                  <option value="system">System</option>
                  <option value="light">Light</option>
                  <option value="dark">Dark</option>
                </select>
              </div>

              <!-- Accent Color Picker -->
              <div class="mb-6">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-3">
                  Accent Color
                </label>
                <div class="flex flex-wrap gap-3">
                  <button
                    v-for="accent in theme.availableAccents"
                    :key="accent.id"
                    @click="theme.setAccentColor(accent.id)"
                    :title="accent.name"
                    :class="[
                      'w-10 h-10 rounded-full transition-all duration-200 flex items-center justify-center',
                      'ring-2 ring-offset-2 dark:ring-offset-surface-800',
                      theme.accentColor === accent.id 
                        ? 'ring-surface-900 dark:ring-white scale-110' 
                        : 'ring-transparent hover:scale-105'
                    ]"
                    :style="{ background: accent.color }"
                  >
                    <span 
                      v-if="theme.accentColor === accent.id" 
                      class="material-symbols-rounded text-lg drop-shadow-md"
                      :class="accent.id === 'mono' ? 'text-primary-500' : 'text-white'"
                    >check</span>
                  </button>
                </div>
              </div>

              <!-- Ambient Background Toggle -->
              <div class="flex items-center justify-between pt-4 border-t border-surface-100 dark:border-surface-700/50">
                <div>
                  <p class="text-sm font-medium text-surface-700 dark:text-surface-300">Ambient Background</p>
                  <p class="text-xs text-surface-500">Apply a subtle color tint based on your accent</p>
                </div>
                <button
                  @click="theme.setAmbientBackground(!theme.ambientBackground)"
                  :class="['w-12 h-6 rounded-full transition-colors relative shrink-0', theme.ambientBackground ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
                >
                  <span 
                    :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', theme.ambientBackground ? 'translate-x-6' : 'translate-x-0']"
                  ></span>
                </button>
              </div>
            </div>
          </section>

          <!-- SAVE BUTTON -->
          <div class="sticky bottom-0 py-4 bg-surface-50 dark:bg-surface-900">
            <button
              @click="saveSettings"
              class="btn-primary shadow-lg px-8"
              :disabled="saving"
            >
              <span v-if="saving" class="spinner"></span>
              <span class="material-symbols-rounded">save</span>
              Save Settings
            </button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
