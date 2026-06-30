<template>
  <div class="h-11 bg-white dark:bg-surface-900 border-b border-surface-200 dark:border-surface-700 flex items-center px-4 gap-3 shrink-0">
    <!-- Back button -->
    <router-link
      :to="{ name: 'automation-hub' }"
      class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-200 transition-colors"
    >
      <span class="material-symbols-rounded text-xl">arrow_back</span>
    </router-link>

    <!-- Workflow name -->
    <div class="flex-1 min-w-0">
      <input
        :value="store.currentWorkflow?.name || ''"
        @input="onNameChange"
        class="bg-transparent text-sm font-semibold text-surface-800 dark:text-surface-100 focus:outline-none w-full max-w-md"
        placeholder="Workflow name"
      />
    </div>

    <!-- Dirty indicator -->
    <div v-if="store.isDirty" class="flex items-center gap-1 text-amber-500 dark:text-amber-400">
      <span class="w-1.5 h-1.5 rounded-full bg-amber-500 dark:bg-amber-400" />
      <span class="text-xs">Unsaved</span>
    </div>

    <!-- Flow animation toggle -->
    <button
      @click="store.toggleFlowAnimation()"
      class="p-1.5 rounded-lg transition-colors"
      :class="store.showFlowAnimation
        ? 'text-primary-500 dark:text-primary-400 bg-primary-50 dark:bg-primary-500/15'
        : 'text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700'"
      :title="store.showFlowAnimation ? 'Flow animation: ON' : 'Flow animation: OFF'"
    >
      <span class="material-symbols-rounded text-lg">animation</span>
    </button>

    <div class="w-px h-5 bg-surface-200 dark:bg-surface-700" />

    <!-- Action buttons -->
    <div class="flex items-center gap-2">
      <!-- Save -->
      <button
        @click="onSave"
        :disabled="!store.isDirty || saving"
        class="flex items-center gap-1.5 px-4 py-1.5 rounded-full text-sm font-medium transition-colors disabled:opacity-40"
        :class="store.isDirty ? 'bg-primary-500 text-white hover:bg-primary-600' : 'bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-300'"
      >
        <span class="material-symbols-rounded text-lg">{{ saving ? 'progress_activity' : 'save' }}</span>
        <span>Save</span>
      </button>

      <!-- Run (for manual trigger workflows) -->
      <button
        v-if="hasManualTrigger"
        @click="$emit('run')"
        :disabled="running"
        class="flex items-center gap-1.5 px-4 py-1.5 rounded-full text-sm font-medium bg-emerald-500 text-white hover:bg-emerald-600 transition-colors disabled:opacity-60"
      >
        <span class="material-symbols-rounded text-lg">{{ running ? 'progress_activity' : 'play_arrow' }}</span>
        <span>Run</span>
      </button>

      <!-- Test -->
      <button
        @click="$emit('test')"
        class="flex items-center gap-1.5 px-4 py-1.5 rounded-full text-sm font-medium bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-200 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
      >
        <span class="material-symbols-rounded text-lg">play_arrow</span>
        <span>Test</span>
      </button>

      <!-- Toggle active -->
      <button
        @click="$emit('toggle-active')"
        class="flex items-center gap-1.5 px-4 py-1.5 rounded-full text-sm font-medium transition-colors"
        :class="store.currentWorkflow?.is_active
          ? 'bg-emerald-50 dark:bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-100 dark:hover:bg-emerald-500/25'
          : 'bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600'"
      >
        <span class="material-symbols-rounded text-lg">{{ store.currentWorkflow?.is_active ? 'toggle_on' : 'toggle_off' }}</span>
        <span>{{ store.currentWorkflow?.is_active ? 'Active' : 'Inactive' }}</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useAutomationHubStore } from '../../stores/automationHub'

const store = useAutomationHubStore()
const emit = defineEmits(['test', 'run', 'toggle-active'])

const hasManualTrigger = computed(() =>
  store.nodesArray.some(n => n.type === 'trigger.manual')
)

const running = ref(false)
defineExpose({ running })

const saving = ref(false)

function onNameChange(e) {
  if (store.currentWorkflow) {
    store.currentWorkflow.name = e.target.value
    store.isDirty = true
  }
}

async function onSave() {
  saving.value = true
  try {
    await store.saveWorkflow()
  } catch (err) {
    console.error('[AutomationHub] Save failed:', err)
  } finally {
    saving.value = false
  }
}
</script>
