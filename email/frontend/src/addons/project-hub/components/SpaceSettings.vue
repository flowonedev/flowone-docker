<script setup>
import { ref, computed, watch } from 'vue'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'

const props = defineProps({
  space: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

const hubStore = useProjectHubStore()

const name = ref(props.space?.name || '')
const color = ref(props.space?.color || '#6366f1')
const icon = ref(props.space?.icon || 'folder_special')
const saving = ref(false)

const isEdit = computed(() => !!props.space?.id)

const colorOptions = [
  '#6366f1', '#8b5cf6', '#ec4899', '#ef4444', '#f97316',
  '#eab308', '#22c55e', '#14b8a6', '#06b6d4', '#3b82f6',
]

watch(() => props.space, (s) => {
  if (s) {
    name.value = s.name || ''
    color.value = s.color || '#6366f1'
    icon.value = s.icon || 'folder_special'
  }
})

async function save() {
  if (!name.value.trim()) return
  saving.value = true
  try {
    if (isEdit.value) {
      await hubStore.updateSpace(props.space.id, {
        name: name.value.trim(),
        color: color.value,
        icon: icon.value,
      })
    } else {
      await hubStore.createSpace({
        name: name.value.trim(),
        color: color.value,
        icon: icon.value,
      })
    }
    emit('saved')
    emit('close')
  } catch (err) {
    console.error('Failed to save space:', err)
  } finally {
    saving.value = false
  }
}

async function handleDelete() {
  if (!props.space?.id) return
  if (!confirm('Delete this space and all its folders?')) return
  try {
    await hubStore.deleteSpace(props.space.id)
    emit('close')
  } catch (err) {
    console.error('Failed to delete space:', err)
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="emit('close')">
    <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md p-6">
      <h3 class="text-lg font-bold text-surface-800 dark:text-surface-100 mb-4">
        {{ isEdit ? 'Edit Space' : 'New Space' }}
      </h3>

      <!-- Name -->
      <div class="mb-4">
        <label class="block text-sm font-medium text-surface-600 dark:text-surface-400 mb-1">Name</label>
        <input
          v-model="name"
          class="w-full px-3 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-200 outline-none focus:ring-2 focus:ring-primary-500"
          placeholder="Space name"
          @keydown.enter="save"
          autofocus
        />
      </div>

      <!-- Color picker -->
      <div class="mb-4">
        <label class="block text-sm font-medium text-surface-600 dark:text-surface-400 mb-2">Color</label>
        <div class="flex items-center gap-2 flex-wrap">
          <button
            v-for="c in colorOptions"
            :key="c"
            class="w-7 h-7 rounded-full border-2 transition-all"
            :class="color === c ? 'border-surface-800 dark:border-white scale-110' : 'border-transparent'"
            :style="{ backgroundColor: c }"
            @click="color = c"
          ></button>
        </div>
      </div>

      <!-- Actions -->
      <div class="flex items-center justify-between mt-6">
        <button
          v-if="isEdit"
          class="text-sm text-red-500 hover:text-red-600 transition-colors"
          @click="handleDelete"
        >
          Delete space
        </button>
        <div v-else></div>

        <div class="flex items-center gap-2">
          <button
            class="px-4 py-2 rounded-full text-sm text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            @click="emit('close')"
          >
            Cancel
          </button>
          <button
            class="px-4 py-2 rounded-full text-sm font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-50"
            :disabled="!name.trim() || saving"
            @click="save"
          >
            {{ saving ? 'Saving...' : (isEdit ? 'Save' : 'Create') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
