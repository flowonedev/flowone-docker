<script setup>
// WordPressTab
// ---------------------------------------------------------------
// WordPress management for the V2 site management view. Replaces
// the "wordpress" section of SiteDetailView.vue.
//
// All endpoints are under /api/wordpress/{domain}/* and are
// domain-scoped + V2-compatible already; this tab just rebinds
// them onto focused state.

import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Toggle from '@/components/Toggle.vue'

const props = defineProps({ domain: { type: String, required: true } })
const toast = useToastStore()

const wpInfo = ref(null)
const plugins = ref([])
const themes = ref([])
const core = ref(null)
const users = ref([])
const loading = ref(false)
const action = ref(null) // tracks which action is currently running

const hasWordPress = computed(() => wpInfo.value !== null)
const pluginsWithUpdates = computed(() => plugins.value.filter((p) =>
  p.update_available || p.update === 'available'))
const themesWithUpdates = computed(() => themes.value.filter((t) => t.update === 'available'))
const coreUpdateAvailable = computed(() =>
  (core.value?.updates ?? []).length > 0
  || (wpInfo.value?.core_updates ?? []).length > 0)

// File editing state: editing is allowed when wp-config.php and .htaccess are
// writable AND the plugin/theme editor is not disabled (DISALLOW_FILE_EDIT).
// `secure: true` from the backend means the file is locked (read-only).
const fileEditingAllowed = computed(() => {
  const p = wpInfo.value?.permissions
  if (!p) return false
  const wpConfigWritable = p['wp-config.php'] ? !p['wp-config.php'].secure : true
  const htaccessWritable = p['.htaccess'] ? !p['.htaccess'].secure : true
  const editorLocked = p.file_editor_locked === true
  return wpConfigWritable && htaccessWritable && !editorLocked
})

const fetchAll = async () => {
  loading.value = true
  try {
    const r = await api.get(`/wordpress/${encodeURIComponent(props.domain)}`)
    wpInfo.value = r.data?.success && r.data?.data?.installed !== false
      ? r.data.data
      : null
    if (!wpInfo.value) {
      plugins.value = []
      themes.value = []
      users.value = []
      core.value = null
      return
    }
    const [pl, th, co, us] = await Promise.allSettled([
      api.get(`/wordpress/${encodeURIComponent(props.domain)}/plugins`),
      api.get(`/wordpress/${encodeURIComponent(props.domain)}/themes`),
      api.get(`/wordpress/${encodeURIComponent(props.domain)}/core`),
      api.get(`/wordpress/${encodeURIComponent(props.domain)}/users?limit=1000`),
    ])
    plugins.value = pl.status === 'fulfilled' && pl.value.data?.success
      ? pl.value.data.data?.plugins ?? []
      : []
    themes.value = th.status === 'fulfilled' && th.value.data?.success
      ? th.value.data.data?.themes ?? []
      : []
    core.value = co.status === 'fulfilled' && co.value.data?.success
      ? co.value.data.data
      : null
    users.value = us.status === 'fulfilled' && us.value.data?.success
      ? us.value.data.data?.users ?? []
      : []
  } catch (e) {
    wpInfo.value = null
  } finally {
    loading.value = false
  }
}

const run = async (key, url, body = {}, method = 'post', toastOk = 'Done') => {
  action.value = key
  try {
    const r = await api[method](url, body)
    if (r.data?.success) {
      toast.success(r.data.message || toastOk)
      await fetchAll()
    } else {
      toast.error(r.data?.error || `${key} failed`)
    }
  } catch (e) {
    toast.error(e?.response?.data?.error || `${key} failed`)
  } finally {
    action.value = null
  }
}

const updatePlugin = (slug) => run(
  `plugin:${slug}`,
  `/wordpress/${encodeURIComponent(props.domain)}/plugins/update`,
  { slug },
  'post',
  `Plugin ${slug} updated`,
)

const updateAllPlugins = () => run(
  'plugins-all',
  `/wordpress/${encodeURIComponent(props.domain)}/plugins/update-all`,
  {}, 'post', 'All plugins updated')

const updateAllThemes = () => run(
  'themes-all',
  `/wordpress/${encodeURIComponent(props.domain)}/themes/update-all`,
  {}, 'post', 'All themes updated')

const updateCore = () => run(
  'core',
  `/wordpress/${encodeURIComponent(props.domain)}/core/update`,
  {}, 'post', 'WordPress core updated')

const toggleMaintenance = () => run(
  'maintenance',
  `/wordpress/${encodeURIComponent(props.domain)}/maintenance`,
  { enabled: !wpInfo.value?.maintenance_mode },
  'post',
  'Maintenance mode toggled',
)

const secure = () => run(
  'secure',
  `/wordpress/${encodeURIComponent(props.domain)}/secure`,
  {}, 'post', 'WordPress hardened')

const fixPermissions = () => run(
  'perms',
  `/wordpress/${encodeURIComponent(props.domain)}/permissions`,
  {}, 'post', 'Permissions fixed')

const unsecure = () => run(
  'unsecure',
  `/wordpress/${encodeURIComponent(props.domain)}/unsecure`,
  {}, 'post', 'File editing enabled')

// Toggle ON  -> allow editing (unlock files + remove DISALLOW_FILE_EDIT)
// Toggle OFF -> lock files again (full harden)
const toggleFileEditing = (next) => {
  if (action.value !== null) return
  next ? unsecure() : secure()
}

// WP-CLI / DB return `roles` as a comma-separated string (sometimes an
// array or a role->label map), so normalise to a display string instead
// of assuming Array.prototype.join exists.
const formatRoles = (user) => {
  const r = user?.roles
  if (Array.isArray(r)) return r.join(', ')
  if (r && typeof r === 'object') return Object.values(r).join(', ')
  if (typeof r === 'string') {
    return r.split(',').map((s) => s.trim()).filter(Boolean).join(', ')
  }
  return ''
}

const toggleUser = (user) => run(
  `user:${user.user_login}`,
  `/wordpress/${encodeURIComponent(props.domain)}/users/${user.is_disabled ? 'enable' : 'disable'}`,
  { user_login: user.user_login },
  'post',
  `User ${user.user_login} toggled`,
)

onMounted(fetchAll)
</script>

<template>
  <div class="space-y-5">
    <div v-if="loading" class="card">
      <div class="card-body space-y-3">
        <div class="skeleton h-6 w-1/3 rounded" />
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div class="skeleton h-20 w-full rounded-xl" />
          <div class="skeleton h-20 w-full rounded-xl" />
          <div class="skeleton h-20 w-full rounded-xl" />
          <div class="skeleton h-20 w-full rounded-xl" />
        </div>
      </div>
    </div>

    <div v-else-if="!hasWordPress" class="card">
      <div class="card-body py-12 text-center">
        <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-4 block">
          edit_note
        </span>
        <h4 class="text-lg font-medium mb-2">WordPress Not Detected</h4>
        <p class="text-surface-500 dark:text-surface-400 mb-4 max-w-md mx-auto">
          No WordPress installation found for this site.
        </p>
        <router-link to="/apps" class="btn-primary inline-flex items-center">
          <span class="material-symbols-rounded">add</span>
          Install WordPress
        </router-link>
      </div>
    </div>

    <template v-else>
      <!-- ─── Header card ─── -->
      <div class="card">
        <div class="card-header flex items-center justify-between gap-2 flex-wrap">
          <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-indigo-500">extension</span>
            <h3 class="font-semibold">WordPress {{ wpInfo.version ?? '' }}</h3>
            <span
              v-if="wpInfo.maintenance_mode"
              class="badge badge-warning ml-1"
              title="Maintenance mode enabled"
            >
              <span class="material-symbols-rounded text-xs">construction</span>
              Maintenance
            </span>
          </div>
          <div class="flex items-center gap-2">
            <a
              v-if="wpInfo.admin_url"
              :href="wpInfo.admin_url"
              target="_blank"
              rel="noopener"
              class="btn-secondary btn-sm"
            >
              <span class="material-symbols-rounded text-sm">open_in_new</span>
              Open wp-admin
            </a>
            <button class="btn-secondary btn-sm" :disabled="loading" @click="fetchAll">
              <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': loading }">refresh</span>
            </button>
          </div>
        </div>
        <div class="card-body space-y-4">
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
            <div
              class="p-3 rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))]
                     bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))]"
            >
              <p class="text-xs uppercase tracking-wide text-surface-500 dark:text-surface-400 mb-1">
                Plugins
              </p>
              <p class="text-2xl font-bold tabular-nums">{{ plugins.length }}</p>
              <p v-if="pluginsWithUpdates.length" class="text-xs text-amber-500">
                {{ pluginsWithUpdates.length }} updates
              </p>
            </div>
            <div
              class="p-3 rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))]
                     bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))]"
            >
              <p class="text-xs uppercase tracking-wide text-surface-500 dark:text-surface-400 mb-1">
                Themes
              </p>
              <p class="text-2xl font-bold tabular-nums">{{ themes.length }}</p>
              <p v-if="themesWithUpdates.length" class="text-xs text-amber-500">
                {{ themesWithUpdates.length }} updates
              </p>
            </div>
            <div
              class="p-3 rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))]
                     bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))]"
            >
              <p class="text-xs uppercase tracking-wide text-surface-500 dark:text-surface-400 mb-1">
                Users
              </p>
              <p class="text-2xl font-bold tabular-nums">{{ users.length }}</p>
            </div>
            <div
              class="p-3 rounded-xl border"
              :class="coreUpdateAvailable
                ? 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10'
                : 'border-emerald-200 dark:border-emerald-500/30 bg-emerald-50 dark:bg-emerald-500/10'"
            >
              <p class="text-xs uppercase tracking-wide text-surface-500 dark:text-surface-400 mb-1">
                Core
              </p>
              <p
                class="text-sm font-bold"
                :class="coreUpdateAvailable ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-300'"
              >
                {{ coreUpdateAvailable ? 'Update available' : 'Up to date' }}
              </p>
            </div>
          </div>

          <div class="action-buttons">
            <button
              class="btn-primary btn-sm"
              :disabled="action !== null"
              @click="updateCore"
            >
              <span v-if="action === 'core'" class="spinner-sm" />
              <span v-else class="material-symbols-rounded text-sm">system_update_alt</span>
              Update Core
            </button>
            <button class="btn-secondary btn-sm" :disabled="action !== null" @click="updateAllPlugins">
              <span v-if="action === 'plugins-all'" class="spinner-sm" />
              <span v-else class="material-symbols-rounded text-sm">extension</span>
              Update Plugins
            </button>
            <button class="btn-secondary btn-sm" :disabled="action !== null" @click="updateAllThemes">
              <span v-if="action === 'themes-all'" class="spinner-sm" />
              <span v-else class="material-symbols-rounded text-sm">palette</span>
              Update Themes
            </button>
            <button class="btn-secondary btn-sm" :disabled="action !== null" @click="toggleMaintenance">
              <span v-if="action === 'maintenance'" class="spinner-sm" />
              <span v-else class="material-symbols-rounded text-sm">construction</span>
              {{ wpInfo.maintenance_mode ? 'Disable' : 'Enable' }} Maintenance
            </button>
            <button class="btn-secondary btn-sm" :disabled="action !== null" @click="secure">
              <span v-if="action === 'secure'" class="spinner-sm" />
              <span v-else class="material-symbols-rounded text-sm">security</span>
              Harden
            </button>
            <button class="btn-secondary btn-sm" :disabled="action !== null" @click="fixPermissions">
              <span v-if="action === 'perms'" class="spinner-sm" />
              <span v-else class="material-symbols-rounded text-sm">lock</span>
              Fix Permissions
            </button>
          </div>

          <!-- File protection: allow editing wp-config, .htaccess and plugins/themes -->
          <div
            class="flex items-start justify-between gap-3 p-3 rounded-xl border
                   border-surface-200 dark:border-[rgb(var(--color-border))]
                   bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))]"
          >
            <div class="min-w-0">
              <p
                class="text-sm font-medium cursor-pointer flex items-center gap-1.5"
                @click="toggleFileEditing(!fileEditingAllowed)"
              >
                <span class="material-symbols-rounded text-base text-surface-400">
                  {{ fileEditingAllowed ? 'lock_open' : 'lock' }}
                </span>
                Allow file editing
              </p>
              <p class="text-xs text-surface-500 dark:text-surface-400 mt-1">
                Unlocks <code class="font-mono">wp-config.php</code>,
                <code class="font-mono">.htaccess</code> and the plugin/theme editor
                for this site. Turn off to harden (recommended for production).
              </p>
            </div>
            <div class="flex items-center pt-0.5 shrink-0">
              <span v-if="action === 'unsecure' || action === 'secure'" class="spinner-sm mr-2" />
              <Toggle
                :model-value="fileEditingAllowed"
                :disabled="action !== null"
                @update:model-value="toggleFileEditing"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- ─── Plugins with updates ─── -->
      <div v-if="pluginsWithUpdates.length" class="card">
        <div class="card-header flex items-center gap-2">
          <span class="material-symbols-rounded text-amber-500">system_update</span>
          <h3 class="font-semibold">Plugins with updates</h3>
          <span class="badge badge-warning ml-1">{{ pluginsWithUpdates.length }}</span>
        </div>
        <div class="card-body">
          <ul class="space-y-2">
            <li
              v-for="p in pluginsWithUpdates"
              :key="p.slug ?? p.name"
              class="flex items-center justify-between p-3 rounded-xl text-sm
                     border border-amber-200 dark:border-amber-500/30
                     bg-amber-50 dark:bg-amber-500/5"
            >
              <div class="flex items-center gap-2 min-w-0">
                <span class="material-symbols-rounded text-amber-500 text-base shrink-0">
                  extension
                </span>
                <div class="min-w-0">
                  <p class="font-medium truncate">{{ p.name }}</p>
                  <p class="text-xs text-surface-500 dark:text-surface-400 font-mono">
                    {{ p.version }} → {{ p.update_version ?? '?' }}
                  </p>
                </div>
              </div>
              <button
                class="btn-primary btn-sm shrink-0"
                :disabled="action === `plugin:${p.slug}`"
                @click="updatePlugin(p.slug)"
              >
                <span v-if="action === `plugin:${p.slug}`" class="spinner-sm" />
                <span v-else class="material-symbols-rounded text-sm">system_update_alt</span>
                Update
              </button>
            </li>
          </ul>
        </div>
      </div>

      <!-- ─── Users ─── -->
      <div v-if="users.length" class="card">
        <div class="card-header flex items-center gap-2">
          <span class="material-symbols-rounded text-primary-500">group</span>
          <h3 class="font-semibold">Users ({{ users.length }})</h3>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th class="text-left">Login</th>
                  <th class="text-left">Email</th>
                  <th class="text-left">Role</th>
                  <th class="text-right">Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="u in users.slice(0, 50)" :key="u.ID ?? u.user_login">
                  <td class="font-mono text-xs">{{ u.user_login }}</td>
                  <td class="text-xs">{{ u.user_email }}</td>
                  <td class="text-xs">{{ formatRoles(u) }}</td>
                  <td class="text-right">
                    <button
                      class="btn-ghost btn-sm"
                      :disabled="action === `user:${u.user_login}`"
                      @click="toggleUser(u)"
                    >
                      <span v-if="action === `user:${u.user_login}`" class="spinner-sm" />
                      {{ u.is_disabled ? 'Enable' : 'Disable' }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
