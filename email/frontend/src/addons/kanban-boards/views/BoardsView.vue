<script setup>
import { ref, shallowRef, onMounted, onUnmounted, computed, watch, defineAsyncComponent } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useToastStore } from '@/stores/toast'
import { useThemeStore } from '@/stores/theme'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useAccountsStore } from '@/stores/accounts'
import { useAuthStore } from '@/stores/auth'
import clientTimeTracker from '@/addons/time-tracker/services/clientTimeTracker'
import { isDebugEnabled } from '@/utils/debug'

// Addons
import { useAddons } from '@/composables/useAddons'

// Components
import BoardCanvas from '@/addons/kanban-boards/components/BoardCanvas.vue'
import BoardTableView from '@/addons/kanban-boards/components/BoardTableView.vue'
import BoardCalendarView from '@/addons/kanban-boards/components/BoardCalendarView.vue'
import BoardTimelineView from '@/addons/kanban-boards/components/BoardTimelineView.vue'
import BoardFinancialsView from '@/addons/kanban-boards/components/BoardFinancialsView.vue'
import CardModal from '@/addons/kanban-boards/components/CardModal.vue'
import BoardSidebar from '@/addons/kanban-boards/components/BoardSidebar.vue'
import BoardMapPanel from '@/addons/kanban-boards/components/BoardMapPanel.vue'
import TrelloImportModal from '@/addons/kanban-boards/components/TrelloImportModal.vue'
import SettingsPanel from '@/addons/kanban-boards/components/SettingsPanel.vue'
import AppHeader from '@/components/shared/AppHeader.vue'
import ActivityLog from '@/components/ActivityLog.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import FeatureGuide from '@/components/shared/FeatureGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import ViewInfoButton from '@/components/shared/ViewInfoButton.vue'
import { featureGuides } from '@/data/featureGuides'
import StepGuide from '@/components/shared/StepGuide.vue'
import { boardsGuide } from '@/data/stepGuides'

// Project Hub components (lazy loaded)
const ProjectHubSidebar = defineAsyncComponent(() => import('@/addons/project-hub/components/ProjectHubSidebar.vue'))
const MyWorkPanel = defineAsyncComponent(() => import('@/addons/project-hub/components/MyWorkPanel.vue'))
const SpaceOverviewView = defineAsyncComponent(() => import('@/addons/project-hub/components/SpaceOverviewView.vue'))
const FolderHeader = defineAsyncComponent(() => import('@/addons/project-hub/components/FolderHeader.vue'))
const FolderTaskView = defineAsyncComponent(() => import('@/addons/project-hub/components/FolderTaskView.vue'))
const WatchFolderManager = defineAsyncComponent(() => import('@/addons/project-hub/components/WatchFolderManager.vue'))

// Board Pro components (lazy loaded)
const BoardRevenueView = defineAsyncComponent(() => import('@/addons/board-pro/components/BoardRevenueView.vue'))
const BoardScopeRadar = defineAsyncComponent(() => import('@/addons/board-pro/components/BoardScopeRadar.vue'))
const BoardScopeCreepBadge = defineAsyncComponent(() => import('@/addons/board-pro/components/BoardScopeCreepBadge.vue'))
const BoardOverview = defineAsyncComponent(() => import('@/addons/board-pro/components/BoardOverview.vue'))
const BoardMoodSplitView = defineAsyncComponent(() => import('@/addons/board-pro/components/BoardMoodSplitView.vue'))
const BoardAutomationPanel = defineAsyncComponent(() => import('@/addons/board-pro/components/BoardAutomationPanel.vue'))
const EmailRulesPanel = defineAsyncComponent(() => import('@/addons/board-pro/components/EmailRulesPanel.vue'))

const route = useRoute()
const router = useRouter()
const boardsStore = useBoardsStore()
const toast = useToastStore()
const theme = useThemeStore()
const todosStore = useTodosStore()
const accountsStore = useAccountsStore()
const authStore = useAuthStore()
const { boardProEnabled, crmProEnabled, projectHubEnabled } = useAddons()

// Project Hub store (reactive ref so computeds update when store loads)
const projectHubStoreRef = shallowRef(null)
watch(projectHubEnabled, (enabled) => {
  if (enabled && !projectHubStoreRef.value) {
    import('@/addons/project-hub/stores/projectHub').then(m => {
      projectHubStoreRef.value = m.useProjectHubStore()
    })
  }
}, { immediate: true })
const hubActiveView = computed(() => projectHubStoreRef.value?.activeView || 'my-work')

// When hub store finishes loading, sync active board from route or current board
watch(projectHubStoreRef, (store) => {
  if (!store || !projectHubEnabled.value) return
  const boardId = route.params.id ? parseInt(route.params.id) : boardsStore.currentBoard?.id
  if (boardId) {
    store.selectBoard(boardId)
  }
})
const isHubMyWork = computed(() => projectHubEnabled.value && hubActiveView.value === 'my-work')
const isHubFolder = computed(() => projectHubEnabled.value && hubActiveView.value?.startsWith('folder:'))
const isHubBoard = computed(() => projectHubEnabled.value && (hubActiveView.value?.startsWith('board:') || hubActiveView.value?.startsWith('unsorted-board:')))
const isHubSpace = computed(() => projectHubEnabled.value && hubActiveView.value?.startsWith('space:'))
const hubSpaceId = computed(() => {
  if (!isHubSpace.value) return null
  return parseInt(hubActiveView.value.split(':')[1])
})
const hubStore = computed(() => projectHubStoreRef.value)

// Navigation
const showAccountDropdown = ref(false)

// Feature guide
const showBoardsGuide = ref(false)
const showStepGuide = ref(false)
const boardsGuideData = featureGuides.boards

// State
const showBoardList = ref(true)
const showCreateModal = ref(false)
const showTrelloImport = ref(false)
const showProgressPanel = ref(false)
const newBoardName = ref('')
const newBoardColor = ref('#1e1e26')
const selectedCard = ref(null)
const searchQuery = ref('')

const isSubtaskView = computed(() => !!route.query.originCard)
const parentCardName = computed(() => {
  if (!isSubtaskView.value) return ''
  return selectedCard.value?.title || 'original card'
})

function goBackToParentCard() {
  const parentCardId = Number(route.query.originCard || 0)
  const parentBoardId = Number(route.query.originBoard || route.params.id || 0)
  if (parentCardId && parentBoardId) {
    router.replace({
      name: 'board',
      params: { id: parentBoardId },
      query: { card: parentCardId },
    })
  } else {
    closeCardModal()
  }
}

// Filter state
const activeFilters = ref({
  cardCount: 'all',    // 'all', 'empty', '1-5', '5-10', '10+'
  recentActivity: 'all', // 'all', 'today', 'week', 'month'
  visibility: 'all',   // 'all', 'private', 'shared', 'shared_with_me'
  sortBy: 'recent'     // 'recent', 'name', 'cards'
})

// Boards list view mode (grid or table)
const boardsListView = ref(localStorage.getItem('boardsListView') || 'grid')
watch(boardsListView, (v) => localStorage.setItem('boardsListView', v))

// Table view sort state
const tableSortColumn = ref('updated_at')
const tableSortDirection = ref('desc')

// Active panel in content area (null = show board view)
const activePanel = ref(null)

const currentViewInfoKey = computed(() => {
  if (showBoardList.value) return 'boards'
  const panel = activePanel.value
  if (panel === 'map') return 'boardMap'
  if (panel === 'settings') return 'settings'
  if (panel === 'activity') return 'activity'
  if (panel === 'bp_automation') return 'automations'
  if (panel === 'bp_email_rules') return 'emailRules'
  if (panel === 'watch_folders') return 'watchFolders'
  const vm = boardsStore.viewMode
  if (vm === 'board') return 'board'
  if (vm === 'table') return 'table'
  if (vm === 'calendar') return 'calendar'
  if (vm === 'timeline') return 'timeline'
  if (vm === 'financials') return 'financials'
  if (vm === 'board_overview') return 'boardOverview'
  if (vm === 'revenue') return 'revenue'
  if (vm === 'scope_radar') return 'scopeRadar'
  if (vm === 'mood_split') return 'moodSplit'
  return 'boards'
})

// Progress report state
const linkedEmails = ref([])
const progressPreview = ref(null)
const progressRecipients = ref('')
const progressSubject = ref('')
const progressSending = ref(false)
const progressHistory = ref([])
const showProgressHistory = ref(false)

// Context menu state for boards
const boardContextMenu = ref({ show: false, x: 0, y: 0, board: null })

// Background context menu state
const bgContextMenu = ref({ show: false, x: 0, y: 0 })

// Background settings
const bgBlur = ref(0)
const bgOverlayColor = ref('#000000')
const bgOverlayOpacity = ref(0)

// Mobile state
const isMobile = ref(false)
const sidebarOpen = ref(false)
const mobileHubNavOpen = ref(false)

const mobileHubLabel = computed(() => {
  const view = hubActiveView.value
  if (!view || view === 'my-work') return 'My Work'
  if (view.startsWith('folder:')) return projectHubStoreRef.value?.activeFolder?.name || 'Folder'
  if (view.startsWith('board:') || view.startsWith('unsorted-board:')) return boardsStore.currentBoard?.name || 'Board'
  return 'Project Hub'
})

function checkMobile() {
  isMobile.value = window.innerWidth < 768
  if (!isMobile.value) {
    sidebarOpen.value = false
  }
}

function toggleSidebar() {
  sidebarOpen.value = !sidebarOpen.value
}

function closeSidebar() {
  sidebarOpen.value = false
}

// Mobile bottom sheet data
const mobileSheetLayoutViews = [
  { id: 'board', name: 'Board', icon: 'view_kanban' },
  { id: 'table', name: 'Table', icon: 'table_rows' },
  { id: 'calendar', name: 'Calendar', icon: 'calendar_month' },
  { id: 'timeline', name: 'Timeline', icon: 'view_timeline' },
]

const mobileSheetInsightViews = computed(() => {
  const views = [
    { id: 'board_overview', name: 'Board Overview', icon: 'space_dashboard' },
  ]
  if (boardsStore.canViewFinancials) {
    views.push(boardProEnabled.value
      ? { id: 'revenue', name: 'Revenue & Billing', icon: 'monetization_on' }
      : { id: 'financials', name: 'Milestones & Billing', icon: 'payments' }
    )
  }
  if (boardProEnabled.value) {
    views.push({ id: 'scope_radar', name: 'Scope Radar', icon: 'radar' })
    views.push({ id: 'mood_split', name: 'Mood Split', icon: 'dashboard_customize' })
  }
  return views
})

const mobileSheetToolOptions = [
  { id: 'map', name: 'Board Map', icon: 'account_tree' },
  { id: 'activity', name: 'Activity Log', icon: 'history' },
  { id: 'settings', name: 'Settings', icon: 'tune' },
]

const mobileSheetAutomationOptions = [
  { id: 'bp_automation', name: 'Automations', icon: 'bolt' },
  { id: 'bp_email_rules', name: 'Email Rules', icon: 'mark_email_read' },
]

const mobileSheetWatchOptions = computed(() =>
  projectHubEnabled.value ? [{ id: 'watch_folders', name: 'Watch Folders', icon: 'visibility' }] : []
)

const allMobileItems = computed(() => [
  ...mobileSheetLayoutViews,
  ...mobileSheetInsightViews.value,
  ...mobileSheetToolOptions,
  ...mobileSheetAutomationOptions,
  ...mobileSheetWatchOptions.value,
])

const mobileCurrentLabel = computed(() => {
  if (activePanel.value) {
    const panel = allMobileItems.value.find(i => i.id === activePanel.value)
    return panel?.name || 'Board'
  }
  const view = allMobileItems.value.find(i => i.id === boardsStore.viewMode)
  return view?.name || 'Board'
})

const mobileCurrentIcon = computed(() => {
  if (activePanel.value) {
    const panel = allMobileItems.value.find(i => i.id === activePanel.value)
    return panel?.icon || 'view_kanban'
  }
  const view = allMobileItems.value.find(i => i.id === boardsStore.viewMode)
  return view?.icon || 'view_kanban'
})

function handleMobileSheetView(viewId) {
  activePanel.value = null
  boardsStore.setViewMode(viewId)
}

function handleMobileSheetPanel(panelId) {
  activePanel.value = panelId
}

// Colors for board backgrounds
const boardColors = [
  '#1e1e26', '#0f766e', '#0369a1', '#7c3aed', '#be185d',
  '#b91c1c', '#c2410c', '#15803d', '#1d4ed8', '#6d28d9'
]

// Overlay color presets
const overlayColors = [
  '#000000', '#ffffff', '#1e1e26', '#0f766e', '#0369a1', 
  '#7c3aed', '#be185d', '#b91c1c'
]

// Include primary account in the list
const allAccounts = computed(() => {
  const primaryAccount = {
    id: 'primary',
    account_email: authStore.userEmail,
    display_name: authStore.displayName,
    is_primary: true,
    is_default: accountsStore.accounts.length === 0,
  }
  return [primaryAccount, ...accountsStore.accounts]
})

const currentAccount = computed(() => {
  if (!accountsStore.activeAccountId || accountsStore.activeAccountId === 'primary') {
    return allAccounts.value[0]
  }
  return allAccounts.value.find(a => a.id === parseInt(accountsStore.activeAccountId)) || allAccounts.value[0]
})

function getAccountInitials(account) {
  if (!account) return '?'
  const name = account.display_name || account.account_email
  return name.substring(0, 2).toUpperCase()
}

// Format relative time (e.g., "2 hours ago", "yesterday")
function formatRelativeTime(dateStr) {
  if (!dateStr) return 'never'
  
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = now - date
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMs / 3600000)
  const diffDays = Math.floor(diffMs / 86400000)
  
  if (diffMins < 1) return 'just now'
  if (diffMins < 60) return `${diffMins}m ago`
  if (diffHours < 24) return `${diffHours}h ago`
  if (diffDays === 1) return 'yesterday'
  if (diffDays < 7) return `${diffDays}d ago`
  if (diffDays < 30) return `${Math.floor(diffDays / 7)}w ago`
  
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

// Accent color mapping (ID -> hex color)
const accentColorMap = {
  green: '#22c55e',
  red: '#ef4444',
  purple: '#a855f7',
  blue: '#3b82f6',
  gold: '#eab308',
  mono: '#404040',
}

// Get account-specific accent color
function getAccountAccentColor(account) {
  if (!account) return '#a855f7' // default purple
  
  // Primary account uses theme accent
  if (account.is_primary || account.id === 'primary') {
    const currentAccent = theme.currentAccent || 'purple'
    return accentColorMap[currentAccent] || '#a855f7'
  }
  
  // Auxiliary accounts use their stored accent color
  if (account.accent_color) {
    return accentColorMap[account.accent_color] || account.accent_color
  }
  
  return '#a855f7' // fallback purple
}

// Get inline style for account avatar background
function getAccountAvatarStyle(account) {
  const color = getAccountAccentColor(account)
  return { backgroundColor: color }
}

async function switchAccount(account) {
  showAccountDropdown.value = false
  
  const accountId = account.id === 'primary' ? 'primary' : account.id
  
  // Switch account
  await accountsStore.switchAccount(accountId)
  
  toast.success(`Switched to ${account.display_name || account.account_email}`)
}

// Logout from an auxiliary (non-primary) account
async function logoutAuxiliaryAccount(e, account) {
  e.stopPropagation()
  
  if (account.is_primary) {
    toast.warning('Use the main Sign Out button to logout from primary account')
    return
  }
  
  const wasActive = currentAccount.value.id === account.id
  
  // Delete the account from backend and local state. Pick the endpoint from
  // the account object (OAuth vs IMAP live in separate tables, ids can collide).
  const result = await accountsStore.removeAccountByType(account)
  
  if (result) {
    toast.success(`Logged out of ${account.account_email}`)
    showAccountDropdown.value = false
    
    // If we logged out of the active account, switch to primary
    if (wasActive) {
      await accountsStore.switchAccount('primary')
    }
  } else {
    toast.error('Failed to logout from account')
  }
}

// Computed
const currentBoardId = computed(() => route.params.id ? parseInt(route.params.id) : null)

const filteredBoards = computed(() => {
  let boards = [...boardsStore.activeBoards]
  
  // Search filter
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase()
    boards = boards.filter(b => 
      b.name.toLowerCase().includes(q) || 
      (b.description && b.description.toLowerCase().includes(q))
    )
  }
  
  // Card count filter
  if (activeFilters.value.cardCount !== 'all') {
    boards = boards.filter(b => {
      const count = b.card_count || 0
      switch (activeFilters.value.cardCount) {
        case 'empty': return count === 0
        case '1-5': return count >= 1 && count <= 5
        case '5-10': return count > 5 && count <= 10
        case '10+': return count > 10
        default: return true
      }
    })
  }
  
  // Visibility filter
  if (activeFilters.value.visibility !== 'all') {
    boards = boards.filter(b => getBoardVisibility(b) === activeFilters.value.visibility)
  }
  
  // Recent activity filter
  if (activeFilters.value.recentActivity !== 'all') {
    const now = new Date()
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
    const weekAgo = new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000)
    const monthAgo = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000)
    
    boards = boards.filter(b => {
      if (!b.updated_at) return false
      const updated = new Date(b.updated_at)
      switch (activeFilters.value.recentActivity) {
        case 'today': return updated >= today
        case 'week': return updated >= weekAgo
        case 'month': return updated >= monthAgo
        default: return true
      }
    })
  }
  
  // Sort
  switch (activeFilters.value.sortBy) {
    case 'name':
      boards.sort((a, b) => a.name.localeCompare(b.name))
      break
    case 'cards':
      boards.sort((a, b) => (b.card_count || 0) - (a.card_count || 0))
      break
    case 'recent':
    default:
      boards.sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at))
  }
  
  return boards
})

// Table-sorted boards (applies column sort on top of filteredBoards)
const tableSortedBoards = computed(() => {
  const boards = [...filteredBoards.value]
  const col = tableSortColumn.value
  const dir = tableSortDirection.value === 'asc' ? 1 : -1
  
  boards.sort((a, b) => {
    let av, bv
    switch (col) {
      case 'name':
        return dir * (a.name || '').localeCompare(b.name || '')
      case 'card_count':
      case 'completed_count':
      case 'overdue_count':
      case 'list_count':
      case 'email_count':
        return dir * ((parseInt(a[col]) || 0) - (parseInt(b[col]) || 0))
      case 'rules_total':
        av = (parseInt(a.automation_count) || 0) + (parseInt(a.email_rule_count) || 0)
        bv = (parseInt(b.automation_count) || 0) + (parseInt(b.email_rule_count) || 0)
        return dir * (av - bv)
      case 'member_count':
        return dir * ((parseInt(a.member_count) || 0) - (parseInt(b.member_count) || 0))
      case 'total_revenue':
        return dir * ((parseFloat(a.total_revenue) || 0) - (parseFloat(b.total_revenue) || 0))
      case 'client_name':
        av = a.client_name || ''
        bv = b.client_name || ''
        return dir * av.localeCompare(bv)
      case 'visibility':
        av = getBoardVisibility(a)
        bv = getBoardVisibility(b)
        return dir * av.localeCompare(bv)
      case 'updated_at':
      default:
        return dir * (new Date(b.updated_at) - new Date(a.updated_at))
    }
  })
  return boards
})

function sortTableBy(key) {
  if (key === 'actions') return
  if (tableSortColumn.value === key) {
    tableSortDirection.value = tableSortDirection.value === 'asc' ? 'desc' : 'asc'
  } else {
    tableSortColumn.value = key
    tableSortDirection.value = key === 'name' ? 'asc' : 'desc'
  }
}

// Board visibility type: 'private' | 'shared' | 'shared_with_me'
function getBoardVisibility(board) {
  const myEmail = (authStore.userEmail || '').toLowerCase()
  const ownerEmail = (board.owner_email || '').toLowerCase()
  const isOwner = myEmail === ownerEmail
  const memberCount = parseInt(board.member_count) || 0
  
  if (!isOwner) return 'shared_with_me'
  if (memberCount > 0) return 'shared'
  return 'private'
}

// Check if any filter is active
const hasActiveFilters = computed(() => {
  return activeFilters.value.cardCount !== 'all' || 
         activeFilters.value.recentActivity !== 'all' ||
         activeFilters.value.visibility !== 'all' ||
         searchQuery.value.length > 0
})

// Clear all filters
function clearFilters() {
  searchQuery.value = ''
  activeFilters.value = {
    cardCount: 'all',
    recentActivity: 'all',
    visibility: 'all',
    sortBy: 'recent'
  }
}

// Header style with subtle board color tint
const headerStyle = computed(() => {
  const board = boardsStore.currentBoard
  if (!board || showBoardList.value) return {}
  
  const isDark = theme.isDarkMode
  
  if (board.background_image) {
    const overlay = isDark 
      ? 'linear-gradient(to right, rgba(30,30,38,0.9), rgba(30,30,38,0.75))'
      : 'linear-gradient(to right, rgba(255,255,255,0.85), rgba(255,255,255,0.7))'
    return {
      backgroundImage: `${overlay}, url(${board.background_image})`,
      backgroundSize: 'cover',
      backgroundPosition: 'center top'
    }
  }
  
  if (board.background_color) {
    const hex = board.background_color.replace('#', '')
    const r = parseInt(hex.substr(0, 2), 16)
    const g = parseInt(hex.substr(2, 2), 16)
    const b = parseInt(hex.substr(4, 2), 16)
    return {
      backgroundColor: `rgba(${r}, ${g}, ${b}, 0.08)`
    }
  }
  
  return {}
})

async function syncSelectedCardFromRoute() {
  const cardId = route.query.card ? parseInt(route.query.card) : null

  if (!cardId) {
    selectedCard.value = null
    return
  }

  const card = boardsStore.allCards?.find(c => c.id === cardId)
  if (card) {
    selectedCard.value = card
    return
  }

  const fetchedCard = await boardsStore.getCard(cardId)
  if (fetchedCard) {
    selectedCard.value = fetchedCard
  }
}

// Watch for board ID changes
watch(() => route.params.id, async (newId) => {
  if (newId) {
    showBoardList.value = false
    await boardsStore.fetchBoard(parseInt(newId))

    if (projectHubEnabled.value && projectHubStoreRef.value) {
      projectHubStoreRef.value.selectBoard(parseInt(newId))
    }
    
    await syncSelectedCardFromRoute()
  } else if (!route.params.folderId) {
    showBoardList.value = true
    boardsStore.clearCurrentBoard()
    selectedCard.value = null
  }
}, { immediate: true })

watch(() => route.query.card, async () => {
  await syncSelectedCardFromRoute()
})

// Track board viewing for client time tracking
watch(() => boardsStore.currentBoard, (board) => {
  if (board) {
    // Track board view activity - pass board name as 4th param
    clientTimeTracker.trackBoardActivity(board.id, null, null, board.name)
  } else {
    // Stop tracking when leaving board
    clientTimeTracker.stopTracking()
  }
})

// Track card viewing for client time tracking
watch(() => selectedCard.value, (card) => {
  if (card && boardsStore.currentBoard) {
    // Track card/task activity - pass card title as 3rd param, board name as 4th
    clientTimeTracker.trackBoardActivity(boardsStore.currentBoard.id, card.id, card.title, boardsStore.currentBoard.name)
  }
})

// Methods
async function createBoard() {
  if (!newBoardName.value.trim()) {
    toast.warning('Please enter a board name')
    return
  }
  
  const board = await boardsStore.createBoard({
    name: newBoardName.value.trim(),
    background_color: newBoardColor.value
  })
  
  if (board) {
    toast.success('Board created')
    showCreateModal.value = false
    newBoardName.value = ''
    newBoardColor.value = '#1e1e26'
    router.push(`/boards/${board.id}`)
  } else {
    toast.error('Failed to create board')
  }
}

function openBoard(board) {
  router.push(`/boards/${board.id}`)
}

function goToBoards() {
  if (projectHubEnabled.value) {
    goBackFromBoard()
    return
  }
  router.push('/boards')
  showBoardList.value = true
}

// Panel change handler
function handlePanelChange(panelId) {
  activePanel.value = panelId
}

// Project Hub navigation handlers
function handleProjectHubBoardSelect(boardId) {
  showBoardList.value = false
  activePanel.value = null
  boardsStore.fetchBoard(boardId)
  projectHubStoreRef.value?.selectBoard(boardId)
  router.replace({ name: 'board', params: { id: boardId } })
}

function handleProjectHubFolderSelect(folder) {
  showBoardList.value = false
  activePanel.value = null
  if (folder?.id) {
    router.replace({ name: 'board-folder', params: { folderId: folder.id } })
  }
}

function handleProjectHubMyWork() {
  showBoardList.value = false
  activePanel.value = null
  router.replace({ name: 'boards' })
}

function handleOpenCardFromHub(card) {
  selectedCard.value = card
  const boardId = card?.board_id
  const cardId = card?.card_id || card?.id
  if (boardId && cardId) {
    router.replace({ name: 'board', params: { id: boardId }, query: { card: cardId } })
  }
}

function goBackFromBoard() {
  activePanel.value = null
  const hub = projectHubStoreRef.value
  if (hub?.activeFolder && hub?.activeSpace) {
    hub.selectFolder(hub.activeFolder, hub.activeSpace)
    boardsStore.clearCurrentBoard()
    router.replace({ name: 'board-folder', params: { folderId: hub.activeFolder.id } })
  } else {
    hub?.selectMyWork()
    boardsStore.clearCurrentBoard()
    router.replace({ name: 'boards' })
  }
}

// Handle settings panel delete
function handleSettingsDeleted() {
  activePanel.value = null
  goToBoards()
}

// Progress Reports
const progressLoading = ref(false)

async function openProgressPanel() {
  if (!boardsStore.currentBoard) return
  showProgressPanel.value = true
  progressLoading.value = true
  progressPreview.value = null
  progressSubject.value = `Progress Update: ${boardsStore.currentBoard.name}`
  
  isDebugEnabled() && console.log('Opening progress panel for board:', boardsStore.currentBoard.id, boardsStore.currentBoard.name)
  
  try {
    // Load linked emails to get recipient suggestions
    const emails = await boardsStore.getBoardEmails(boardsStore.currentBoard.id)
    linkedEmails.value = emails
    isDebugEnabled() && console.log('Linked emails:', emails)
    
    // Auto-fill recipients from linked email senders (unique)
    if (emails && emails.length > 0) {
      const uniqueRecipients = [...new Set(emails.map(e => e.email_from).filter(Boolean))]
      progressRecipients.value = uniqueRecipients.join(', ')
    } else {
      progressRecipients.value = ''
    }
    
    // Load preview and history in parallel
    isDebugEnabled() && console.log('Fetching progress preview for board ID:', boardsStore.currentBoard.id)
    const [preview, history] = await Promise.all([
      boardsStore.generateProgressReportPreview(boardsStore.currentBoard.id),
      boardsStore.getProgressReportHistory(boardsStore.currentBoard.id)
    ])
    
    isDebugEnabled() && console.log('Progress preview received:', preview ? 'HTML (' + preview.length + ' chars)' : 'null/empty')
    progressPreview.value = preview
    progressHistory.value = history || []
  } catch (e) {
    console.error('Failed to load progress report:', e)
    toast.error('Failed to load progress report')
  } finally {
    progressLoading.value = false
  }
}

async function sendProgressReport() {
  if (!progressRecipients.value || !boardsStore.currentBoard) return
  
  progressSending.value = true
  try {
    const success = await boardsStore.sendProgressReport(
      boardsStore.currentBoard.id,
      progressRecipients.value,
      progressSubject.value
    )
    
    if (success) {
      toast.success('Progress report sent!')
      showProgressPanel.value = false
      // Reload preview for next time
      progressPreview.value = await boardsStore.generateProgressReportPreview(boardsStore.currentBoard.id)
    } else {
      toast.error('Failed to send progress report')
    }
  } catch (e) {
    console.error('Failed to send progress report:', e)
    toast.error('Failed to send progress report')
  } finally {
    progressSending.value = false
  }
}

function goToEmailNav() {
  router.push('/inbox')
}

async function handleSignOut() {
  showAccountDropdown.value = false
  await authStore.logout()
  router.push('/login')
}

function goToCalendar() {
  router.push('/calendar')
}

function goToDrive() {
  router.push('/drive')
}

function goToClients() {
  router.push('/clients')
}

function goToSettings() {
  router.push('/settings')
}

function getBoardInitials(name) {
  if (!name) return ''
  const words = name.trim().split(/\s+/)
  if (words.length >= 2) {
    return words[0][0] + words[1][0]
  }
  return name.substring(0, 2)
}

// Board context menu
function showBoardContext(e, board) {
  e.preventDefault()
  boardContextMenu.value = {
    show: true,
    x: e.clientX,
    y: e.clientY,
    board
  }
}

function closeBoardContext() {
  boardContextMenu.value.show = false
}

async function changeBoardColor(color) {
  if (!boardContextMenu.value.board) return
  await boardsStore.updateBoard(boardContextMenu.value.board.id, { background_color: color })
  closeBoardContext()
}

function openBoardSettings(board) {
  router.push(`/boards/${board.id}`)
  setTimeout(() => {
    activePanel.value = 'settings'
  }, 500)
  closeBoardContext()
}

// Background context menu
function showBgContext(e) {
  // Only show if clicking on the background, not on cards/lists
  if (e.target.closest('.board-list') || e.target.closest('.board-card')) return
  e.preventDefault()
  
  // Load current settings
  const board = boardsStore.currentBoard
  if (board) {
    bgBlur.value = board.background_blur || 0
    bgOverlayColor.value = board.background_overlay_color || '#000000'
    bgOverlayOpacity.value = board.background_overlay_opacity || 0
  }
  
  bgContextMenu.value = {
    show: true,
    x: e.clientX,
    y: e.clientY
  }
}

function closeBgContext() {
  bgContextMenu.value.show = false
}

async function changeBgColor(color) {
  if (!boardsStore.currentBoard) return
  await boardsStore.updateBoard(boardsStore.currentBoard.id, { background_color: color })
  closeBgContext()
}

async function updateBgBlur() {
  if (!boardsStore.currentBoard) return
  await boardsStore.updateBoard(boardsStore.currentBoard.id, { background_blur: bgBlur.value })
}

async function updateBgOverlay() {
  if (!boardsStore.currentBoard) return
  await boardsStore.updateBoard(boardsStore.currentBoard.id, { 
    background_overlay_color: bgOverlayColor.value,
    background_overlay_opacity: bgOverlayOpacity.value
  })
}

function openBgImageUpload() {
  document.getElementById('board-bg-upload-main')?.click()
  closeBgContext()
}

async function handleBgImageUpload(e) {
  const file = e.target.files?.[0]
  if (!file || !boardsStore.currentBoard) return
  
  if (!file.type.startsWith('image/')) {
    toast.error('Please select an image file')
    return
  }
  
  if (file.size > 5 * 1024 * 1024) {
    toast.error('Image must be less than 5MB')
    return
  }
  
  try {
    // Get or create board folder: Boards / [Board Name]
    const folderResponse = await api.post('/drive/board-folder', {
      board_name: boardsStore.currentBoard.name
    })
    
    if (!folderResponse.data.success) {
      toast.error('Failed to create board folder')
      return
    }
    
    const folderId = folderResponse.data.data.folder.id
    
    const formData = new FormData()
    formData.append('file', file)
    formData.append('folder_id', folderId)
    
    const response = await api.post('/drive/upload', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    const data = response.data
    
    if (data.success) {
      const fileId = data.data.file.id
      const shareResponse = await api.post(`/drive/files/${fileId}/share`)
      const shareData = shareResponse.data
      
      if (shareData.success) {
        await boardsStore.updateBoard(boardsStore.currentBoard.id, { 
          background_image: shareData.data.url 
        })
        toast.success('Background updated')
      }
    } else {
      toast.error('Failed to upload image')
    }
  } catch (err) {
    console.error('Background upload error:', err)
    toast.error('Failed to upload image')
  }
  
  e.target.value = ''
}

async function removeBgImage() {
  if (!boardsStore.currentBoard) return
  await boardsStore.updateBoard(boardsStore.currentBoard.id, { background_image: null })
  closeBgContext()
  toast.success('Background image removed')
}

async function archiveBoard(board) {
  if (await boardsStore.archiveBoard(board.id)) {
    toast.success('Board archived')
    if (currentBoardId.value === board.id) {
      goToBoards()
    }
  }
}

async function handleCloseBoard(board) {
  if (await boardsStore.closeBoard(board.id)) {
    toast.success('Board closed')
  }
}

async function handleReopenBoard(board) {
  if (await boardsStore.reopenBoard(board.id)) {
    toast.success('Board reopened')
  }
}

function openCard(card) {
  selectedCard.value = card
  if (card?.id && route.params.id) {
    router.replace({ path: route.path, query: { card: card.id } })
  }
}

function closeCardModal() {
  selectedCard.value = null
  if (route.query.card) {
    router.replace({ path: route.path, query: {} })
  }
}

onMounted(async () => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
  
  await boardsStore.fetchBoards()
  boardsStore.initViewMode()
  
  if (route.params.id) {
    showBoardList.value = false
    await boardsStore.fetchBoard(parseInt(route.params.id))

    if (projectHubEnabled.value && projectHubStoreRef.value) {
      projectHubStoreRef.value.selectBoard(parseInt(route.params.id))
    }
    
    await syncSelectedCardFromRoute()
  } else if (route.params.folderId && projectHubEnabled.value && projectHubStoreRef.value) {
    showBoardList.value = false
    const hub = projectHubStoreRef.value
    if (!hub.hierarchyLoaded) await hub.fetchHierarchy()
    const fId = parseInt(route.params.folderId)
    const folder = hub.findFolderById(fId)
    if (folder) {
      const space = hub.findSpaceByFolderId(fId)
      hub.selectFolder(folder, space)
    }
  } else if (projectHubEnabled.value && projectHubStoreRef.value) {
    const hub = projectHubStoreRef.value
    if (!hub.hierarchyLoaded) await hub.fetchHierarchy()
    if (hub.activeView === 'my-work' || !hub.activeView) {
      hub.selectMyWork()
    }
  }
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})
</script>

<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <!-- Header -->
    <AppHeader
      current-view="boards"
      :icon="isHubSpace ? 'folder_special' : (isHubFolder ? 'folder' : (showBoardList ? 'dashboard' : 'arrow_back'))"
      :title="isHubSpace ? (hubStore?.activeSpace?.name || 'Space') : (isHubFolder ? (hubStore?.activeFolder?.name || 'Folder') : (showBoardList ? 'Boards' : (boardsStore.currentBoard?.name || 'Board')))"
      :show-mobile-menu="isMobile && !showBoardList && !!boardsStore.currentBoard"
      :avatar-url="!showBoardList && !isHubFolder && boardsStore.currentBoard?.background_image ? boardsStore.currentBoard.background_image : ''"
      :avatar-color="!showBoardList && !isHubFolder && !boardsStore.currentBoard?.background_image && boardsStore.currentBoard?.background_color ? boardsStore.currentBoard.background_color : ''"
      :avatar-text="isHubFolder ? '' : (!showBoardList && boardsStore.currentBoard ? (boardsStore.currentBoard.name?.substring(0, 2) || '') : '')"
      @toggle-sidebar="toggleSidebar"
      @icon-click="goToBoards"
    >
      <template #title-badge>
        <ViewInfoButton :view-key="currentViewInfoKey" />
        <HowItWorksButton @click="showStepGuide = true" />
        <HowItWorksButton variant="features" :active="showBoardsGuide" @click="showBoardsGuide = !showBoardsGuide" />
      </template>
    </AppHeader>
    
    <!-- Mobile: Project Hub navigation header -->
    <div v-if="isMobile && projectHubEnabled" class="flex items-center justify-between px-4 py-2.5 bg-white dark:bg-[rgb(var(--color-surface))] border-b border-surface-200 dark:border-[rgb(var(--color-border))] flex-shrink-0">
      <button
        @click="mobileHubNavOpen = true"
        class="flex items-center gap-2 text-sm font-medium text-surface-900 dark:text-surface-100"
      >
        <span class="material-symbols-rounded text-lg text-primary-500">hub</span>
        {{ mobileHubLabel }}
        <span class="material-symbols-rounded text-lg text-surface-400">expand_more</span>
      </button>
    </div>

    <!-- Mobile: current view indicator + sheet trigger (non-hub mode) -->
    <div v-else-if="isMobile && !showBoardList && boardsStore.currentBoard" class="flex items-center justify-between px-4 py-2.5 bg-white dark:bg-[rgb(var(--color-surface))] border-b border-surface-200 dark:border-[rgb(var(--color-border))] flex-shrink-0">
      <button
        @click="sidebarOpen = true"
        class="flex items-center gap-2 text-sm font-medium text-surface-900 dark:text-surface-100"
      >
        <span class="material-symbols-rounded text-lg text-primary-500">{{ mobileCurrentIcon }}</span>
        {{ mobileCurrentLabel }}
        <span class="material-symbols-rounded text-lg text-surface-400">expand_more</span>
      </button>
    </div>

    <!-- Main content -->
    <main class="flex-1 relative bg-surface-100 dark:bg-surface-900 flex" :class="isMobile ? 'min-h-0' : 'overflow-hidden'">
      <!-- Project Hub Sidebar (replaces board sidebar when addon is on, desktop only) -->
      <ProjectHubSidebar
        v-if="!isMobile && projectHubEnabled"
        @select-board="handleProjectHubBoardSelect"
        @select-folder="handleProjectHubFolderSelect"
        @select-my-work="handleProjectHubMyWork"
      />

      <!-- Desktop Board Sidebar (only when viewing a board, Project Hub OFF) -->
      <BoardSidebar 
        v-else-if="!isMobile && !showBoardList && boardsStore.currentBoard"
        @panel-change="handlePanelChange"
        @open-progress="openProgressPanel"
      />
      
      <!-- Project Hub: My Work Panel -->
      <MyWorkPanel
        v-if="isHubMyWork"
        @open-card="handleOpenCardFromHub"
      />

      <!-- Project Hub: Space Overview -->
      <SpaceOverviewView
        v-else-if="isHubSpace && hubSpaceId"
        :space-id="hubSpaceId"
        @select-folder="(folder) => hubStore?.selectFolder(folder, hubStore.activeSpace)"
        @open-card="handleOpenCardFromHub"
      />

      <!-- Project Hub: Folder View (header + task list OR inline card detail) -->
      <div v-else-if="isHubFolder" class="flex-1 flex flex-col overflow-hidden">
        <!-- Inline card detail (replaces folder content when a card is open) -->
        <template v-if="selectedCard">
          <div class="flex items-center gap-2 px-4 py-2 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 shrink-0">
            <button
              @click="isSubtaskView ? goBackToParentCard() : closeCardModal()"
              class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors"
              :title="isSubtaskView ? 'Back to original card' : 'Back to folder'"
            >
              <span class="material-symbols-rounded text-xl text-surface-600 dark:text-surface-300">arrow_back</span>
            </button>
            <span class="text-sm text-surface-500">{{ isSubtaskView ? 'Back to original card' : `Back to ${hubStore?.activeFolder?.name || 'folder'}` }}</span>
          </div>
          <div class="flex-1 overflow-hidden">
            <CardModal
              :card="selectedCard"
              :inline-mode="true"
              @close="closeCardModal"
            />
          </div>
        </template>
        <!-- Normal folder view -->
        <template v-else>
          <FolderHeader />
          <FolderTaskView
            @open-card="handleOpenCardFromHub"
            @select-board="handleProjectHubBoardSelect"
          />
        </template>
      </div>

      <!-- Project Hub: Board View (embedded with back nav) -->
      <div v-else-if="isHubBoard" class="flex-1 flex flex-col overflow-hidden">
        <!-- Inline card detail (replaces board when a card is open) -->
        <template v-if="selectedCard">
          <div class="flex items-center gap-2 px-4 py-2 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 shrink-0">
            <button
              @click="isSubtaskView ? goBackToParentCard() : closeCardModal()"
              class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors"
              :title="isSubtaskView ? 'Back to original card' : 'Back to board'"
            >
              <span class="material-symbols-rounded text-xl text-surface-600 dark:text-surface-300">arrow_back</span>
            </button>
            <span class="text-sm text-surface-500">{{ isSubtaskView ? 'Back to original card' : `Back to ${boardsStore.currentBoard?.name || 'board'}` }}</span>
          </div>
          <div class="flex-1 overflow-hidden">
            <CardModal
              :card="selectedCard"
              :inline-mode="true"
              @close="closeCardModal"
            />
          </div>
        </template>
        <!-- Normal board view -->
        <template v-else>
          <div class="flex items-center gap-3 px-4 py-2.5 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 shrink-0">
            <button
              class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors"
              @click="goBackFromBoard()"
              title="Back to My Work"
            >
              <span class="material-symbols-rounded text-surface-500">arrow_back</span>
            </button>
            <span class="text-sm font-semibold text-surface-700 dark:text-surface-200">
              {{ boardsStore.currentBoard?.name || 'Board' }}
            </span>
            <span v-if="boardsStore.currentBoard?.client_name" class="text-xs text-surface-400 px-2 py-0.5 bg-surface-100 dark:bg-surface-700 rounded-full">
              {{ boardsStore.currentBoard.client_name }}
            </span>
            <div class="ml-auto flex items-center gap-1">
              <button
                @click="activePanel = activePanel === 'watch_folders' ? null : 'watch_folders'"
                class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium transition-colors"
                :class="activePanel === 'watch_folders'
                  ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
                  : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'"
                title="Watch Folders"
              >
                <span class="material-symbols-rounded text-sm">folder_eye</span>
                Watch Folders
              </button>
              <router-link
                :to="`/workload?mode=task-time&board_id=${boardsStore.currentBoard?.id}`"
                class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-500/10 transition-colors"
              >
                <span class="material-symbols-rounded text-sm">schedule</span>
                Time
              </router-link>
            </div>
          </div>
          <div v-if="activePanel === 'watch_folders'" class="flex-1 min-h-0 overflow-hidden relative">
            <WatchFolderManager
              :board-id="boardsStore.currentBoard?.id"
              :client-id="boardsStore.currentBoard?.client_id ? Number(boardsStore.currentBoard.client_id) : null"
              class="absolute inset-0"
            />
          </div>
          <div v-else class="flex-1 min-h-0 overflow-hidden relative" @contextmenu="showBgContext">
            <BoardCanvas @open-card="openCard" class="w-full h-full" />
          </div>
        </template>
      </div>

      <!-- Hub board loading state (store still loading) -->
      <div v-else-if="projectHubEnabled && boardsStore.currentBoard" class="flex-1 flex flex-col overflow-hidden">
        <template v-if="selectedCard">
          <div class="flex items-center gap-2 px-4 py-2 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 shrink-0">
            <button
              @click="isSubtaskView ? goBackToParentCard() : closeCardModal()"
              class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors"
              :title="isSubtaskView ? 'Back to original card' : 'Back to board'"
            >
              <span class="material-symbols-rounded text-xl text-surface-600 dark:text-surface-300">arrow_back</span>
            </button>
            <span class="text-sm text-surface-500">{{ isSubtaskView ? 'Back to original card' : `Back to ${boardsStore.currentBoard?.name || 'board'}` }}</span>
          </div>
          <div class="flex-1 overflow-hidden">
            <CardModal
              :card="selectedCard"
              :inline-mode="true"
              @close="closeCardModal"
            />
          </div>
        </template>
        <template v-else>
          <div class="flex items-center gap-3 px-4 py-2.5 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 shrink-0">
            <button
              class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors"
              @click="goBackFromBoard()"
              title="Back to My Work"
            >
              <span class="material-symbols-rounded text-surface-500">arrow_back</span>
            </button>
            <span class="text-sm font-semibold text-surface-700 dark:text-surface-200">
              {{ boardsStore.currentBoard?.name || 'Board' }}
            </span>
            <div class="ml-auto flex items-center gap-1">
              <button
                @click="activePanel = activePanel === 'watch_folders' ? null : 'watch_folders'"
                class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium transition-colors"
                :class="activePanel === 'watch_folders'
                  ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
                  : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'"
                title="Watch Folders"
              >
                <span class="material-symbols-rounded text-sm">folder_eye</span>
                Watch Folders
              </button>
            </div>
          </div>
          <div v-if="activePanel === 'watch_folders'" class="flex-1 min-h-0 overflow-hidden relative">
            <WatchFolderManager
              :board-id="boardsStore.currentBoard?.id"
              :client-id="boardsStore.currentBoard?.client_id ? Number(boardsStore.currentBoard.client_id) : null"
              class="absolute inset-0"
            />
          </div>
          <div v-else class="flex-1 min-h-0 overflow-hidden relative" @contextmenu="showBgContext">
            <BoardCanvas @open-card="openCard" class="w-full h-full" />
          </div>
        </template>
      </div>

      <!-- Board list (when Project Hub is OFF) -->
      <div v-else-if="!projectHubEnabled && showBoardList" class="absolute inset-0 overflow-y-auto p-4 md:p-6">
        <!-- Search and Filters Bar -->
        <div class="mb-6 space-y-4">
          <!-- Search + New Board row -->
          <div class="flex items-center gap-3">
            <div class="relative flex-1">
              <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-surface-400">search</span>
              <input
                v-model="searchQuery"
                type="text"
                placeholder="Search boards by name, description, or client..."
                class="w-full pl-12 pr-4 py-3 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl text-surface-900 dark:text-surface-100 placeholder:text-surface-400 focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 outline-none text-sm"
              />
            <div v-if="searchQuery" class="absolute right-3 top-1/2 -translate-y-1/2">
              <button 
                @click="searchQuery = ''"
                class="p-1 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
              >
                <span class="material-symbols-rounded text-surface-400 hover:text-surface-600">close</span>
              </button>
            </div>
            </div>
            <button 
              @click="showTrelloImport = true"
              class="h-[46px] px-4 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300 rounded-xl text-sm font-medium flex items-center gap-1.5 transition-colors flex-shrink-0"
              title="Import from Trello"
            >
              <span class="material-symbols-rounded text-base">download</span>
              <span class="hidden md:inline">Import</span>
            </button>
            <button 
              @click="showCreateModal = true"
              class="h-[46px] px-5 bg-primary-500 hover:bg-primary-600 text-white rounded-xl text-sm font-medium flex items-center gap-1.5 transition-colors flex-shrink-0"
            >
              <span class="material-symbols-rounded text-base">add</span>
              <span class="hidden md:inline">New Board</span>
            </button>
          </div>
          
          <!-- Filter Row -->
          <div class="flex items-center gap-3 overflow-x-auto pb-2 md:pb-0 md:flex-wrap">
            <!-- Card Count Filter -->
            <div class="flex items-center gap-2 flex-shrink-0">
              <span class="text-xs text-surface-500 font-medium">Cards:</span>
              <div class="flex rounded-lg border border-surface-200 dark:border-surface-700 overflow-hidden">
                <button 
                  v-for="opt in [
                    { value: 'all', label: 'All' },
                    { value: 'empty', label: 'Empty' },
                    { value: '1-5', label: '1-5' },
                    { value: '5-10', label: '5-10' },
                    { value: '10+', label: '10+' }
                  ]"
                  :key="opt.value"
                  @click="activeFilters.cardCount = opt.value"
                  :class="[
                    'px-2.5 py-1 text-xs font-medium transition-colors',
                    activeFilters.cardCount === opt.value 
                      ? 'bg-primary-500 text-white' 
                      : 'bg-white dark:bg-surface-800 text-surface-600 dark:text-surface-400 hover:bg-surface-50 dark:hover:bg-surface-700'
                  ]"
                >
                  {{ opt.label }}
                </button>
              </div>
            </div>
            
            <!-- Recent Activity Filter -->
            <div class="flex items-center gap-2 flex-shrink-0">
              <span class="text-xs text-surface-500 font-medium">Updated:</span>
              <div class="flex rounded-lg border border-surface-200 dark:border-surface-700 overflow-hidden">
                <button 
                  v-for="opt in [
                    { value: 'all', label: 'Any time' },
                    { value: 'today', label: 'Today' },
                    { value: 'week', label: 'This week' },
                    { value: 'month', label: 'This month' }
                  ]"
                  :key="opt.value"
                  @click="activeFilters.recentActivity = opt.value"
                  :class="[
                    'px-2.5 py-1 text-xs font-medium transition-colors',
                    activeFilters.recentActivity === opt.value 
                      ? 'bg-primary-500 text-white' 
                      : 'bg-white dark:bg-surface-800 text-surface-600 dark:text-surface-400 hover:bg-surface-50 dark:hover:bg-surface-700'
                  ]"
                >
                  {{ opt.label }}
                </button>
              </div>
            </div>
            
            <!-- Visibility Filter -->
            <div class="flex items-center gap-2 flex-shrink-0">
              <span class="text-xs text-surface-500 font-medium">Type:</span>
              <div class="flex rounded-lg border border-surface-200 dark:border-surface-700 overflow-hidden">
                <button 
                  v-for="opt in [
                    { value: 'all', label: 'All' },
                    { value: 'private', label: 'Private' },
                    { value: 'shared', label: 'Shared' },
                    { value: 'shared_with_me', label: 'With me' }
                  ]"
                  :key="opt.value"
                  @click="activeFilters.visibility = opt.value"
                  :class="[
                    'px-2.5 py-1 text-xs font-medium transition-colors',
                    activeFilters.visibility === opt.value 
                      ? 'bg-primary-500 text-white' 
                      : 'bg-white dark:bg-surface-800 text-surface-600 dark:text-surface-400 hover:bg-surface-50 dark:hover:bg-surface-700'
                  ]"
                >
                  {{ opt.label }}
                </button>
              </div>
            </div>
            
            <!-- Sort By -->
            <div class="flex items-center gap-2 ml-auto flex-shrink-0">
              <span class="text-xs text-surface-500 font-medium">Sort:</span>
              <select 
                v-model="activeFilters.sortBy"
                class="px-3 py-1.5 text-xs bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg text-surface-700 dark:text-surface-300 focus:border-primary-500 outline-none"
              >
                <option value="recent">Recently updated</option>
                <option value="name">Name A-Z</option>
                <option value="cards">Most cards</option>
              </select>
            </div>
            
            <!-- View toggle -->
            <div class="flex rounded-lg border border-surface-200 dark:border-surface-700 overflow-hidden flex-shrink-0">
              <button 
                @click="boardsListView = 'grid'"
                :class="[
                  'p-1.5 transition-colors',
                  boardsListView === 'grid' 
                    ? 'bg-primary-500 text-white' 
                    : 'bg-white dark:bg-surface-800 text-surface-500 hover:bg-surface-50 dark:hover:bg-surface-700'
                ]"
                title="Grid view"
              >
                <span class="material-symbols-rounded text-sm">grid_view</span>
              </button>
              <button 
                @click="boardsListView = 'table'"
                :class="[
                  'p-1.5 transition-colors',
                  boardsListView === 'table' 
                    ? 'bg-primary-500 text-white' 
                    : 'bg-white dark:bg-surface-800 text-surface-500 hover:bg-surface-50 dark:hover:bg-surface-700'
                ]"
                title="Table view"
              >
                <span class="material-symbols-rounded text-sm">view_list</span>
              </button>
            </div>
            
            <!-- Clear filters -->
            <button 
              v-if="hasActiveFilters"
              @click="clearFilters"
              class="px-3 py-1.5 text-xs text-primary-500 hover:text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-500/10 rounded-lg transition-colors flex items-center gap-1"
            >
              <span class="material-symbols-rounded text-sm">filter_alt_off</span>
              Clear filters
            </button>
          </div>
          
          <!-- Results count -->
          <div class="flex items-center justify-between text-sm text-surface-500">
            <span>
              {{ filteredBoards.length }} board{{ filteredBoards.length !== 1 ? 's' : '' }}
              <span v-if="hasActiveFilters"> (filtered)</span>
            </span>
          </div>
        </div>

        
        
        <!-- Loading -->
        <div v-if="boardsStore.loading" class="flex justify-center py-12">
          <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
        </div>
        
        <!-- Empty state -->
        <div v-else-if="filteredBoards.length === 0" class="text-center py-16">
          <div class="w-20 h-20 mx-auto mb-4 bg-surface-100 dark:bg-surface-800 rounded-2xl flex items-center justify-center">
            <span class="material-symbols-rounded text-4xl text-surface-400">dashboard</span>
          </div>
          <h3 class="text-lg font-medium text-surface-900 dark:text-surface-100 mb-2">
            {{ searchQuery ? 'No boards found' : 'No boards yet' }}
          </h3>
          <p class="text-surface-500 mb-6">
            {{ searchQuery ? 'Try a different search term' : 'Create your first board to get started' }}
          </p>
          <button 
            v-if="!searchQuery"
            @click="showCreateModal = true"
            class="px-6 py-2.5 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-sm font-medium inline-flex items-center gap-2 transition-colors"
          >
            <span class="material-symbols-rounded">add</span>
            Create Board
          </button>
        </div>
        
        <!-- Boards grid -->
        <div v-else-if="boardsListView === 'grid'" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
          <div
            v-for="board in filteredBoards"
            :key="board.id"
            @click="openBoard(board)"
            @contextmenu="showBoardContext($event, board)"
            class="group relative bg-white dark:bg-surface-800 rounded-xl overflow-hidden border border-surface-200 dark:border-surface-700 hover:border-primary-500 dark:hover:border-primary-500 transition-all cursor-pointer shadow-sm hover:shadow-md"
          >
            <!-- Color strip / background image -->
            <div 
              class="h-20 relative"
              :style="board.background_image 
                ? { backgroundImage: `url(${board.background_image})`, backgroundSize: 'cover', backgroundPosition: 'center' }
                : { backgroundColor: board.background_color || '#1e1e26' }"
            >
              <div class="absolute inset-0 bg-gradient-to-t from-black/30 to-transparent"></div>
              <!-- Visibility badge -->
              <div class="absolute top-2 left-2 z-10">
                <span
                  v-if="getBoardVisibility(board) === 'private'"
                  class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-black/50 text-white/90 text-[10px] font-medium backdrop-blur-sm"
                  title="Private - only you"
                >
                  <span class="material-symbols-rounded text-[11px]">lock</span>
                  Private
                </span>
                <span
                  v-else-if="getBoardVisibility(board) === 'shared'"
                  class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-primary-500/80 text-white text-[10px] font-medium backdrop-blur-sm"
                  title="Shared by you"
                >
                  <span class="material-symbols-rounded text-[11px]">group</span>
                  Shared
                </span>
                <span
                  v-else
                  class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-violet-500/80 text-white text-[10px] font-medium backdrop-blur-sm"
                  :title="'Shared by ' + board.owner_email"
                >
                  <span class="material-symbols-rounded text-[11px]">person_add</span>
                  by {{ board.owner_email.split('@')[0] }}
                </span>
              </div>
            </div>
            
            <!-- Content -->
            <div class="p-3">
              <h3 class="font-medium text-sm text-surface-900 dark:text-surface-100 mb-1 line-clamp-1 flex items-center gap-1.5">
                {{ board.name }}
                <span v-if="board.is_closed" class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400 text-[10px] font-medium">
                  <span class="material-symbols-rounded text-[10px]">lock</span>
                  Closed
                </span>
                <BoardScopeCreepBadge v-if="boardProEnabled" :board-id="board.id" />
              </h3>
              
              <!-- Stats -->
              <div class="flex items-center gap-3 text-xs text-surface-500">
                <span class="flex items-center gap-1" :title="`${board.card_count || 0} cards`">
                  <span class="material-symbols-rounded text-sm">credit_card</span>
                  {{ board.card_count || 0 }} cards
                </span>
                <span v-if="board.email_count" class="flex items-center gap-1" :title="`${board.email_count} linked emails`">
                  <span class="material-symbols-rounded text-sm">attach_email</span>
                  {{ board.email_count }}
                </span>
              </div>
              
              <!-- Last updated -->
              <div class="text-[10px] text-surface-400 mt-1.5">
                Updated {{ formatRelativeTime(board.updated_at) }}
              </div>
            </div>
            
            <!-- Actions overlay -->
            <div class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity flex gap-1">
              <button 
                @click.stop="openBoardSettings(board)"
                class="p-1.5 bg-black/40 hover:bg-black/60 rounded-lg text-white transition-colors"
                title="Settings"
              >
                <span class="material-symbols-rounded text-sm">settings</span>
              </button>
              <button 
                @click.stop="archiveBoard(board)"
                class="p-1.5 bg-black/40 hover:bg-black/60 rounded-lg text-white transition-colors"
                title="Archive"
              >
                <span class="material-symbols-rounded text-sm">archive</span>
              </button>
            </div>
          </div>
        </div>
        
        <!-- Boards table -->
        <div v-else class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800/50">
                  <th 
                    v-for="col in [
                      { key: 'name', label: 'Board', mobile: true },
                      { key: 'card_count', label: 'Cards', mobile: true },
                      { key: 'completed_count', label: 'Done' },
                      { key: 'overdue_count', label: 'Overdue' },
                      { key: 'list_count', label: 'Lists' },
                      { key: 'client_name', label: 'Client' },
                      { key: 'total_revenue', label: 'Revenue', mobile: true },
                      { key: 'rules_total', label: 'Rules' },
                      { key: 'visibility', label: 'Type', mobile: true },
                      { key: 'updated_at', label: 'Last Updated', mobile: true },
                      { key: 'actions', label: '' }
                    ]"
                    :key="col.key"
                    @click="sortTableBy(col.key)"
                    :class="[
                      'px-3 md:px-4 py-3 text-left text-xs font-semibold text-surface-600 dark:text-surface-400 uppercase tracking-wide transition-colors select-none whitespace-nowrap',
                      col.key !== 'actions' ? 'cursor-pointer hover:bg-surface-100 dark:hover:bg-surface-700' : '',
                      !col.mobile ? 'hide-on-mobile' : ''
                    ]"
                  >
                    <span class="flex items-center gap-1">
                      {{ col.label }}
                      <span 
                        v-if="tableSortColumn === col.key" 
                        class="material-symbols-rounded text-xs text-primary-500"
                      >
                        {{ tableSortDirection === 'asc' ? 'arrow_upward' : 'arrow_downward' }}
                      </span>
                    </span>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr 
                  v-for="board in tableSortedBoards" 
                  :key="board.id"
                  @click="openBoard(board)"
                  @contextmenu="showBoardContext($event, board)"
                  class="border-b border-surface-100 dark:border-surface-700/50 hover:bg-surface-50 dark:hover:bg-surface-800/50 cursor-pointer transition-colors group"
                >
                  <!-- Board name with color swatch -->
                  <td class="px-3 md:px-4 py-3">
                    <div class="flex items-center gap-3">
                      <div 
                        class="w-8 h-8 rounded-lg flex-shrink-0 shadow-sm"
                        :style="board.background_image 
                          ? { backgroundImage: `url(${board.background_image})`, backgroundSize: 'cover', backgroundPosition: 'center' }
                          : { backgroundColor: board.background_color || '#1e1e26' }"
                      ></div>
                      <div class="min-w-0">
                        <span class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-1.5">
                          {{ board.name }}
                          <span v-if="board.is_closed" class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400 text-[10px] font-medium">
                            <span class="material-symbols-rounded text-[10px]">lock</span>
                            Closed
                          </span>
                          <BoardScopeCreepBadge v-if="boardProEnabled" :board-id="board.id" />
                        </span>
                        <p v-if="board.client_name" class="text-xs text-surface-400 mt-0.5">{{ board.client_name }}</p>
                        <p v-else-if="board.description" class="text-xs text-surface-400 truncate max-w-xs mt-0.5">{{ board.description }}</p>
                      </div>
                    </div>
                  </td>
                  
                  <!-- Cards (open / total) -->
                  <td class="px-3 md:px-4 py-3 text-surface-600 dark:text-surface-400">
                    <span class="flex items-center gap-1">
                      <span class="material-symbols-rounded text-sm text-surface-400">credit_card</span>
                      {{ board.card_count || 0 }}
                    </span>
                  </td>
                  
                  <!-- Completed -->
                  <td class="px-4 py-3 hide-on-mobile">
                    <span v-if="board.completed_count > 0" class="flex items-center gap-1 text-green-600 dark:text-green-400">
                      <span class="material-symbols-rounded text-sm">check_circle</span>
                      {{ board.completed_count }}
                    </span>
                    <span v-else class="text-surface-300 dark:text-surface-600">-</span>
                  </td>
                  
                  <!-- Overdue -->
                  <td class="px-4 py-3 hide-on-mobile">
                    <span v-if="board.overdue_count > 0" class="flex items-center gap-1 text-red-500">
                      <span class="material-symbols-rounded text-sm">schedule</span>
                      {{ board.overdue_count }}
                    </span>
                    <span v-else class="text-surface-300 dark:text-surface-600">-</span>
                  </td>
                  
                  <!-- Lists -->
                  <td class="px-4 py-3 text-surface-600 dark:text-surface-400 hide-on-mobile">
                    <span v-if="board.list_count > 0" class="flex items-center gap-1">
                      <span class="material-symbols-rounded text-sm text-surface-400">view_column</span>
                      {{ board.list_count }}
                    </span>
                    <span v-else class="text-surface-300 dark:text-surface-600">-</span>
                  </td>
                  
                  <!-- Client -->
                  <td class="px-4 py-3 text-surface-600 dark:text-surface-400 hide-on-mobile">
                    <span v-if="board.client_name" class="flex items-center gap-1">
                      <span class="material-symbols-rounded text-sm text-surface-400">person</span>
                      <span class="truncate max-w-[120px]">{{ board.client_name }}</span>
                    </span>
                    <span v-else class="text-surface-300 dark:text-surface-600">-</span>
                  </td>
                  
                  <!-- Revenue -->
                  <td class="px-3 md:px-4 py-3">
                    <span v-if="board.total_revenue > 0" class="flex items-center gap-1 text-green-600 dark:text-green-400 font-medium">
                      <span class="material-symbols-rounded text-sm">payments</span>
                      {{ Number(board.total_revenue).toLocaleString() }} Ft
                    </span>
                    <span v-else class="text-surface-300 dark:text-surface-600">-</span>
                  </td>
                  
                  <!-- Rules (automations + email rules) -->
                  <td class="px-4 py-3 hide-on-mobile">
                    <div v-if="(board.automation_count || 0) + (board.email_rule_count || 0) > 0" class="flex items-center gap-2">
                      <span v-if="board.automation_count > 0" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-violet-50 dark:bg-violet-500/20 text-violet-600 dark:text-violet-400 text-xs font-medium" title="Automations">
                        <span class="material-symbols-rounded text-xs">bolt</span>
                        {{ board.automation_count }}
                      </span>
                      <span v-if="board.email_rule_count > 0" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 text-xs font-medium" title="Email rules">
                        <span class="material-symbols-rounded text-xs">mail</span>
                        {{ board.email_rule_count }}
                      </span>
                    </div>
                    <span v-else class="text-surface-300 dark:text-surface-600">-</span>
                  </td>
                  
                  <!-- Visibility type -->
                  <td class="px-3 md:px-4 py-3">
                    <span
                      v-if="getBoardVisibility(board) === 'private'"
                      class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 text-xs font-medium"
                    >
                      <span class="material-symbols-rounded text-xs">lock</span>
                      Private
                    </span>
                    <span
                      v-else-if="getBoardVisibility(board) === 'shared'"
                      class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-primary-50 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 text-xs font-medium"
                    >
                      <span class="material-symbols-rounded text-xs">group</span>
                      Shared
                    </span>
                    <span
                      v-else
                      class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-violet-50 dark:bg-violet-500/20 text-violet-600 dark:text-violet-400 text-xs font-medium"
                    >
                      <span class="material-symbols-rounded text-xs">person_add</span>
                      {{ board.owner_email.split('@')[0] }}
                    </span>
                  </td>
                  
                  <!-- Last Updated -->
                  <td class="px-3 md:px-4 py-3 text-xs text-surface-500 whitespace-nowrap">
                    {{ formatRelativeTime(board.updated_at) }}
                  </td>
                  
                  <!-- Actions -->
                  <td class="px-3 md:px-4 py-3 hide-on-mobile">
                    <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                      <button 
                        @click.stop="openBoardSettings(board)"
                        class="p-1.5 rounded-lg text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                        title="Settings"
                      >
                        <span class="material-symbols-rounded text-sm">settings</span>
                      </button>
                      <button 
                        @click.stop="archiveBoard(board)"
                        class="p-1.5 rounded-lg text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors"
                        title="Archive"
                      >
                        <span class="material-symbols-rounded text-sm">archive</span>
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        
        <!-- Board context menu -->
        <Teleport to="body">
          <div 
            v-if="boardContextMenu.show"
            class="fixed inset-0 z-50"
            @click="closeBoardContext"
            @contextmenu.prevent="closeBoardContext"
          >
            <div 
              class="absolute bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-2 w-48"
              :style="{ left: boardContextMenu.x + 'px', top: boardContextMenu.y + 'px' }"
              @click.stop
            >
              <button
                @click="openBoard(boardContextMenu.board); closeBoardContext()"
                class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2"
              >
                <span class="material-symbols-rounded text-lg">open_in_new</span>
                Open Board
              </button>
              
              <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
              
              <div class="px-3 py-1.5">
                <p class="text-xs text-surface-500 mb-2">Change color</p>
                <div class="flex flex-wrap gap-1">
                  <button
                    v-for="color in boardColors"
                    :key="color"
                    @click="changeBoardColor(color)"
                    class="w-5 h-5 rounded transition-transform hover:scale-110"
                    :style="{ backgroundColor: color }"
                    :class="boardContextMenu.board?.background_color === color ? 'ring-2 ring-primary-500 ring-offset-1' : ''"
                  ></button>
                </div>
              </div>
              
              <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
              
              <button
                @click="openBoardSettings(boardContextMenu.board)"
                class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2"
              >
                <span class="material-symbols-rounded text-lg">settings</span>
                Settings
              </button>
              
              <button
                v-if="!boardContextMenu.board?.is_closed"
                @click="handleCloseBoard(boardContextMenu.board); closeBoardContext()"
                class="w-full px-3 py-2 text-left text-sm text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/20 flex items-center gap-2"
              >
                <span class="material-symbols-rounded text-lg">lock</span>
                Close Board
              </button>
              <button
                v-else
                @click="handleReopenBoard(boardContextMenu.board); closeBoardContext()"
                class="w-full px-3 py-2 text-left text-sm text-green-600 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/20 flex items-center gap-2"
              >
                <span class="material-symbols-rounded text-lg">lock_open</span>
                Reopen Board
              </button>

              <button
                @click="archiveBoard(boardContextMenu.board); closeBoardContext()"
                class="w-full px-3 py-2 text-left text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2"
              >
                <span class="material-symbols-rounded text-lg">archive</span>
                Archive
              </button>
            </div>
          </div>
        </Teleport>
      </div>
      
      <!-- Board view (non-hub path: only when Project Hub is off) -->
      <div v-else-if="boardsStore.currentBoard && !projectHubEnabled" class="flex-1 relative bg-surface-100 dark:bg-surface-900 overflow-hidden">
        <!-- Inline card detail (overlays board content) -->
        <div v-if="selectedCard" class="absolute inset-0 z-20 flex flex-col bg-white dark:bg-surface-900">
          <div class="flex items-center gap-2 px-4 py-2 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 shrink-0">
            <button
              @click="isSubtaskView ? goBackToParentCard() : closeCardModal()"
              class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors"
              :title="isSubtaskView ? 'Back to original card' : 'Back to board'"
            >
              <span class="material-symbols-rounded text-xl text-surface-600 dark:text-surface-300">arrow_back</span>
            </button>
            <span class="text-sm text-surface-500">{{ isSubtaskView ? 'Back to original card' : `Back to ${boardsStore.currentBoard?.name || 'board'}` }}</span>
          </div>
          <div class="flex-1 overflow-hidden">
            <CardModal
              :card="selectedCard"
              :inline-mode="true"
              @close="closeCardModal"
            />
          </div>
        </div>

        <!-- Loading board -->
        <div v-if="boardsStore.boardLoading" class="absolute inset-0 flex items-center justify-center">
          <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
        </div>
        
        <!-- Inline Panels (shown in content area) -->
        <BoardMapPanel 
          v-else-if="activePanel === 'map'"
          :board-id="boardsStore.currentBoard.id"
          class="absolute inset-0"
        />
        
        <SettingsPanel 
          v-else-if="activePanel === 'settings'"
          :board-id="boardsStore.currentBoard.id"
          @deleted="handleSettingsDeleted"
          class="absolute inset-0"
        />
        
        <!-- Activity panel -->
        <div 
          v-else-if="activePanel === 'activity'"
          class="absolute inset-0 bg-white dark:bg-surface-900 overflow-y-auto p-6"
        >
          <ActivityLog 
            v-if="boardsStore.currentBoard"
            :board-id="boardsStore.currentBoard.id"
          />
        </div>
        
        <!-- Board Pro panels (must be before view modes in chain) -->
        <BoardAutomationPanel 
          v-else-if="boardProEnabled && activePanel === 'bp_automation'"
          class="absolute inset-0"
        />
        <EmailRulesPanel 
          v-else-if="boardProEnabled && activePanel === 'bp_email_rules'"
          class="absolute inset-0"
        />

        <WatchFolderManager
          v-else-if="projectHubEnabled && activePanel === 'watch_folders'"
          :board-id="boardsStore.currentBoard?.id"
          :client-id="boardsStore.currentBoard?.client_id ? Number(boardsStore.currentBoard.client_id) : null"
          class="absolute inset-0"
        />
        
        <!-- Kanban view (also active in mood_split mode) -->
        <div 
          v-else-if="boardsStore.viewMode === 'board' || (boardProEnabled && boardsStore.viewMode === 'mood_split')"
          class="absolute inset-0 bg-surface-200 dark:bg-surface-900 flex"
        >
          <div
            class="flex-1 relative"
            @contextmenu="showBgContext"
          >
          <BoardCanvas @open-card="openCard" class="w-full h-full" />
          
          <!-- Background context menu -->
          <Teleport to="body">
            <div 
              v-if="bgContextMenu.show"
              class="fixed inset-0 z-50"
              @click="closeBgContext"
              @contextmenu.prevent="closeBgContext"
            >
              <div 
                class="absolute bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-2 w-72"
                :style="{ left: bgContextMenu.x + 'px', top: bgContextMenu.y + 'px' }"
                @click.stop
              >
                <p class="px-3 py-1.5 text-xs font-semibold text-surface-500 uppercase">Background Color</p>
                
                <!-- Color options -->
                <div class="px-3 py-2">
                  <div class="flex flex-wrap gap-1.5">
                    <button
                      v-for="color in boardColors"
                      :key="color"
                      @click="changeBgColor(color)"
                      class="w-6 h-6 rounded transition-transform hover:scale-110"
                      :style="{ backgroundColor: color }"
                      :class="boardsStore.currentBoard?.background_color === color ? 'ring-2 ring-primary-500 ring-offset-1' : ''"
                    ></button>
                  </div>
                </div>
                
                <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
                
                <button
                  @click="openBgImageUpload"
                  class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2"
                >
                  <span class="material-symbols-rounded text-lg">image</span>
                  Upload image
                </button>
                
                <button
                  v-if="boardsStore.currentBoard?.background_image"
                  @click="removeBgImage"
                  class="w-full px-3 py-2 text-left text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2"
                >
                  <span class="material-symbols-rounded text-lg">delete</span>
                  Remove image
                </button>
                
                <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
                
                <!-- Blur setting -->
                <div class="px-3 py-2">
                  <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-surface-500 uppercase">Blur</span>
                    <span class="text-xs text-surface-500">{{ bgBlur }}px</span>
                  </div>
                  <input
                    v-model.number="bgBlur"
                    type="range"
                    min="0"
                    max="200"
                    step="1"
                    class="w-full h-2 bg-surface-200 dark:bg-surface-700 rounded-lg appearance-none cursor-pointer accent-primary-500"
                    @change="updateBgBlur"
                  />
                </div>
                
                <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
                
                <!-- Overlay setting -->
                <div class="px-3 py-2">
                  <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-surface-500 uppercase">Overlay</span>
                    <span class="text-xs text-surface-500">{{ bgOverlayOpacity }}%</span>
                  </div>
                  
                  <!-- Overlay color picker -->
                  <div class="flex items-center gap-2 mb-2">
                    <div class="flex flex-wrap gap-1">
                      <button
                        v-for="color in overlayColors"
                        :key="'overlay-' + color"
                        @click="bgOverlayColor = color; updateBgOverlay()"
                        class="w-5 h-5 rounded transition-transform hover:scale-110"
                        :style="{ backgroundColor: color }"
                        :class="bgOverlayColor === color ? 'ring-2 ring-primary-500 ring-offset-1 dark:ring-offset-surface-800' : ''"
                      ></button>
                    </div>
                    <input
                      v-model="bgOverlayColor"
                      type="color"
                      class="w-7 h-7 rounded cursor-pointer border-0 p-0"
                      @change="updateBgOverlay"
                    />
                  </div>
                  
                  <!-- Overlay opacity -->
                  <input
                    v-model.number="bgOverlayOpacity"
                    type="range"
                    min="0"
                    max="100"
                    step="5"
                    class="w-full h-2 bg-surface-200 dark:bg-surface-700 rounded-lg appearance-none cursor-pointer accent-primary-500"
                    @change="updateBgOverlay"
                  />
                </div>
              </div>
            </div>
          </Teleport>
          
          <!-- Hidden file input for background upload -->
          <input
            id="board-bg-upload-main"
            type="file"
            accept="image/*"
            class="hidden"
            @change="handleBgImageUpload"
          />
          </div>

          <!-- Mood Board reference panel (side-by-side with kanban) -->
          <BoardMoodSplitView 
            v-if="boardProEnabled && boardsStore.viewMode === 'mood_split'"
            class="flex-shrink-0 border-l border-surface-300 dark:border-surface-700"
          />
        </div>
        
        <!-- Table view -->
        <BoardTableView 
          v-else-if="boardsStore.viewMode === 'table'"
          @open-card="openCard"
        />
        
        <!-- Calendar view -->
        <BoardCalendarView 
          v-else-if="boardsStore.viewMode === 'calendar'"
          @open-card="openCard"
        />
        
        <!-- Timeline view -->
        <BoardTimelineView 
          v-else-if="boardsStore.viewMode === 'timeline'"
          @open-card="openCard"
        />
        
        <!-- Financials view -->
        <BoardFinancialsView 
          v-else-if="boardsStore.viewMode === 'financials'"
          @open-card="openCard"
        />
        
        <!-- Board Pro views (in v-else-if chain, components read boardId from store) -->
        <BoardOverview 
          v-else-if="boardProEnabled && boardsStore.viewMode === 'board_overview'"
        />
        <BoardRevenueView 
          v-else-if="boardProEnabled && boardsStore.viewMode === 'revenue'"
        />
        <BoardScopeRadar 
          v-else-if="boardProEnabled && boardsStore.viewMode === 'scope_radar'"
        />
      </div>
    </main>
    
    <!-- Create board modal -->
    <Teleport to="body">
      <Transition name="fade">
        <div 
          v-if="showCreateModal"
          class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
          @click.self="showCreateModal = false"
        >
          <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
            <div class="p-6">
              <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100 mb-6">Create Board</h2>
              
              <div class="space-y-4">
                <div>
                  <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                    Board name
                  </label>
                  <input
                    v-model="newBoardName"
                    type="text"
                    placeholder="Enter board name..."
                    class="w-full px-4 py-2.5 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-surface-900 dark:text-surface-100 placeholder:text-surface-400 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none"
                    @keydown.enter="createBoard"
                    autofocus
                  />
                </div>
                
                <div>
                  <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
                    Background color
                  </label>
                  <div class="flex flex-wrap gap-2">
                    <button
                      v-for="color in boardColors"
                      :key="color"
                      @click="newBoardColor = color"
                      :class="[
                        'w-8 h-8 rounded-lg transition-transform',
                        newBoardColor === color ? 'ring-2 ring-offset-2 ring-primary-500 scale-110' : 'hover:scale-105'
                      ]"
                      :style="{ backgroundColor: color }"
                    ></button>
                  </div>
                </div>
              </div>
            </div>
            
            <div class="px-6 py-4 bg-surface-50 dark:bg-surface-900 flex justify-end gap-3">
              <button 
                @click="showCreateModal = false"
                class="px-4 py-2 text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-100 transition-colors"
              >
                Cancel
              </button>
              <button 
                @click="createBoard"
                :disabled="!newBoardName.trim()"
                class="px-6 py-2 bg-primary-500 hover:bg-primary-600 disabled:bg-primary-500/50 text-white rounded-full font-medium transition-colors"
              >
                Create
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
    
    <!-- Card modal removed: all card detail views are now rendered inline within their respective board sections -->
    
    <!-- Progress Report Panel -->
    <Teleport to="body">
      <Transition name="fade">
        <div 
          v-if="showProgressPanel" 
          class="fixed inset-0 bg-black/50 z-[100] flex items-center justify-center p-4"
          @mousedown.self="showProgressPanel = false"
        >
          <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-5xl max-h-[90vh] overflow-hidden flex flex-col">
            <div class="px-5 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between shrink-0">
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-primary-500">send</span>
                Send Progress Report
              </h2>
              <div class="flex items-center gap-2">
                <button 
                  @click="showProgressHistory = !showProgressHistory"
                  class="px-3 py-1.5 text-sm rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400 flex items-center gap-1.5"
                >
                  <span class="material-symbols-rounded text-lg">history</span>
                  History
                </button>
                <button @click="showProgressPanel = false" class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg">
                  <span class="material-symbols-rounded text-surface-500">close</span>
                </button>
              </div>
            </div>
            
            <div class="flex-1 overflow-y-auto p-4">
              <!-- Recipients and Subject -->
              <div class="space-y-3 mb-4">
                <div>
                  <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
                    Recipients (comma-separated emails)
                  </label>
                  <input 
                    v-model="progressRecipients"
                    type="text"
                    placeholder="client@example.com, team@example.com"
                    class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
                    Subject
                  </label>
                  <input 
                    v-model="progressSubject"
                    type="text"
                    class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                  />
                </div>
              </div>
              
              <!-- History view -->
              <div v-if="showProgressHistory" class="mb-4">
                <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-300 mb-2">Report History</h3>
                <div v-if="progressHistory.length === 0" class="text-sm text-surface-500 py-4 text-center">
                  No reports sent yet
                </div>
                <div v-else class="space-y-2 max-h-48 overflow-y-auto">
                  <div 
                    v-for="report in progressHistory" 
                    :key="report.id"
                    class="p-3 rounded-lg bg-surface-50 dark:bg-surface-700 text-sm"
                  >
                    <div class="flex items-center justify-between">
                      <span class="font-medium text-surface-900 dark:text-surface-100">{{ report.subject }}</span>
                      <span class="text-surface-500">{{ new Date(report.sent_at).toLocaleDateString() }}</span>
                    </div>
                    <p class="text-surface-500 mt-1">To: {{ report.sent_to }}</p>
                  </div>
                </div>
              </div>
              
              <!-- Preview -->
              <div class="border border-surface-200 dark:border-surface-600 rounded-xl overflow-hidden">
                <div class="px-3 py-2 bg-surface-50 dark:bg-surface-700 border-b border-surface-200 dark:border-surface-600">
                  <span class="text-xs font-semibold text-surface-500 uppercase tracking-wide">Email Preview</span>
                </div>
                <div 
                  v-if="progressPreview" 
                  class="p-0 bg-gray-100 max-h-96 overflow-y-auto"
                  v-html="progressPreview"
                ></div>
                <div v-else-if="progressLoading" class="p-8 text-center">
                  <span class="material-symbols-rounded text-3xl text-surface-300 animate-spin">progress_activity</span>
                  <p class="text-sm text-surface-500 mt-2">Loading progress report...</p>
                </div>
                <div v-else class="p-8 text-center text-surface-500">
                  <span class="material-symbols-rounded text-3xl text-surface-300">info</span>
                  <p class="text-sm mt-2">No progress data available yet.<br>Complete some tasks to generate a report!</p>
                </div>
              </div>
            </div>
            
            <div class="px-5 py-4 border-t border-surface-200 dark:border-surface-700 flex items-center justify-end gap-3 shrink-0">
              <button 
                @click="showProgressPanel = false"
                class="px-4 py-2 rounded-lg text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700"
              >
                Cancel
              </button>
              <button 
                @click="sendProgressReport"
                :disabled="!progressRecipients || progressSending"
                class="px-4 py-2 bg-primary-500 hover:bg-primary-600 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg font-medium flex items-center gap-2"
              >
                <span v-if="progressSending" class="material-symbols-rounded animate-spin">progress_activity</span>
                <span class="material-symbols-rounded" v-else>send</span>
                {{ progressSending ? 'Sending...' : 'Send Report' }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
    
    <!-- Mobile Board Sidebar Bottom Sheet -->
    <Teleport to="body">
      <Transition name="board-sheet">
        <div
          v-if="sidebarOpen && isMobile"
          class="fixed inset-0 z-[60] bg-black/40"
          @click.self="sidebarOpen = false"
        >
          <div class="absolute bottom-0 left-0 right-0 bg-white dark:bg-surface-800 rounded-t-2xl max-h-[85vh] overflow-y-auto" style="-webkit-overflow-scrolling: touch;">
            <!-- Drag handle -->
            <div class="flex justify-center pt-3 pb-1 sticky top-0 bg-white dark:bg-surface-800 z-10">
              <div class="w-10 h-1 bg-surface-300 dark:bg-surface-600 rounded-full"></div>
            </div>

            <div class="px-4 pb-6 space-y-1">
              <!-- Board header -->
              <div v-if="boardsStore.currentBoard" class="flex items-center gap-3 px-1 pb-3 mb-2 border-b border-surface-200 dark:border-surface-700">
                <div
                  class="w-9 h-9 rounded-lg flex items-center justify-center text-white text-xs font-bold uppercase flex-shrink-0 overflow-hidden"
                  :style="boardsStore.currentBoard.background_image
                    ? { backgroundImage: `url(${boardsStore.currentBoard.background_image})`, backgroundSize: 'cover', backgroundPosition: 'center' }
                    : { backgroundColor: boardsStore.currentBoard.background_color || '#1e1e26' }"
                >
                  <span v-if="!boardsStore.currentBoard.background_image">
                    {{ boardsStore.currentBoard.name?.substring(0, 2) || 'BD' }}
                  </span>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-semibold text-surface-900 dark:text-surface-100 truncate">{{ boardsStore.currentBoard.name }}</p>
                  <p class="text-xs text-surface-500">{{ boardsStore.currentBoard.card_count || 0 }} cards</p>
                </div>
              </div>

              <!-- Views -->
              <p class="text-xs font-semibold text-surface-500 uppercase tracking-wide px-1 pt-1 pb-2">Views</p>
              <button
                v-for="view in mobileSheetLayoutViews"
                :key="view.id"
                @click="handleMobileSheetView(view.id); sidebarOpen = false"
                :class="[
                  'w-full px-3 py-3 rounded-xl text-sm text-left flex items-center gap-3 transition-colors',
                  boardsStore.viewMode === view.id && !activePanel
                    ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 font-medium'
                    : 'bg-surface-50 dark:bg-surface-700/50 hover:bg-surface-100 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300'
                ]"
              >
                <span class="material-symbols-rounded text-lg">{{ view.icon }}</span>
                {{ view.name }}
                <span v-if="boardsStore.viewMode === view.id && !activePanel" class="ml-auto material-symbols-rounded text-sm text-primary-500">check</span>
              </button>

              <!-- Insights -->
              <template v-if="mobileSheetInsightViews.length > 0">
                <hr class="border-surface-200 dark:border-surface-600 my-3">
                <p class="text-xs font-semibold text-surface-500 uppercase tracking-wide px-1 pb-2">Insights</p>
                <button
                  v-for="view in mobileSheetInsightViews"
                  :key="view.id"
                  @click="handleMobileSheetView(view.id); sidebarOpen = false"
                  :class="[
                    'w-full px-3 py-3 rounded-xl text-sm text-left flex items-center gap-3 transition-colors',
                    boardsStore.viewMode === view.id && !activePanel
                      ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 font-medium'
                      : 'bg-surface-50 dark:bg-surface-700/50 hover:bg-surface-100 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300'
                  ]"
                >
                  <span class="material-symbols-rounded text-lg">{{ view.icon }}</span>
                  {{ view.name }}
                  <span v-if="boardsStore.viewMode === view.id && !activePanel" class="ml-auto material-symbols-rounded text-sm text-primary-500">check</span>
                </button>
              </template>

              <!-- Tools -->
              <hr class="border-surface-200 dark:border-surface-600 my-3">
              <p class="text-xs font-semibold text-surface-500 uppercase tracking-wide px-1 pb-2">Tools</p>
              <button
                v-for="panel in mobileSheetToolOptions"
                :key="panel.id"
                @click="handleMobileSheetPanel(panel.id); sidebarOpen = false"
                :class="[
                  'w-full px-3 py-3 rounded-xl text-sm text-left flex items-center gap-3 transition-colors',
                  activePanel === panel.id
                    ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 font-medium'
                    : 'bg-surface-50 dark:bg-surface-700/50 hover:bg-surface-100 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300'
                ]"
              >
                <span class="material-symbols-rounded text-lg">{{ panel.icon }}</span>
                {{ panel.name }}
                <span v-if="activePanel === panel.id" class="ml-auto material-symbols-rounded text-sm text-primary-500">check</span>
              </button>

              <!-- Automation (Board Pro) -->
              <template v-if="boardProEnabled">
                <hr class="border-surface-200 dark:border-surface-600 my-3">
                <p class="text-xs font-semibold text-surface-500 uppercase tracking-wide px-1 pb-2">Automation</p>
                <button
                  v-for="panel in mobileSheetAutomationOptions"
                  :key="panel.id"
                  @click="handleMobileSheetPanel(panel.id); sidebarOpen = false"
                  :class="[
                    'w-full px-3 py-3 rounded-xl text-sm text-left flex items-center gap-3 transition-colors',
                    activePanel === panel.id
                      ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 font-medium'
                      : 'bg-surface-50 dark:bg-surface-700/50 hover:bg-surface-100 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300'
                  ]"
                >
                  <span class="material-symbols-rounded text-lg">{{ panel.icon }}</span>
                  {{ panel.name }}
                  <span v-if="activePanel === panel.id" class="ml-auto material-symbols-rounded text-sm text-primary-500">check</span>
                </button>
              </template>

              <!-- Reports -->
              <hr class="border-surface-200 dark:border-surface-600 my-3">
              <p class="text-xs font-semibold text-surface-500 uppercase tracking-wide px-1 pb-2">Reports</p>
              <button
                @click="openProgressPanel(); sidebarOpen = false"
                class="w-full px-3 py-3 bg-surface-50 dark:bg-surface-700/50 hover:bg-green-50 dark:hover:bg-green-500/10 rounded-xl text-sm text-green-600 dark:text-green-400 text-left flex items-center gap-3 transition-colors"
              >
                <span class="material-symbols-rounded text-lg">send</span>
                Progress Report
              </button>

              <!-- Navigate -->
              <hr class="border-surface-200 dark:border-surface-600 my-3">
              <button
                @click="$router.push('/clients/overview'); sidebarOpen = false"
                class="w-full px-3 py-3 bg-surface-50 dark:bg-surface-700/50 hover:bg-surface-100 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3 transition-colors"
              >
                <span class="material-symbols-rounded text-lg">groups</span>
                Clients Overview
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- Mobile Project Hub Navigation Sheet -->
    <Teleport to="body">
      <Transition name="board-sheet">
        <div
          v-if="mobileHubNavOpen && isMobile && projectHubEnabled"
          class="fixed inset-0 z-[60] bg-black/40"
          @click.self="mobileHubNavOpen = false"
        >
          <div class="absolute bottom-0 left-0 right-0 bg-white dark:bg-surface-800 rounded-t-2xl max-h-[80vh] overflow-y-auto" style="-webkit-overflow-scrolling: touch;">
            <div class="flex justify-center pt-3 pb-1 sticky top-0 bg-white dark:bg-surface-800 z-10">
              <div class="w-10 h-1 bg-surface-300 dark:bg-surface-600 rounded-full"></div>
            </div>
            <div class="px-4 pb-6 space-y-1">
              <!-- My Work -->
              <button
                @click="handleProjectHubMyWork(); mobileHubNavOpen = false"
                class="w-full px-3 py-3 rounded-xl text-sm text-left flex items-center gap-3 transition-colors"
                :class="isHubMyWork
                  ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 font-medium'
                  : 'bg-surface-50 dark:bg-surface-700/50 hover:bg-surface-100 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300'"
              >
                <span class="material-symbols-rounded text-lg">task_alt</span>
                My Work
              </button>

              <!-- Workload Planner -->
              <button
                @click="router.push({ name: 'workload' }); mobileHubNavOpen = false"
                class="w-full px-3 py-3 rounded-xl text-sm text-left flex items-center gap-3 transition-colors bg-surface-50 dark:bg-surface-700/50 hover:bg-surface-100 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300"
              >
                <span class="material-symbols-rounded text-lg">monitoring</span>
                Workload Planner
              </button>

              <!-- Time -->
              <button
                @click="router.push({ path: '/workload', query: { mode: 'task-time' } }); mobileHubNavOpen = false"
                class="w-full px-3 py-3 rounded-xl text-sm text-left flex items-center gap-3 transition-colors bg-surface-50 dark:bg-surface-700/50 hover:bg-surface-100 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300"
              >
                <span class="material-symbols-rounded text-lg">schedule</span>
                Time
              </button>

              <!-- Spaces & Folders -->
              <template v-if="projectHubStoreRef">
                <hr class="border-surface-200 dark:border-surface-600 my-3">
                <p class="text-xs font-semibold text-surface-500 uppercase tracking-wide px-1 pb-2">Spaces</p>
                <template v-for="space in projectHubStoreRef.spacesWithFolders" :key="space.id">
                  <div class="px-1 py-1 text-xs font-semibold text-surface-500 flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full" :style="{ backgroundColor: space.color || '#6366f1' }"></span>
                    {{ space.name }}
                  </div>
                  <button
                    v-for="folder in space.folders"
                    :key="folder.id"
                    @click="handleProjectHubFolderSelect(folder); projectHubStoreRef.selectFolder(folder, space); mobileHubNavOpen = false"
                    class="w-full px-3 py-2.5 rounded-xl text-sm text-left flex items-center gap-3 transition-colors ml-2"
                    :class="hubActiveView === `folder:${folder.id}`
                      ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 font-medium'
                      : 'bg-surface-50 dark:bg-surface-700/50 hover:bg-surface-100 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300'"
                  >
                    <span class="material-symbols-rounded text-base">folder</span>
                    {{ folder.name }}
                    <span v-if="folder.boards?.length" class="ml-auto text-[10px] bg-surface-200 dark:bg-surface-600 px-1.5 py-0.5 rounded-full text-surface-500">
                      {{ folder.boards.length }}
                    </span>
                  </button>
                </template>

                <!-- Unsorted -->
                <template v-if="projectHubStoreRef.unsortedBoards?.length > 0">
                  <hr class="border-surface-200 dark:border-surface-600 my-3">
                  <p class="text-xs font-semibold text-surface-500 uppercase tracking-wide px-1 pb-2">Unsorted</p>
                  <button
                    v-for="board in projectHubStoreRef.unsortedBoards"
                    :key="board.id"
                    @click="handleProjectHubBoardSelect(board.id); mobileHubNavOpen = false"
                    class="w-full px-3 py-2.5 rounded-xl text-sm text-left flex items-center gap-3 transition-colors"
                    :class="hubActiveView === `unsorted-board:${board.id}`
                      ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 font-medium'
                      : 'bg-surface-50 dark:bg-surface-700/50 hover:bg-surface-100 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300'"
                  >
                    <span class="w-2 h-2 rounded" :style="{ backgroundColor: board.background_color || '#6366f1' }"></span>
                    {{ board.name }}
                  </button>
                </template>
              </template>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- Mobile Bottom Navigation -->
    <MobileBottomNav v-if="isMobile" />

    <!-- Trello Import Modal -->
    <TrelloImportModal v-if="showTrelloImport" @close="showTrelloImport = false" />

    <FeatureGuide v-model="showBoardsGuide" :tiers="boardsGuideData.tiers" :integrations="boardsGuideData.integrations" :title-key="boardsGuideData.titleKey" :footer-key="boardsGuideData.footerKey" :layer-key="boardsGuideData.layerKey" :layer-icon="boardsGuideData.layerIcon" />

    <StepGuide
      v-if="showStepGuide"
      :title-key="boardsGuide.titleKey"
      :subtitle-key="boardsGuide.subtitleKey"
      :header-icon="boardsGuide.headerIcon"
      :header-color="boardsGuide.headerColor"
      :storage-key="boardsGuide.storageKey"
      :steps="boardsGuide.steps"
      @close="showStepGuide = false"
    />
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.line-clamp-1 {
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.animate-spin {
  animation: spin 1s linear infinite;
}

/* Board sidebar bottom sheet */
.board-sheet-enter-active {
  transition: opacity 0.2s ease;
}
.board-sheet-enter-active > div:last-child {
  transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}
.board-sheet-leave-active {
  transition: opacity 0.15s ease;
}
.board-sheet-leave-active > div:last-child {
  transition: transform 0.2s ease-in;
}
.board-sheet-enter-from { opacity: 0; }
.board-sheet-enter-from > div:last-child { transform: translateY(100%); }
.board-sheet-leave-to { opacity: 0; }
.board-sheet-leave-to > div:last-child { transform: translateY(100%); }
</style>

