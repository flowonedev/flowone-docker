<script setup>
import { ref, computed, onMounted } from 'vue'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import api from '@/services/api'

const props = defineProps({
  mobile: { type: Boolean, default: false }
})
const emit = defineEmits(['close', 'updated'])

const colleaguesStore = useColleaguesStore()

const currentStatus = ref('active')
const customText = ref('')
const selectedPreset = ref(null)
const loading = ref(false)

// Preset status options
const presets = [
  { icon: 'meeting_room', label: 'In a meeting', text: 'In a meeting', clearAfter: '1h' },
  { icon: 'directions_car', label: 'Commuting', text: 'Commuting', clearAfter: '30m' },
  { icon: 'sick', label: 'Out sick', text: 'Out sick today', clearAfter: '8h' },
  { icon: 'beach_access', label: 'Vacationing', text: 'On vacation', clearAfter: null },
  { icon: 'home', label: 'Working remotely', text: 'Working remotely', clearAfter: null },
  { icon: 'lunch_dining', label: 'Lunch break', text: 'At lunch', clearAfter: '1h' },
  { icon: 'headphones', label: 'Focusing', text: 'Heads down, focusing', clearAfter: '2h' },
]

const presenceOptions = [
  { value: 'active', label: 'Active', icon: 'circle', color: 'text-green-500' },
  { value: 'away', label: 'Away', icon: 'circle', color: 'text-amber-500' },
  { value: 'do_not_disturb', label: 'Do Not Disturb', icon: 'do_not_disturb_on', color: 'text-red-500' },
]

const currentProfile = computed(() => colleaguesStore.currentColleague)

onMounted(() => {
  // Load current status from profile
  if (currentProfile.value) {
    currentStatus.value = currentProfile.value.status || currentProfile.value.presence_status || 'active'
    customText.value = currentProfile.value.status_text || ''
    
    // Match saved status_text to a preset so the correct one is highlighted
    if (customText.value) {
      const match = presets.find(p => p.text === customText.value || p.label === customText.value)
      if (match) {
        selectedPreset.value = match
      }
    }
  }
})

async function applyPreset(preset) {
  selectedPreset.value = preset
  customText.value = preset.text
  await saveCustomStatus(preset.text)
}

async function saveCustomStatus(text) {
  loading.value = true
  try {
    await api.put('/colleagues/me', { 
      status_text: text || null 
    })
    // Optimistic local update for instant feedback
    updateLocalStatus(text)
    emit('updated')
  } catch (e) {
    console.error('Failed to save status text:', e)
    // Still update locally so UI doesn't get stuck
    updateLocalStatus(text)
    emit('close')
  } finally {
    loading.value = false
  }
}

// Update both currentColleague and the colleagues list immediately
function updateLocalStatus(text) {
  const statusText = text || null
  if (colleaguesStore.currentColleague) {
    colleaguesStore.currentColleague.status_text = statusText
    // Also update in the colleagues list so sidebar badges refresh instantly
    const idx = colleaguesStore.colleagues.findIndex(c => c.id === colleaguesStore.currentColleague.id)
    if (idx !== -1) {
      colleaguesStore.colleagues[idx] = { ...colleaguesStore.colleagues[idx], status_text: statusText }
    }
  }
}

async function setPresence(status) {
  currentStatus.value = status
  loading.value = true
  try {
    await api.put('/colleagues/me/status', { status })
    colleaguesStore.setMyStatus(status)
    // Also optimistically update the colleagues list + currentColleague
    if (colleaguesStore.currentColleague) {
      colleaguesStore.currentColleague.status = status
      const idx = colleaguesStore.colleagues.findIndex(c => c.id === colleaguesStore.currentColleague.id)
      if (idx !== -1) {
        colleaguesStore.colleagues[idx] = { ...colleaguesStore.colleagues[idx], status }
      }
    }
  } catch (e) {
    console.error('Failed to update status:', e)
  } finally {
    loading.value = false
  }
}

async function clearStatus() {
  customText.value = ''
  selectedPreset.value = null
  await saveCustomStatus('')
}

function handleCustomSubmit() {
  if (customText.value.trim()) {
    saveCustomStatus(customText.value.trim())
  }
}
</script>

<template>
  <div :class="[
    'bg-white dark:bg-surface-800 shadow-xl w-full overflow-hidden',
    mobile ? 'rounded-t-2xl max-h-[85vh] overflow-y-auto' : 'rounded-2xl max-w-sm'
  ]">
    <!-- Header -->
    <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
      <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100">Set Status</h3>
      <button @click="$emit('close')" class="p-1 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors">
        <span class="material-symbols-rounded text-lg text-surface-400">close</span>
      </button>
    </div>

    <!-- Custom text input -->
    <div class="px-4 py-3">
      <div class="flex items-center gap-2">
        <input
          v-model="customText"
          type="text"
          placeholder="What's your status?"
          maxlength="100"
          @keydown.enter="handleCustomSubmit"
          class="flex-1 px-3 py-2.5 text-base bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none text-surface-900 dark:text-surface-100"
        />
        <button
          v-if="customText"
          @click="clearStatus"
          class="p-2 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
          title="Clear status"
        >
          <span class="material-symbols-rounded text-lg text-surface-400">close</span>
        </button>
      </div>
    </div>

    <!-- Presets -->
    <div class="px-2 pb-2">
      <p class="px-2 text-sm font-medium text-surface-500 uppercase tracking-wide mb-1.5">Quick Set</p>
      <div class="space-y-0.5">
        <button
          v-for="preset in presets"
          :key="preset.label"
          @click="applyPreset(preset)"
          :class="[
            'w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-left transition-colors',
            selectedPreset?.label === preset.label
              ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400'
              : 'hover:bg-surface-50 dark:hover:bg-surface-700'
          ]"
        >
          <span class="material-symbols-rounded text-xl text-surface-400">{{ preset.icon }}</span>
          <div class="flex-1 min-w-0">
            <span class="text-base text-surface-900 dark:text-surface-100">{{ preset.label }}</span>
          </div>
          <span v-if="preset.clearAfter" class="text-sm text-surface-400">{{ preset.clearAfter }}</span>
        </button>
      </div>
    </div>

    <!-- Presence status -->
    <div class="px-2 py-2 border-t border-surface-200 dark:border-surface-700">
      <p class="px-2 text-sm font-medium text-surface-500 uppercase tracking-wide mb-1.5">Presence</p>
      <div class="space-y-0.5">
        <button
          v-for="option in presenceOptions"
          :key="option.value"
          @click="setPresence(option.value)"
          :class="[
            'w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-left transition-colors',
            currentStatus === option.value
              ? 'bg-surface-100 dark:bg-surface-700'
              : 'hover:bg-surface-50 dark:hover:bg-surface-700'
          ]"
        >
          <span :class="['material-symbols-rounded text-xl', option.color]">{{ option.icon }}</span>
          <span class="text-base text-surface-900 dark:text-surface-100">{{ option.label }}</span>
          <span v-if="currentStatus === option.value" class="ml-auto material-symbols-rounded text-xl text-primary-500">check</span>
        </button>
      </div>
    </div>

    <!-- Footer -->
    <div class="px-4 py-3 border-t border-surface-200 dark:border-surface-700">
      <button
        v-if="customText.trim()"
        @click="handleCustomSubmit"
        :disabled="loading"
        class="w-full px-4 py-2.5 text-base font-medium bg-primary-500 text-white rounded-full hover:bg-primary-600 transition-colors disabled:opacity-40"
      >
        {{ loading ? 'Saving...' : 'Save Status' }}
      </button>
    </div>
  </div>
</template>

