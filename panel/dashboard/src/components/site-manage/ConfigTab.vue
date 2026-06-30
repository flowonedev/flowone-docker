<script setup>
// ConfigTab
// ---------------------------------------------------------------
// Site vhost config view + targeted/raw editor. Replaces the
// "config" section of SiteDetailView.vue.
//
// CRITICAL FIX (from the consolidation plan):
//   - Legacy SiteDetailView used `PATCH /sites/{domain}/config` for
//     targeted value updates. That route never existed - only
//     `PUT /api/sites/{domain}/config/values` does. This tab uses
//     the correct PUT endpoint so "Edit Values" mode actually saves.
//   - Raw edit still uses `PUT /api/sites/{domain}/config` (which
//     exists and is correct).

import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import ConfigEditor from '@/components/ConfigEditor.vue'

const props = defineProps({ domain: { type: String, required: true } })
const toast = useToastStore()

const vhostConfig = ref('')
const configPath = ref('')
const editableConfig = ref([])
const loading = ref(false)
const saving = ref(false)
const mode = ref('view') // 'view' | 'raw' | 'values'
const changes = ref({})

const hasChanges = computed(() => Object.keys(changes.value).length > 0)
const rawDraft = ref('')

const fetchConfig = async () => {
  loading.value = true
  try {
    const r = await api.get(`/sites/${encodeURIComponent(props.domain)}/config`)
    if (r.data?.success) {
      vhostConfig.value = r.data.data?.config ?? ''
      configPath.value = r.data.data?.path ?? ''
      editableConfig.value = r.data.data?.sections ?? []
      rawDraft.value = vhostConfig.value
      changes.value = {}
    } else {
      toast.error(r.data?.error || 'Failed to load vhost config')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Failed to load vhost config')
  } finally {
    loading.value = false
  }
}

const trackChange = (section, key, oldValue, newValue) => {
  const id = `${section}.${key}`
  if (String(oldValue) === String(newValue)) {
    delete changes.value[id]
    changes.value = { ...changes.value }
  } else {
    changes.value = {
      ...changes.value,
      [id]: { section, key, oldValue, value: newValue },
    }
  }
}

const saveValues = async () => {
  if (!hasChanges.value) return
  saving.value = true
  try {
    const payload = {
      changes: Object.values(changes.value).map((c) => ({
        section: c.section,
        key: c.key,
        value: c.value,
        oldValue: c.oldValue,
      })),
    }
    // CORRECT endpoint (was PATCH /sites/{d}/config in legacy code -
    // a route that never existed; PUT /sites/{d}/config/values is
    // the real updateConfigValues handler in SiteController).
    const r = await api.put(
      `/sites/${encodeURIComponent(props.domain)}/config/values`,
      payload,
    )
    if (r.data?.success) {
      const applied = r.data.data?.applied?.length ?? 0
      const failed = r.data.data?.failed?.length ?? 0
      if (applied) toast.success(`${applied} value(s) updated`)
      if (failed) toast.warning(`${failed} value(s) failed`)
      await fetchConfig()
      mode.value = 'view'
    } else {
      toast.error(r.data?.error || 'Failed to save values')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Failed to save values')
  } finally {
    saving.value = false
  }
}

const saveRaw = async () => {
  if (rawDraft.value === vhostConfig.value) {
    toast.info('No changes to save')
    return
  }
  saving.value = true
  try {
    const r = await api.put(
      `/sites/${encodeURIComponent(props.domain)}/config`,
      { config: rawDraft.value },
    )
    if (r.data?.success) {
      toast.success('Vhost config saved')
      vhostConfig.value = rawDraft.value
      mode.value = 'view'
    } else {
      toast.error(r.data?.error || 'Failed to save config')
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || 'Failed to save config')
  } finally {
    saving.value = false
  }
}

const cancelEdit = () => {
  rawDraft.value = vhostConfig.value
  changes.value = {}
  mode.value = 'view'
}

onMounted(fetchConfig)
</script>

<template>
  <div class="space-y-4">
    <div class="card">
      <div class="card-header flex items-center justify-between gap-2 flex-wrap">
        <div class="flex items-center gap-2 min-w-0">
          <span class="material-symbols-rounded text-blue-500">code</span>
          <h3 class="font-semibold">Vhost Configuration</h3>
        </div>
        <div class="flex items-center gap-2">
          <button
            class="btn-secondary btn-sm"
            :disabled="loading"
            @click="fetchConfig"
          >
            <span
              class="material-symbols-rounded text-sm"
              :class="{ 'animate-spin': loading }"
            >refresh</span>
          </button>
          <button
            v-if="mode === 'view'"
            class="btn-secondary btn-sm"
            @click="mode = 'values'"
          >
            <span class="material-symbols-rounded text-sm">tune</span>
            Edit Values
          </button>
          <button
            v-if="mode === 'view'"
            class="btn-secondary btn-sm"
            @click="mode = 'raw'"
          >
            <span class="material-symbols-rounded text-sm">edit</span>
            Raw Edit
          </button>
        </div>
      </div>

      <div class="card-body">
        <p
          v-if="configPath"
          class="text-xs font-mono text-surface-500 dark:text-surface-400 mb-3"
        >
          {{ configPath }}
        </p>

        <!-- View mode: color-coded read-only editor with "Check Syntax" button.
             Uses the same ConfigEditor the legacy SiteDetailView used: dark
             CodeMirror theme + comment/key/value/brace/number highlighting +
             a Check Syntax button that hits /system/syntax-check?service=ols
             and shows a green "Syntax OK" or red error banner with any
             warnings or fix hints the server returns. -->
        <div v-if="mode === 'view'">
          <div v-if="loading" class="space-y-1.5">
            <div class="skeleton h-3 w-3/4 rounded" />
            <div class="skeleton h-3 w-5/6 rounded" />
            <div class="skeleton h-3 w-2/3 rounded" />
            <div class="skeleton h-3 w-4/5 rounded" />
            <div class="skeleton h-3 w-3/4 rounded" />
            <div class="skeleton h-3 w-1/2 rounded" />
          </div>
          <ConfigEditor
            v-else
            :model-value="vhostConfig || 'No config loaded'"
            readonly
            height="60vh"
            language="conf"
            service="ols"
            :zen-title="`Virtual Host Configuration - ${props.domain}`"
          />
        </div>

        <!-- Values mode: per-section key-value cells -->
        <div v-if="mode === 'values'" class="space-y-4">
          <div
            v-for="section in editableConfig"
            :key="section.name"
            class="border border-surface-200 dark:border-[rgb(var(--color-border))] rounded-xl p-3"
          >
            <h4 class="font-semibold text-sm mb-2 flex items-center gap-1.5">
              <span class="material-symbols-rounded text-sm text-primary-500">folder_open</span>
              {{ section.name }}
            </h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
              <div v-for="kv in section.values" :key="kv.key">
                <label class="block text-xs text-surface-500 dark:text-surface-400 mb-1">
                  {{ kv.key }}
                </label>
                <input
                  :value="changes[`${section.name}.${kv.key}`]?.value ?? kv.value"
                  type="text"
                  class="input w-full text-xs font-mono"
                  @input="trackChange(section.name, kv.key, kv.value, $event.target.value)"
                />
              </div>
            </div>
          </div>
          <div class="flex items-center gap-2 justify-end">
            <button class="btn-secondary" @click="cancelEdit">Cancel</button>
            <button
              class="btn-primary"
              :disabled="!hasChanges || saving"
              @click="saveValues"
            >
              <span v-if="saving" class="spinner-sm" />
              <span v-else class="material-symbols-rounded text-sm">save</span>
              Save {{ hasChanges ? `(${Object.keys(changes).length})` : '' }}
            </button>
          </div>
        </div>

        <!-- Raw mode: full color-coded editor with live syntax checker.
             The same ConfigEditor used in view-mode, but writable. The
             "Check Syntax" toolbar button validates against the real OLS
             config parser before save - matching the legacy "file
             correction check" workflow. -->
        <div v-if="mode === 'raw'" class="space-y-3">
          <div class="p-3 bg-amber-50 dark:bg-amber-500/10 rounded-xl border border-amber-200 dark:border-amber-500/30">
            <p class="text-xs text-amber-700 dark:text-amber-300 flex items-start gap-2">
              <span class="material-symbols-rounded text-sm mt-0.5">warning</span>
              <span>
                Be careful when editing the configuration. Invalid syntax
                may cause the site to stop working. Use
                <strong>Check Syntax</strong> in the editor toolbar before
                saving to validate the OLS config grammar.
              </span>
            </p>
          </div>
          <ConfigEditor
            v-model="rawDraft"
            height="60vh"
            language="conf"
            service="ols"
            :zen-title="`Virtual Host Configuration - ${props.domain}`"
            @save="saveRaw"
          />
          <div class="flex items-center gap-2 justify-end">
            <button class="btn-secondary" @click="cancelEdit">Cancel</button>
            <button
              class="btn-primary"
              :disabled="saving"
              @click="saveRaw"
            >
              <span v-if="saving" class="spinner-sm" />
              <span v-else class="material-symbols-rounded text-sm">save</span>
              Save Raw Config
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
