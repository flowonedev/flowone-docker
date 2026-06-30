<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Toggle from '@/components/Toggle.vue'

const toast = useToastStore()
const addons = ref([])
const loading = ref(true)
const toggling = ref(null)

async function fetchAddons() {
  try {
    loading.value = true
    const { data } = await api.get('/addons')
    if (data.success) {
      addons.value = data.data.addons || []
    }
  } catch (e) {
    console.error('Failed to load addons:', e)
  } finally {
    loading.value = false
  }
}

async function toggleAddon(addon) {
  try {
    toggling.value = addon.slug
    const { data } = await api.put(`/addons/${addon.slug}/toggle`)
    if (data.success) {
      const idx = addons.value.findIndex(a => a.slug === addon.slug)
      if (idx !== -1) {
        addons.value[idx] = data.data.addon
      }
      toast.success(data.message || 'Addon toggled')
    } else {
      toast.error(data.error || 'Failed to toggle addon')
    }
  } catch (e) {
    toast.error('Failed to toggle addon')
  } finally {
    toggling.value = null
  }
}

onMounted(fetchAddons)
</script>

<template>
  <div class="card overflow-hidden">
    <div class="px-5 py-4 border-b border-surface-100 dark:border-[rgb(var(--color-border))] flex items-center gap-3">
      <span class="material-symbols-rounded text-primary-500 text-xl">extension</span>
      <h3 class="text-sm font-semibold">Email App Addons</h3>
    </div>
    
    <div v-if="loading" class="p-5 text-center text-sm text-surface-400">
      Loading addons...
    </div>
    
    <div v-else-if="addons.length === 0" class="p-5 text-center text-sm text-surface-400">
      No addons available
    </div>
    
    <div v-else class="divide-y divide-surface-100 dark:divide-[rgb(var(--color-border))]">
      <div
        v-for="addon in addons"
        :key="addon.slug"
        class="px-5 py-4 flex items-center gap-4"
      >
        <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0"
          :class="addon.enabled ? 'bg-green-50 dark:bg-green-500/10' : 'bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))]'"
        >
          <span class="material-symbols-rounded text-lg"
            :class="addon.enabled ? 'text-green-600 dark:text-green-400' : 'text-surface-400'"
          >{{ addon.icon || 'extension' }}</span>
        </div>
        
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ addon.name }}</span>
            <span
              class="text-[10px] font-semibold uppercase px-2 py-0.5 rounded-full"
              :class="addon.enabled 
                ? 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400' 
                : 'bg-surface-100 text-surface-500 dark:bg-[rgb(var(--color-surface-elevated))] dark:text-surface-400'"
            >{{ addon.enabled ? 'Active' : 'Disabled' }}</span>
          </div>
          <p class="text-xs text-surface-500 mt-0.5 line-clamp-2">{{ addon.description }}</p>
          <p v-if="addon.enabled && addon.enabled_at" class="text-[10px] text-surface-400 mt-1">
            Enabled {{ new Date(addon.enabled_at).toLocaleDateString() }}
            <template v-if="addon.enabled_by"> by {{ addon.enabled_by }}</template>
          </p>
        </div>
        
        <Toggle 
          :model-value="!!addon.enabled"
          :disabled="toggling === addon.slug"
          @update:model-value="toggleAddon(addon)"
        />
      </div>
    </div>
  </div>
</template>

