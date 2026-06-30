<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick, defineAsyncComponent } from 'vue'
import { useRouter } from 'vue-router'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useToastStore } from '@/stores/toast'
import { useAddons } from '@/composables/useAddons'
import SharePermissionsModal from './SharePermissionsModal.vue'
import BoardScopePanel from './BoardScopePanel.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import api from '@/services/api'
import LayerBadge from '@/components/shared/LayerBadge.vue'

// Board Pro (lazy loaded)
const BoardClientHealthBadge = defineAsyncComponent(() => import('@/addons/board-pro/components/BoardClientHealthBadge.vue'))

const emit = defineEmits(['open-progress', 'panel-change'])

const router = useRouter()
const boardsStore = useBoardsStore()
const toast = useToastStore()
const { boardProEnabled, projectHubEnabled } = useAddons()

// Collapsible sections
const showViews = ref(true)
const showInsights = ref(true)
const showTools = ref(true)
const showAutomation = ref(true)
const showReports = ref(true)
const showNavigation = ref(true)

// Active panel tracking (null means showing board view)
const activePanel = ref(null)

// Active view
const currentView = computed(() => boardsStore.viewMode)

// Share / Members state
const showShareModal = ref(false)
const editingMember = ref(null)
const showMembersDropdown = ref(false)
const membersButtonRef = ref(null)
const dropdownStyle = ref({})

const members = computed(() => boardsStore.currentMembers || [])
const isOwner = computed(() => boardsStore.currentBoard?.user_role === 'owner')

// Confirm modal for member removal
const showRemoveConfirm = ref(false)
const memberToRemove = ref(null)

function toggleMembersDropdown() {
  if (showMembersDropdown.value) {
    showMembersDropdown.value = false
    return
  }
  showMembersDropdown.value = true
  nextTick(() => {
    if (membersButtonRef.value) {
      const rect = membersButtonRef.value.getBoundingClientRect()
      dropdownStyle.value = {
        position: 'fixed',
        top: `${rect.bottom + 4}px`,
        left: `${rect.left}px`,
        zIndex: 9999,
      }
    }
  })
}

function openShareModal() {
  editingMember.value = null
  showShareModal.value = true
  showMembersDropdown.value = false
}

function openEditMemberModal(member) {
  if (member.is_owner) return
  editingMember.value = member
  showShareModal.value = true
  showMembersDropdown.value = false
}

async function handleSavePermissions({ email, role, permissions }) {
  const isEditing = !!editingMember.value
  
  try {
    if (isEditing) {
      const response = await api.post(`/boards/${boardsStore.currentBoard.id}/members/permissions`, {
        member_email: email,
        role,
        ...permissions
      })
      if (response.data.success) {
        toast.success('Permissions updated')
        await boardsStore.fetchBoard(boardsStore.currentBoard.id)
      } else {
        toast.error('Failed to update permissions')
      }
    } else {
      const response = await api.post(`/boards/${boardsStore.currentBoard.id}/members`, {
        email,
        role,
        ...permissions
      })
      if (response.data.success) {
        toast.success('Member added')
        await boardsStore.fetchBoard(boardsStore.currentBoard.id)
      } else {
        toast.error(response.data.message || 'Failed to add member')
      }
    }
    
    showShareModal.value = false
    editingMember.value = null
  } catch (e) {
    console.error('Failed to save permissions:', e)
    toast.error('Failed to save. Please try again.')
  }
}

// ONE HTTP call instead of N parallel adds; one board refresh.
async function handleSaveBulkPermissions({ emails, role, permissions }) {
  if (!Array.isArray(emails) || emails.length === 0) return
  const boardId = boardsStore.currentBoard?.id
  if (!boardId) return

  try {
    const members = emails.map(email => ({ email, role, ...permissions }))
    const response = await api.post(`/boards/${boardId}/members/batch`, { members })
    const data = response.data?.data || {}
    const added = data.added || 0
    const failed = data.failed || 0
    if (added > 0) {
      toast.success(`Added ${added} member(s)`)
      await boardsStore.fetchBoard(boardId)
    }
    if (failed > 0) {
      toast.warning(`${failed} member(s) failed to add`)
    }
    showShareModal.value = false
    editingMember.value = null
  } catch (e) {
    console.error('Failed to bulk save permissions:', e)
    toast.error('Failed to save members. Please try again.')
  }
}

function confirmRemoveMember(member) {
  memberToRemove.value = member
  showRemoveConfirm.value = true
}

async function removeMember() {
  if (!memberToRemove.value) return
  const member = memberToRemove.value
  
  const success = await boardsStore.removeMember(boardsStore.currentBoard.id, member.email)
  if (success) {
    toast.success('Member removed')
  } else {
    toast.error('Failed to remove member')
  }
  showRemoveConfirm.value = false
  memberToRemove.value = null
}

// ──────────────────────────────────────────────
// Sidebar groups — structured for clear visual hierarchy
// ──────────────────────────────────────────────

// GROUP 1: Views — how you look at the board
const layoutViews = [
  { id: 'board', name: 'Board', icon: 'view_kanban' },
  { id: 'table', name: 'Table', icon: 'table_rows' },
  { id: 'calendar', name: 'Calendar', icon: 'calendar_month' },
  { id: 'timeline', name: 'Timeline', icon: 'view_timeline' },
]

// GROUP 2: Insights — financial & analytical views
const baseInsightViews = [
  { id: 'financials', name: 'Milestones & Billing', icon: 'payments', requiresFinancialAccess: true, replacedByPro: 'revenue' },
]
const proInsightViews = [
  { id: 'board_overview', name: 'Board Overview', icon: 'space_dashboard' },
  { id: 'revenue', name: 'Revenue & Billing', icon: 'monetization_on', requiresFinancialAccess: true },
  { id: 'scope_radar', name: 'Scope Radar', icon: 'radar' },
  { id: 'mood_split', name: 'Mood Split', icon: 'dashboard_customize' },
]

const insightOptions = computed(() => {
  const base = baseInsightViews.filter(opt => {
    if (opt.requiresFinancialAccess && !boardsStore.canViewFinancials) return false
    if (opt.replacedByPro && boardProEnabled.value) return false
    return true
  })
  if (boardProEnabled.value) {
    const pro = proInsightViews.filter(opt => {
      if (opt.requiresFinancialAccess && !boardsStore.canViewFinancials) return false
      return true
    })
    return [...base, ...pro]
  }
  return base
})

// GROUP 3: Tools — utility panels
const baseToolOptions = [
  { id: 'map', name: 'Board Map', icon: 'account_tree' },
  { id: 'activity', name: 'Activity Log', icon: 'history' },
  { id: 'settings', name: 'Settings', icon: 'tune' },
]
const proToolOptions = []
const toolOptions = computed(() => {
  const opts = boardProEnabled.value ? [...baseToolOptions, ...proToolOptions] : [...baseToolOptions]
  if (projectHubEnabled.value) opts.splice(opts.length - 1, 0, { id: 'watch_folders', name: 'Watch Folders', icon: 'visibility' })
  return opts
})

// GROUP 4: Automation — rules & workflows (Board Pro only)
const automationOptions = [
  { id: 'bp_automation', name: 'Automations', icon: 'bolt' },
  { id: 'bp_email_rules', name: 'Email Rules', icon: 'mark_email_read' },
]

// GROUP 5: Reports — generated outputs
const baseReportOptions = []
const proReportOptions = []
const reportOptions = computed(() => {
  if (boardProEnabled.value) return [...baseReportOptions, ...proReportOptions]
  return baseReportOptions
})

// Progress report is still a popup (sends email)
const progressOption = { id: 'progress', name: 'Progress Report', icon: 'send', highlight: true }

// Legacy compat — viewOptions includes layouts + insights for setView()
const viewOptions = computed(() => [...layoutViews, ...insightOptions.value])

// Legacy compat — panelOptions for setActivePanel()
const panelOptions = computed(() => [
  ...toolOptions.value,
  ...automationOptions,
  ...reportOptions.value,
])

function setView(viewId) {
  activePanel.value = null
  boardsStore.setViewMode(viewId)
  emit('panel-change', null)
}

function setActivePanel(panelId) {
  activePanel.value = panelId
  emit('panel-change', panelId)
}

function openProgress() {
  emit('open-progress')
}

function goToClients() {
  router.push('/clients/overview')
}

// Section toggles are inline in the template (@click="showX = !showX")

// Watch for board changes to reset panel
watch(() => boardsStore.currentBoard?.id, () => {
  activePanel.value = null
  showMembersDropdown.value = false
  emit('panel-change', null)
})

// Close members dropdown on outside click
function handleOutsideClick(e) {
  if (showMembersDropdown.value && !e.target.closest('.members-dropdown-container')) {
    showMembersDropdown.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleOutsideClick)
})

onUnmounted(() => {
  document.removeEventListener('click', handleOutsideClick)
})
</script>

<template>
  <aside class="w-56 flex-shrink-0 border-r border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] h-full flex flex-col">
    <!-- Intelligence layer indicator -->
    <div class="px-3 pt-3">
      <LayerBadge layer-key="appHeader.deliveryIntelligence" icon="engineering" />
    </div>
    <!-- Board header: avatar + share/members actions -->
    <div class="p-3 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
      <div class="flex items-center gap-2">
        <!-- Board avatar -->
        <div 
          v-if="boardsStore.currentBoard"
          class="w-9 h-9 rounded-lg flex items-center justify-center text-white text-xs font-bold uppercase flex-shrink-0 overflow-hidden"
          :style="boardsStore.currentBoard.background_image 
            ? { backgroundImage: `url(${boardsStore.currentBoard.background_image})`, backgroundSize: 'cover', backgroundPosition: 'center' }
            : { backgroundColor: boardsStore.currentBoard.background_color || '#1e1e26' }"
        >
          <span v-if="!boardsStore.currentBoard.background_image">
            {{ boardsStore.currentBoard.name?.substring(0, 2) || 'BD' }}
          </span>
        </div>
        <span class="text-xs text-surface-500 flex-shrink-0">{{ boardsStore.currentBoard?.card_count || 0 }} cards</span>
        
        <div class="flex-1"></div>

        <!-- Share button -->
        <button 
          @click="openShareModal"
          class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 hover:text-primary-500 transition-colors"
          title="Share board"
        >
          <span class="material-symbols-rounded text-lg">person_add</span>
        </button>

        <!-- Members button -->
        <div class="members-dropdown-container">
          <button 
            ref="membersButtonRef"
            @click.stop="toggleMembersDropdown"
            class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 hover:text-primary-500 transition-colors relative"
            title="Board members"
          >
            <span class="material-symbols-rounded text-lg">group</span>
            <span 
              v-if="members.length > 1"
              class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-primary-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center"
            >{{ members.length }}</span>
          </button>
        </div>
      </div>
    </div>
    
    <!-- Scrollable content -->
    <nav class="flex-1 overflow-y-auto p-3">

      <!-- ═══ GROUP: Views ═══ -->
      <div class="mb-1">
        <button 
          @click="showViews = !showViews"
          class="w-full flex items-center gap-1.5 px-2 py-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-widest hover:text-surface-600 dark:hover:text-surface-300"
        >
          <span class="material-symbols-rounded text-xs">{{ showViews ? 'expand_more' : 'chevron_right' }}</span>
          Views
        </button>
        <div v-if="showViews" class="mt-0.5 space-y-0.5">
          <button
            v-for="view in layoutViews"
            :key="view.id"
            @click="setView(view.id)"
            :class="[
              'w-full flex items-center gap-3 px-3 py-1.5 rounded-lg text-sm font-medium transition-all',
              currentView === view.id && !activePanel
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-surface-200'
            ]"
          >
            <span class="material-symbols-rounded text-lg">{{ view.icon }}</span>
            <span>{{ view.name }}</span>
            <span 
              v-if="currentView === view.id && !activePanel" 
              class="ml-auto material-symbols-rounded text-sm text-primary-500"
            >check</span>
          </button>
        </div>
      </div>

      <!-- ═══ GROUP: Insights ═══ -->
      <div v-if="insightOptions.length > 0" class="mb-1">
        <div class="border-t border-surface-100 dark:border-surface-700/50 my-2"></div>
        <button 
          @click="showInsights = !showInsights"
          class="w-full flex items-center gap-1.5 px-2 py-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-widest hover:text-surface-600 dark:hover:text-surface-300"
        >
          <span class="material-symbols-rounded text-xs">{{ showInsights ? 'expand_more' : 'chevron_right' }}</span>
          Insights
        </button>
        <div v-if="showInsights" class="mt-0.5 space-y-0.5">
          <button
            v-for="view in insightOptions"
            :key="view.id"
            @click="setView(view.id)"
            :class="[
              'w-full flex items-center gap-3 px-3 py-1.5 rounded-lg text-sm font-medium transition-all',
              currentView === view.id && !activePanel
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-surface-200'
            ]"
          >
            <span class="material-symbols-rounded text-lg">{{ view.icon }}</span>
            <span>{{ view.name }}</span>
            <span 
              v-if="currentView === view.id && !activePanel" 
              class="ml-auto material-symbols-rounded text-sm text-primary-500"
            >check</span>
          </button>
        </div>
      </div>

      <!-- ═══ GROUP: Tools ═══ -->
      <div class="mb-1">
        <div class="border-t border-surface-100 dark:border-surface-700/50 my-2"></div>
        <button 
          @click="showTools = !showTools"
          class="w-full flex items-center gap-1.5 px-2 py-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-widest hover:text-surface-600 dark:hover:text-surface-300"
        >
          <span class="material-symbols-rounded text-xs">{{ showTools ? 'expand_more' : 'chevron_right' }}</span>
          Tools
        </button>
        <div v-if="showTools" class="mt-0.5 space-y-0.5">
          <button
            v-for="panel in toolOptions"
            :key="panel.id"
            @click="setActivePanel(panel.id)"
            :class="[
              'w-full flex items-center gap-3 px-3 py-1.5 rounded-lg text-sm font-medium transition-all',
              activePanel === panel.id
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400'
                : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-surface-200'
            ]"
          >
            <span class="material-symbols-rounded text-lg">{{ panel.icon }}</span>
            <span>{{ panel.name }}</span>
            <span 
              v-if="activePanel === panel.id" 
              class="ml-auto material-symbols-rounded text-sm text-primary-500"
            >check</span>
          </button>
        </div>
      </div>

      <!-- ═══ GROUP: Automation (Board Pro only) ═══ -->
      <div v-if="boardProEnabled" class="mb-1">
        <div class="border-t border-surface-100 dark:border-surface-700/50 my-2"></div>
        <button 
          @click="showAutomation = !showAutomation"
          class="w-full flex items-center gap-1.5 px-2 py-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-widest hover:text-surface-600 dark:hover:text-surface-300"
        >
          <span class="material-symbols-rounded text-xs">{{ showAutomation ? 'expand_more' : 'chevron_right' }}</span>
          Automation
        </button>
        <div v-if="showAutomation" class="mt-0.5 space-y-0.5">
          <button
            v-for="panel in automationOptions"
            :key="panel.id"
            @click="setActivePanel(panel.id)"
            :class="[
              'w-full flex items-center gap-3 px-3 py-1.5 rounded-lg text-sm font-medium transition-all',
              activePanel === panel.id
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400'
                : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-surface-200'
            ]"
          >
            <span class="material-symbols-rounded text-lg">{{ panel.icon }}</span>
            <span>{{ panel.name }}</span>
            <span 
              v-if="activePanel === panel.id" 
              class="ml-auto material-symbols-rounded text-sm text-primary-500"
            >check</span>
          </button>
        </div>
      </div>

      <!-- ═══ GROUP: Reports ═══ -->
      <div class="mb-1">
        <div class="border-t border-surface-100 dark:border-surface-700/50 my-2"></div>
        <button 
          @click="showReports = !showReports"
          class="w-full flex items-center gap-1.5 px-2 py-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-widest hover:text-surface-600 dark:hover:text-surface-300"
        >
          <span class="material-symbols-rounded text-xs">{{ showReports ? 'expand_more' : 'chevron_right' }}</span>
          Reports
        </button>
        <div v-if="showReports" class="mt-0.5 space-y-0.5">
          <!-- Pro report panels -->
          <button
            v-for="panel in reportOptions"
            :key="panel.id"
            @click="setActivePanel(panel.id)"
            :class="[
              'w-full flex items-center gap-3 px-3 py-1.5 rounded-lg text-sm font-medium transition-all',
              activePanel === panel.id
                ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400'
                : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-surface-200'
            ]"
          >
            <span class="material-symbols-rounded text-lg">{{ panel.icon }}</span>
            <span>{{ panel.name }}</span>
            <span 
              v-if="activePanel === panel.id" 
              class="ml-auto material-symbols-rounded text-sm text-primary-500"
            >check</span>
          </button>
          <!-- Progress Report (opens popup — always available) -->
          <button
            @click="openProgress"
            class="w-full flex items-center gap-3 px-3 py-1.5 rounded-lg text-sm font-medium transition-all text-green-600 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-500/10"
          >
            <span class="material-symbols-rounded text-lg">{{ progressOption.icon }}</span>
            <span>{{ progressOption.name }}</span>
          </button>
        </div>
      </div>

      <!-- ═══ GROUP: Navigate ═══ -->
      <div>
        <div class="border-t border-surface-100 dark:border-surface-700/50 my-2"></div>
        <button 
          @click="showNavigation = !showNavigation"
          class="w-full flex items-center gap-1.5 px-2 py-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-widest hover:text-surface-600 dark:hover:text-surface-300"
        >
          <span class="material-symbols-rounded text-xs">{{ showNavigation ? 'expand_more' : 'chevron_right' }}</span>
          Navigate
        </button>
        <div v-if="showNavigation" class="mt-0.5 space-y-0.5">
          <button
            @click="goToClients"
            class="w-full flex items-center gap-3 px-3 py-1.5 rounded-lg text-sm font-medium transition-all text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-surface-200"
          >
            <span class="material-symbols-rounded text-lg">groups</span>
            <span>Clients Overview</span>
          </button>
        </div>
      </div>
      
      <!-- Project scope: dates, budget, drift -->
      <template v-if="boardsStore.currentBoard?.id">
        <div class="border-t border-surface-100 dark:border-surface-700/50 my-2"></div>
        <BoardScopePanel />
      </template>

      <!-- Board Pro: Client Health Badge -->
      <template v-if="boardProEnabled && boardsStore.currentBoard?.id">
        <div class="border-t border-surface-100 dark:border-surface-700/50 my-2"></div>
        <BoardClientHealthBadge :board-id="boardsStore.currentBoard.id" />
      </template>
    </nav>

    <!-- Members dropdown (teleported to body to avoid sidebar overflow clipping) -->
    <Teleport to="body">
      <div 
        v-if="showMembersDropdown"
        :style="dropdownStyle"
        class="w-64 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-2 members-dropdown-container"
        @click.stop
      >
        <div class="px-3 py-1.5 text-xs font-semibold text-surface-500 uppercase tracking-wide">
          Board Members
        </div>
        <div class="max-h-48 overflow-y-auto">
          <div
            v-for="member in members"
            :key="member.email"
            class="flex items-center gap-2.5 px-3 py-2 hover:bg-surface-50 dark:hover:bg-surface-700"
          >
            <UserAvatar :email="member.email" size="sm" />
            <div class="flex-1 min-w-0">
              <p class="text-sm text-surface-900 dark:text-surface-100 truncate">{{ member.email }}</p>
              <p class="text-[10px] text-surface-400 capitalize">{{ member.is_owner ? 'Owner' : member.is_guest ? 'Guest' : member.role }}</p>
            </div>
            <button 
              v-if="isOwner && !member.is_owner"
              @click.stop="openEditMemberModal(member)"
              class="p-1 rounded hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300"
              title="Edit permissions"
            >
              <span class="material-symbols-rounded text-base">settings</span>
            </button>
            <button 
              v-if="isOwner && !member.is_owner"
              @click.stop="confirmRemoveMember(member)"
              class="p-1 rounded hover:bg-red-100 dark:hover:bg-red-900/30 text-surface-400 hover:text-red-500"
              title="Remove member"
            >
              <span class="material-symbols-rounded text-base">person_remove</span>
            </button>
          </div>
        </div>
        <div v-if="isOwner" class="border-t border-surface-200 dark:border-surface-700 mt-1 pt-1 px-2">
          <button
            @click="openShareModal"
            class="w-full px-2 py-2 text-left text-sm text-primary-500 hover:bg-primary-50 dark:hover:bg-primary-500/10 rounded-lg flex items-center gap-2"
          >
            <span class="material-symbols-rounded text-lg">person_add</span>
            Add Member
          </button>
        </div>
      </div>
    </Teleport>

    <!-- Share Permissions Modal -->
    <SharePermissionsModal
      :show="showShareModal"
      :member="editingMember"
      :board-id="boardsStore.currentBoard?.id || 0"
      :is-editing="!!editingMember"
      @close="showShareModal = false; editingMember = null"
      @save="handleSavePermissions"
      @save-bulk="handleSaveBulkPermissions"
    />

    <!-- Remove member confirmation -->
    <ConfirmModal
      :show="showRemoveConfirm"
      title="Remove Member"
      :message="`Remove ${memberToRemove?.email} from this board? They will lose access to all cards and data.`"
      confirm-text="Remove"
      :danger="true"
      @confirm="removeMember"
      @cancel="showRemoveConfirm = false; memberToRemove = null"
    />
  </aside>
</template>
