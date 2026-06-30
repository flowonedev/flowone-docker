<script setup>
/**
 * CrmSharingView - Manage CRM internal sharing
 * Share your CRM with colleagues or groups, view CRMs shared with you.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import AppHeader from '@/components/shared/AppHeader.vue'
import ViewInfoButton from '@/components/shared/ViewInfoButton.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import CrmSidebar from '../components/CrmSidebar.vue'

const toast = useToastStore()
const colleaguesStore = useColleaguesStore()

// State
const loading = ref(true)
const activeTab = ref('my_shares') // my_shares | shared_with_me | activity
const shareMode = ref('company') // company | groups | manual

// Data
const myShares = ref({ individual: [], groups: [] })
const sharedWithMe = ref({ individual: [], from_groups: [] })
const activityLog = ref([])
const accessibleOwners = ref([])

// Company users
const companyUsers = ref([])
const loadingCompanyUsers = ref(false)
const searchQuery = ref('')

// New share form
const newShareEmail = ref('')
const newSharePermission = ref('viewer')
const selectedGroupId = ref(null)
const saving = ref(false)

// Computed
const filteredCompanyUsers = computed(() => {
  if (!searchQuery.value) return companyUsers.value
  const q = searchQuery.value.toLowerCase()
  return companyUsers.value.filter(u => u.toLowerCase().includes(q))
})

const alreadySharedEmails = computed(() => {
  return new Set(myShares.value.individual.map(s => s.shared_with_email.toLowerCase()))
})

const alreadySharedGroupIds = computed(() => {
  return new Set(myShares.value.groups.map(g => g.group_id))
})

const isMobile = ref(false)
function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

// Load data
onMounted(async () => {
  loading.value = true
  try {
    await Promise.all([
      fetchShares(),
      loadCompanyUsers(),
      colleaguesStore.fetchGroups()
    ])
  } finally {
    loading.value = false
  }
  checkMobile()
  window.addEventListener('resize', checkMobile)
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})

async function fetchShares() {
  try {
    const res = await api.get('/crm/sharing')
    if (res.data?.success) {
      myShares.value = res.data.data.my_shares || { individual: [], groups: [] }
      sharedWithMe.value = res.data.data.shared_with_me || { individual: [], from_groups: [] }
      accessibleOwners.value = res.data.data.accessible_owners || []
    }
  } catch (e) {
    toast.error('Failed to load sharing data')
  }
}

async function loadCompanyUsers() {
  loadingCompanyUsers.value = true
  try {
    const res = await api.get('/boards/company-users')
    if (res.data?.success) {
      companyUsers.value = res.data.data.users || []
    }
  } catch (e) {
    companyUsers.value = []
  } finally {
    loadingCompanyUsers.value = false
  }
}

async function fetchActivity() {
  try {
    const res = await api.get('/crm/sharing/activity')
    if (res.data?.success) {
      activityLog.value = res.data.data.activity || []
    }
  } catch (e) {
    toast.error('Failed to load activity')
  }
}

// Share with colleague
async function shareWithColleague(email) {
  saving.value = true
  try {
    await api.post('/crm/sharing/colleague', {
      shared_with_email: email.toLowerCase(),
      permission: newSharePermission.value
    })
    toast.success(`Shared CRM with ${email}`)
    newShareEmail.value = ''
    await fetchShares()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to share')
  } finally {
    saving.value = false
  }
}

// Share with group
async function shareWithGroup() {
  if (!selectedGroupId.value) return
  saving.value = true
  try {
    await api.post('/crm/sharing/group', {
      group_id: selectedGroupId.value,
      permission: newSharePermission.value
    })
    toast.success('Shared CRM with group')
    selectedGroupId.value = null
    await fetchShares()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to share with group')
  } finally {
    saving.value = false
  }
}

// Update permission
async function updatePermission(share, type, newPerm) {
  try {
    await api.put(`/crm/sharing/${share.id}`, {
      type,
      permission: newPerm
    })
    toast.success('Permission updated')
    await fetchShares()
  } catch (e) {
    toast.error('Failed to update permission')
  }
}

// Revoke share
async function revokeShare(share, type) {
  try {
    await api.delete(`/crm/sharing/${share.id}?type=${type}`)
    toast.success('Access revoked')
    await fetchShares()
  } catch (e) {
    toast.error('Failed to revoke access')
  }
}

// Helpers
function permissionColor(perm) {
  return {
    viewer: 'text-blue-700 dark:text-blue-400 bg-blue-100 dark:bg-blue-500/10',
    editor: 'text-amber-700 dark:text-amber-400 bg-amber-100 dark:bg-amber-500/10',
    manager: 'text-green-700 dark:text-green-400 bg-green-100 dark:bg-green-500/10',
  }[perm] || 'text-surface-600 dark:text-surface-400'
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <AppHeader title="CRM Sharing" icon="share">
      <template #title-badge>
        <ViewInfoButton view-key="crmSharing" />
      </template>
    </AppHeader>

    <div :class="isMobile ? 'flex-1 flex flex-col' : 'flex-1 flex overflow-hidden'">
      <CrmSidebar />

      <div :class="isMobile ? 'flex-1 min-w-0' : 'flex-1 overflow-y-auto'">
      <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8">
        <!-- Tab Switcher -->
        <div class="flex gap-2 mb-8">
          <button
            v-for="tab in [
              { key: 'my_shares', label: 'My Shares', icon: 'share' },
              { key: 'shared_with_me', label: 'Shared With Me', icon: 'group' },
              { key: 'activity', label: 'Activity', icon: 'history' }
            ]"
            :key="tab.key"
            @click="activeTab = tab.key; if (tab.key === 'activity') fetchActivity()"
            :class="[
              'flex items-center gap-2 px-5 py-2.5 rounded-full text-sm font-medium transition-all',
              activeTab === tab.key
                ? 'bg-primary-600 text-white'
                : 'bg-surface-200 dark:bg-surface-800 text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-white hover:bg-surface-300 dark:hover:bg-surface-700'
            ]"
          >
            <span class="material-symbols-rounded text-[18px]">{{ tab.icon }}</span>
            {{ tab.label }}
          </button>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="flex items-center justify-center py-20">
          <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
        </div>

        <!-- My Shares Tab -->
        <template v-else-if="activeTab === 'my_shares'">
          <!-- Add New Share Section -->
          <div class="bg-white dark:bg-surface-800 rounded-2xl p-6 mb-6 border border-surface-200 dark:border-surface-700">
            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2 text-surface-900 dark:text-white">
              <span class="material-symbols-rounded text-primary-500">person_add</span>
              Share Your CRM
            </h3>

            <!-- Share mode tabs -->
            <div class="flex gap-2 mb-4">
              <button
                v-for="mode in [
                  { key: 'company', label: 'Company Users' },
                  { key: 'groups', label: 'Groups' },
                  { key: 'manual', label: 'Manual Email' }
                ]"
                :key="mode.key"
                @click="shareMode = mode.key"
                :class="[
                  'px-4 py-1.5 rounded-full text-sm transition-all',
                  shareMode === mode.key
                    ? 'bg-surface-200 dark:bg-surface-700 text-surface-900 dark:text-white'
                    : 'text-surface-500 hover:text-surface-900 dark:hover:text-white'
                ]"
              >
                {{ mode.label }}
              </button>
            </div>

            <!-- Permission selector -->
            <div class="flex items-center gap-3 mb-4">
              <span class="text-sm text-surface-500 dark:text-surface-400">Permission:</span>
              <div class="flex gap-2">
                <button
                  v-for="perm in ['viewer', 'editor', 'manager']"
                  :key="perm"
                  @click="newSharePermission = perm"
                  :class="[
                    'px-4 py-1.5 rounded-full text-sm capitalize transition-all',
                    newSharePermission === perm
                      ? permissionColor(perm) + ' ring-1 ring-current'
                      : 'text-surface-500 hover:text-surface-900 dark:hover:text-white'
                  ]"
                >
                  {{ perm }}
                </button>
              </div>
            </div>

            <!-- Company Users -->
            <div v-if="shareMode === 'company'">
              <input
                v-model="searchQuery"
                type="text"
                placeholder="Search team members..."
                class="w-full bg-surface-50 dark:bg-surface-900 border border-surface-300 dark:border-surface-600 rounded-xl px-4 py-2.5 text-sm text-surface-900 dark:text-white placeholder-surface-400 dark:placeholder-surface-500 focus:outline-none focus:ring-1 focus:ring-primary-500 mb-3"
              />
              <div v-if="loadingCompanyUsers" class="text-surface-500 text-sm py-4 text-center">Loading...</div>
              <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-48 overflow-y-auto">
                <button
                  v-for="user in filteredCompanyUsers"
                  :key="user"
                  :disabled="alreadySharedEmails.has(user.toLowerCase())"
                  @click="shareWithColleague(user)"
                  :class="[
                    'flex items-center gap-3 px-4 py-2.5 rounded-xl text-left transition-all text-sm',
                    alreadySharedEmails.has(user.toLowerCase())
                      ? 'bg-surface-100/50 dark:bg-surface-800/50 text-surface-400 dark:text-surface-600 cursor-not-allowed'
                      : 'bg-surface-100 dark:bg-surface-900 text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white'
                  ]"
                >
                  <span class="material-symbols-rounded text-[18px]">person</span>
                  <span class="truncate">{{ user }}</span>
                  <span v-if="alreadySharedEmails.has(user.toLowerCase())" class="ml-auto text-xs text-surface-400 dark:text-surface-600">shared</span>
                </button>
              </div>
            </div>

            <!-- Groups -->
            <div v-if="shareMode === 'groups'">
              <div v-if="colleaguesStore.sortedGroups.length === 0" class="text-surface-500 text-sm py-4 text-center">
                No groups available
              </div>
              <div v-else class="space-y-2">
                <button
                  v-for="group in colleaguesStore.sortedGroups"
                  :key="group.id"
                  :disabled="alreadySharedGroupIds.has(group.id)"
                  @click="selectedGroupId = group.id; shareWithGroup()"
                  :class="[
                    'flex items-center gap-3 px-4 py-3 rounded-xl w-full text-left transition-all',
                    alreadySharedGroupIds.has(group.id)
                      ? 'bg-surface-100/50 dark:bg-surface-800/50 text-surface-400 dark:text-surface-600 cursor-not-allowed'
                      : 'bg-surface-100 dark:bg-surface-900 text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-white'
                  ]"
                >
                  <span
                    class="w-8 h-8 rounded-lg flex items-center justify-center text-sm text-white"
                    :style="{ backgroundColor: group.color || '#6366f1' }"
                  >
                    <span class="material-symbols-rounded text-[16px]">{{ group.icon || 'group' }}</span>
                  </span>
                  <div>
                    <div class="font-medium text-sm">{{ group.name }}</div>
                    <div class="text-xs text-surface-500">{{ group.member_count || 0 }} members</div>
                  </div>
                  <span v-if="alreadySharedGroupIds.has(group.id)" class="ml-auto text-xs text-surface-400 dark:text-surface-600">shared</span>
                </button>
              </div>
            </div>

            <!-- Manual Email -->
            <div v-if="shareMode === 'manual'" class="flex gap-3">
              <input
                v-model="newShareEmail"
                type="email"
                placeholder="colleague@company.com"
                class="flex-1 bg-surface-50 dark:bg-surface-900 border border-surface-300 dark:border-surface-600 rounded-xl px-4 py-2.5 text-sm text-surface-900 dark:text-white placeholder-surface-400 dark:placeholder-surface-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
                @keydown.enter="shareWithColleague(newShareEmail)"
              />
              <button
                @click="shareWithColleague(newShareEmail)"
                :disabled="!newShareEmail || saving"
                class="px-6 py-2.5 bg-primary-600 hover:bg-primary-700 disabled:opacity-40 rounded-full text-white text-sm font-medium transition-all"
              >
                Share
              </button>
            </div>
          </div>

          <!-- Current Individual Shares -->
          <div class="bg-white dark:bg-surface-800 rounded-2xl p-6 mb-6 border border-surface-200 dark:border-surface-700">
            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2 text-surface-900 dark:text-white">
              <span class="material-symbols-rounded text-primary-500">people</span>
              Individual Shares
              <span class="text-sm text-surface-500 font-normal">({{ myShares.individual.length }})</span>
            </h3>

            <div v-if="myShares.individual.length === 0" class="text-surface-500 text-sm py-4 text-center">
              No individual shares yet
            </div>
            <div v-else class="space-y-2">
              <div
                v-for="share in myShares.individual"
                :key="share.id"
                class="flex items-center gap-4 px-4 py-3 bg-surface-50 dark:bg-surface-900 rounded-xl"
              >
                <span class="material-symbols-rounded text-surface-400">person</span>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-medium truncate text-surface-900 dark:text-white">{{ share.colleague_name || share.shared_with_email }}</div>
                  <div v-if="share.colleague_name" class="text-xs text-surface-500 truncate">{{ share.shared_with_email }}</div>
                </div>
                <!-- Permission toggle -->
                <select
                  :value="share.permission"
                  @change="updatePermission(share, 'individual', $event.target.value)"
                  class="bg-surface-100 dark:bg-surface-700 border border-surface-300 dark:border-surface-600 rounded-lg px-3 py-1.5 text-sm text-surface-700 dark:text-surface-300 focus:ring-1 focus:ring-primary-500"
                >
                  <option value="viewer">Viewer</option>
                  <option value="editor">Editor</option>
                  <option value="manager">Manager</option>
                </select>
                <button
                  @click="revokeShare(share, 'individual')"
                  class="p-1.5 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg text-surface-500 hover:text-red-500 dark:hover:text-red-400 transition-all"
                  title="Revoke access"
                >
                  <span class="material-symbols-rounded text-[18px]">close</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Group Shares -->
          <div class="bg-white dark:bg-surface-800 rounded-2xl p-6 border border-surface-200 dark:border-surface-700">
            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2 text-surface-900 dark:text-white">
              <span class="material-symbols-rounded text-indigo-500 dark:text-indigo-400">groups</span>
              Group Shares
              <span class="text-sm text-surface-500 font-normal">({{ myShares.groups.length }})</span>
            </h3>

            <div v-if="myShares.groups.length === 0" class="text-surface-500 text-sm py-4 text-center">
              No group shares yet
            </div>
            <div v-else class="space-y-2">
              <div
                v-for="share in myShares.groups"
                :key="share.id"
                class="flex items-center gap-4 px-4 py-3 bg-surface-50 dark:bg-surface-900 rounded-xl"
              >
                <span
                  class="w-8 h-8 rounded-lg flex items-center justify-center text-sm text-white"
                  :style="{ backgroundColor: share.group_color || '#6366f1' }"
                >
                  <span class="material-symbols-rounded text-[16px]">{{ share.group_icon || 'group' }}</span>
                </span>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-medium truncate text-surface-900 dark:text-white">{{ share.group_name }}</div>
                  <div class="text-xs text-surface-500">{{ share.member_count || 0 }} members</div>
                </div>
                <select
                  :value="share.permission"
                  @change="updatePermission(share, 'group', $event.target.value)"
                  class="bg-surface-100 dark:bg-surface-700 border border-surface-300 dark:border-surface-600 rounded-lg px-3 py-1.5 text-sm text-surface-700 dark:text-surface-300 focus:ring-1 focus:ring-primary-500"
                >
                  <option value="viewer">Viewer</option>
                  <option value="editor">Editor</option>
                  <option value="manager">Manager</option>
                </select>
                <button
                  @click="revokeShare(share, 'group')"
                  class="p-1.5 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg text-surface-500 hover:text-red-500 dark:hover:text-red-400 transition-all"
                  title="Revoke access"
                >
                  <span class="material-symbols-rounded text-[18px]">close</span>
                </button>
              </div>
            </div>
          </div>
        </template>

        <!-- Shared With Me Tab -->
        <template v-else-if="activeTab === 'shared_with_me'">
          <div class="bg-white dark:bg-surface-800 rounded-2xl p-6 mb-6 border border-surface-200 dark:border-surface-700">
            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2 text-surface-900 dark:text-white">
              <span class="material-symbols-rounded text-green-500 dark:text-green-400">folder_shared</span>
              CRMs Shared With Me
            </h3>

            <!-- Individual shares to me -->
            <div v-if="sharedWithMe.individual.length === 0 && sharedWithMe.from_groups.length === 0"
                 class="text-surface-500 text-sm py-8 text-center">
              No CRMs have been shared with you yet
            </div>

            <div v-if="sharedWithMe.individual.length > 0" class="space-y-2 mb-6">
              <h4 class="text-sm text-surface-500 mb-2">Direct Shares</h4>
              <div
                v-for="share in sharedWithMe.individual"
                :key="'ind-' + share.id"
                class="flex items-center gap-4 px-4 py-3 bg-surface-50 dark:bg-surface-900 rounded-xl"
              >
                <span class="material-symbols-rounded text-surface-400">person</span>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-medium truncate text-surface-900 dark:text-white">{{ share.owner_name || share.owner_email }}</div>
                  <div v-if="share.owner_name" class="text-xs text-surface-500">{{ share.owner_email }}</div>
                </div>
                <span :class="['px-3 py-1 rounded-full text-xs capitalize', permissionColor(share.permission)]">
                  {{ share.permission }}
                </span>
                <a
                  :href="`/crm/dashboard?owner=${encodeURIComponent(share.owner_email)}`"
                  class="px-4 py-1.5 bg-primary-600 hover:bg-primary-700 rounded-full text-white text-sm font-medium transition-all"
                >
                  View CRM
                </a>
              </div>
            </div>

            <!-- Group shares to me -->
            <div v-if="sharedWithMe.from_groups.length > 0" class="space-y-2">
              <h4 class="text-sm text-surface-500 mb-2">Via Groups</h4>
              <div
                v-for="share in sharedWithMe.from_groups"
                :key="'grp-' + share.id"
                class="flex items-center gap-4 px-4 py-3 bg-surface-50 dark:bg-surface-900 rounded-xl"
              >
                <span class="material-symbols-rounded text-indigo-500 dark:text-indigo-400">groups</span>
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-medium truncate text-surface-900 dark:text-white">{{ share.owner_name || share.owner_email }}</div>
                  <div class="text-xs text-surface-500">via {{ share.group_name }}</div>
                </div>
                <span :class="['px-3 py-1 rounded-full text-xs capitalize', permissionColor(share.permission)]">
                  {{ share.permission }}
                </span>
                <a
                  :href="`/crm/dashboard?owner=${encodeURIComponent(share.owner_email)}`"
                  class="px-4 py-1.5 bg-primary-600 hover:bg-primary-700 rounded-full text-white text-sm font-medium transition-all"
                >
                  View CRM
                </a>
              </div>
            </div>
          </div>
        </template>

        <!-- Activity Tab -->
        <template v-else-if="activeTab === 'activity'">
          <div class="bg-white dark:bg-surface-800 rounded-2xl p-6 border border-surface-200 dark:border-surface-700">
            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2 text-surface-900 dark:text-white">
              <span class="material-symbols-rounded text-amber-500 dark:text-amber-400">history</span>
              Share Activity
            </h3>

            <div v-if="activityLog.length === 0" class="text-surface-500 text-sm py-8 text-center">
              No activity recorded yet
            </div>
            <div v-else class="space-y-2">
              <div
                v-for="entry in activityLog"
                :key="entry.id"
                class="flex items-center gap-4 px-4 py-3 bg-surface-50 dark:bg-surface-900 rounded-xl"
              >
                <span class="material-symbols-rounded text-surface-500 text-[18px]">
                  {{ entry.action === 'shared_crm' ? 'person_add' : entry.action === 'viewed_client' ? 'visibility' : 'edit' }}
                </span>
                <div class="flex-1 min-w-0">
                  <div class="text-sm text-surface-700 dark:text-surface-200">
                    <span class="font-medium text-surface-900 dark:text-white">{{ entry.colleague_name || entry.colleague_email }}</span>{{ ' ' }}<span class="text-surface-500 dark:text-surface-400">{{ entry.action.replace(/_/g, ' ') }}</span>
                  </div>
                  <div v-if="entry.detail" class="text-xs text-surface-500 mt-0.5">{{ entry.detail }}</div>
                </div>
                <span class="text-xs text-surface-500 whitespace-nowrap">{{ formatDate(entry.created_at) }}</span>
              </div>
            </div>
          </div>
        </template>
      </div>
      </div><!-- end flex-1 content -->
    </div><!-- end flex sidebar+content -->

    <MobileBottomNav v-if="isMobile" />
  </div>
</template>

